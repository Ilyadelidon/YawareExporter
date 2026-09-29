<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import DatePicker from 'primevue/datepicker';
import Message from 'primevue/message';
import Select from 'primevue/select';
import { useRoute, useRouter } from 'vue-router';
import client from '../api/client';
import ActivityBreakdown from '../components/ActivityBreakdown.vue';
import AiAnalysisPanel from '../components/AiAnalysisPanel.vue';
import HintBanner from '../components/HintBanner.vue';
import IntegrationsLink from '../components/IntegrationsLink.vue';
import UiIcon from '../components/UiIcon.vue';
import ReportEmptyState from '../components/reports/ReportEmptyState.vue';
import ReportSummary from '../components/reports/ReportSummary.vue';
import ReportTaskList from '../components/reports/ReportTaskList.vue';
import { useIntegrationsStatus } from '../composables/useIntegrationsStatus';
import { useReportTasks } from '../composables/useReportTasks';
import { REPORT_STATUS, TIME_KEYS, isReportActive } from '../constants/report';
import { useAuthStore } from '../stores/auth';
import { useHintsStore } from '../stores/hints';
import { useReportGenerationStore } from '../stores/reportGeneration';
import { formatLongDate, toIsoDate } from '../utils/dates';
import { saveBlob } from '../utils/download';
import { parseClock } from '../utils/duration';
import { pluralUk } from '../utils/plural';

const auth = useAuthStore();
const generation = useReportGenerationStore();
const hints = useHintsStore();
const route = useRoute();
const router = useRouter();

const employees = ref([]);
// Дату й працівника тримаємо в сторі: після переходу між сторінками звіти
// відкриваються там, де їх залишили, і звіт, що формується, лишається з лоадером.
const selectedEmployee = ref(generation.employeeId);
const ownEmployeeId = ref(null);
const selectedDate = ref(generation.date ? new Date(`${generation.date}T00:00:00`) : new Date());
const report = ref(null);
const loading = ref(true);
const generating = ref(false);
const downloading = ref(false);
const errorMessage = ref('');

// Номер останнього запиту звіту: відповідь за попередню дату чи працівника,
// що прийшла пізніше, не має підмінити звіт, який зараз вибрано.
let reportRequest = 0;

const { status: integrations, ready: integrationsOk, load: loadIntegrations } = useIntegrationsStatus();
const {
  state: taskState,
  label: trackerLabel,
  reset: resetTasks,
  applyFromReport: applyReportTasks,
} = useReportTasks(() => integrations.value.provider || auth.user?.task_provider);

const isActive = computed(() => isReportActive(report.value));
const isDone = computed(() => report.value?.status === REPORT_STATUS.completed);
const isFailed = computed(() => report.value?.status === REPORT_STATUS.failed);
// Звіт свідомо не сформовано: у дні лишився час поза тасками. Це не збій,
// тож показуємо не помилку, а що саме треба поправити в трекері.
const isBlocked = computed(() => report.value?.status === REPORT_STATUS.blocked);
// День без тасок стає звітом лише зі статистикою — файлу для завантаження немає.
// Список звітів віддає files_count, окремий звіт — масив files.
const hasFile = computed(() => (report.value?.files_count ?? report.value?.files?.length ?? 0) > 0);

// Адмін переглядає звіти працівників, але формує лише власні —
// кожен працівник формує звіт сам зі своїми інтеграціями.
const viewingOther = computed(() => auth.isAdmin && Boolean(selectedEmployee.value) && selectedEmployee.value !== ownEmployeeId.value);

const integrationsHint = computed(() => {
  if (viewingOther.value) return '';
  if (!integrations.value.loaded || integrationsOk.value) return '';
  const actions = [];
  if (!integrations.value.tracker) actions.push(`налаштуйте ${trackerLabel.value}`);
  if (!integrations.value.sheets) actions.push('налаштуйте Google Таблицю');
  return `Щоб формувати звіти, ${actions.join(' і ')} на сторінці «Інтеграції».`;
});

// День, про який нагадує підказка в меню (немає звіту чи задач у Планах).
// Якщо працівник цього дня не працював — прибирає нагадування тут, на самому дні.
// Коли за день уже є час у розборі (або звіт заблоковано через час поза
// тасками), день точно робочий — «Не працював» тоді не пропонуємо.
const isHintedDay = computed(() => {
  if (auth.isAdmin || !selectedDate.value) return false;
  if (isBlocked.value || parseClock(report.value?.summary?.[TIME_KEYS.total]) > 0) return false;
  return hints.hasDay(toIsoDate(selectedDate.value));
});

function skipSelectedDay() {
  hints.skip(toIsoDate(selectedDate.value));
}

const canGenerate = computed(() => !loading.value && !generating.value && !isActive.value && integrationsOk.value && !viewingOther.value && (!auth.isAdmin || selectedEmployee.value));

const employeeName = computed(() => {
  if (report.value?.employee?.name) return report.value.employee.name;
  if (auth.isAdmin) {
    return employees.value.find((employee) => employee.id === selectedEmployee.value)?.name || '';
  }
  return auth.user?.name || '';
});

const dateLabel = computed(() => (selectedDate.value ? formatLongDate(selectedDate.value) : ''));

const taskCountLabel = computed(() => {
  const n = taskState.items.length;
  return `${n} ${pluralUk(n, ['завдання', 'завдання', 'завдань'])}`;
});

// Що показати на порожньому екрані — див. ReportEmptyState.
const emptyVariant = computed(() => {
  if (auth.isAdmin && !selectedEmployee.value) return 'select-employee';
  if (isFailed.value) return 'failed';
  if (isBlocked.value) return 'blocked';
  if (viewingOther.value) return 'viewing-other';
  if (integrationsHint.value) return 'integrations';
  return 'not-generated';
});

// Статус звіту, що формується, политься в сторі — він не зупиняється,
// коли сторінку покидають. Тут лише віддаємо звіт під нагляд.
function trackIfActive() {
  if (isActive.value) {
    generation.track(report.value);
  }
}

function applyTasks() {
  applyReportTasks(report.value, toIsoDate(selectedDate.value));
}

watch(() => generation.report, (tracked) => {
  if (!tracked || tracked.id !== report.value?.id) return;
  report.value = tracked;
  if (isDone.value && !taskState.items.length) {
    applyTasks();
  }
});

async function loadReport() {
  const request = ++reportRequest;
  loading.value = true;
  errorMessage.value = '';
  report.value = null;
  resetTasks();

  if (auth.isAdmin && !selectedEmployee.value) {
    loading.value = false;
    return;
  }

  try {
    const isoDate = toIsoDate(selectedDate.value);
    const params = { date_from: isoDate, date_to: isoDate };
    if (auth.isAdmin) {
      params.employee_id = selectedEmployee.value;
    }
    const { data } = await client.get('/reports', { params });
    if (request !== reportRequest) return;
    report.value = data.data?.[0] || null;
  } catch (error) {
    if (request !== reportRequest) return;
    errorMessage.value = error.response?.data?.message || 'Не вдалося завантажити звіт.';
  }

  loading.value = false;
  if (isDone.value) {
    applyTasks();
  }
  trackIfActive();
}

async function loadEmployees() {
  if (!auth.isAdmin) {
    return;
  }
  const { data } = await client.get('/employees');
  employees.value = data.data;
  const own = employees.value.find((employee) => employee.user_id === auth.user?.id);
  ownEmployeeId.value = own?.id || null;
  if (!employees.value.some((employee) => employee.id === selectedEmployee.value)) {
    selectedEmployee.value = own?.id || employees.value[0]?.id || null;
  }
}

async function generateReport() {
  if (!canGenerate.value) return;

  // Той самий лічильник, що й у loadReport: якщо поки звіт ставиться в
  // чергу, вибрали іншу дату, відповідь уже не для цієї сторінки.
  const request = ++reportRequest;
  errorMessage.value = '';
  generating.value = true;
  resetTasks();

  try {
    const payload = { report_date: toIsoDate(selectedDate.value) };
    if (auth.isAdmin) {
      payload.employee_id = selectedEmployee.value;
    }
    const { data } = await client.post('/reports', payload);
    if (request === reportRequest) {
      report.value = data.data;
    }
    generation.track(data.data);
  } catch (error) {
    if (request === reportRequest) {
      errorMessage.value = error.response?.data?.message || 'Не вдалося поставити звіт у чергу.';
    }
  } finally {
    generating.value = false;
  }
}

async function downloadReport() {
  if (!report.value?.id) return;
  downloading.value = true;
  try {
    const response = await client.get(`/reports/${report.value.id}/download`, { responseType: 'blob' });
    saveBlob(response.data, `yaware-report-${report.value.report_date}.xlsx`);
  } catch {
    errorMessage.value = 'Не вдалося завантажити файл звіту.';
  } finally {
    downloading.value = false;
  }
}

// Підказка в меню веде сюди з ?date=Y-m-d: відкриваємо звіт за цей день і
// прибираємо параметр, щоб повторний перехід на ту саму дату теж спрацював.
function applyQueryDate() {
  const iso = route.query.date;
  if (typeof iso !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(iso)) return;
  selectedDate.value = new Date(`${iso}T00:00:00`);
  router.replace({ query: { ...route.query, date: undefined } });
}

watch(() => route.query.date, applyQueryDate);

onMounted(async () => {
  applyQueryDate();
  if (!auth.isAdmin) loadIntegrations();
  await loadEmployees();
  await loadReport();
  // Слухаємо вибір лише після першого завантаження — інакше підстановка
  // працівника в loadEmployees запустила б loadReport удруге.
  watch([selectedDate, selectedEmployee], () => {
    generation.date = selectedDate.value ? toIsoDate(selectedDate.value) : null;
    generation.employeeId = selectedEmployee.value;
    if (selectedDate.value) {
      loadReport();
    }
  });
});
</script>

<template>
  <div class="dashboard-page">

    <!-- Heading + controls -->
    <div class="page-head">
      <div class="page-head-info">
        <div class="page-head-icon">
          <UiIcon name="file" :size="20" color="var(--accent)" />
        </div>
        <div>
          <div class="page-head-title">Звіт за {{ dateLabel }}</div>
          <div class="page-head-subtitle">
            <template v-if="employeeName">{{ employeeName }} · активність, час та таски</template>
            <template v-else>активність, час та таски</template>
          </div>
        </div>
      </div>
      <div class="page-head-actions">
        <Select
          v-if="auth.isAdmin"
          v-model="selectedEmployee"
          :options="employees"
          option-label="name"
          option-value="id"
          placeholder="Працівник"
          filter
          class="employee-select"
        />
        <div class="field-pill">
          <UiIcon name="calendar" :size="15" color="var(--accent)" />
          <DatePicker v-model="selectedDate" date-format="dd.mm.yy" :manual-input="false" select-other-months />
        </div>
        <button
          v-if="isHintedDay"
          class="skip-btn"
          type="button"
          title="Не нагадувати про цей день у підказках"
          @click="skipSelectedDay"
        >
          Не працював
        </button>
        <button
          v-if="!viewingOther"
          class="gen-btn"
          :class="{ 'is-loading': generating || isActive, 'is-done': isDone && !generating && !isActive }"
          type="button"
          :disabled="!canGenerate"
          @click="generateReport"
        >
          <template v-if="generating || isActive">
            <UiIcon name="spinner" />
            Формування…
          </template>
          <template v-else-if="isDone">
            <UiIcon name="check" :stroke-width="2.5" />
            Сформовано · оновити
          </template>
          <template v-else>
            <UiIcon name="play" />
            Сформувати звіт
          </template>
        </button>
      </div>
    </div>

    <Message v-if="errorMessage" severity="error" :closable="true" class="page-message" @close="errorMessage = ''">
      {{ errorMessage }}
    </Message>

    <Message v-if="isFailed && report.error_message" severity="error" :closable="false" class="page-message">
      <pre class="error-details">{{ report.error_message }}</pre>
    </Message>

    <Message v-else-if="isBlocked && report.error_message" severity="warn" :closable="false" class="page-message">
      {{ report.error_message }}
    </Message>

    <!-- Коли звіт уже показано, пустого стану немає — підказуємо банером -->
    <HintBanner v-if="integrationsHint && isDone" title="Формування звіту недоступне" class="page-message">
      {{ integrationsHint }}
      <IntegrationsLink />
    </HintBanner>

    <!-- Loading / generating skeleton -->
    <div v-if="loading || generating || isActive" class="results-grid">
      <div class="skeleton column-skeleton"></div>
      <div class="skeleton column-skeleton"></div>
    </div>

    <!-- Results -->
    <div v-else-if="isDone" class="results-grid">

      <section class="column">
        <div class="column-head">
          <h2>Розбір звіту</h2>
          <div class="column-head-tools">
            <span class="status-badge">
              <span class="dot"></span>
              Готовий
            </span>
            <button v-if="hasFile" class="download-btn" type="button" :disabled="downloading" @click="downloadReport">
              <UiIcon :name="downloading ? 'spinner' : 'download'" :size="15" />
              XLSX
            </button>
          </div>
        </div>

        <ReportSummary :summary="report.summary" />
      </section>

      <section class="column">
        <div class="column-head">
          <h2>Виконані таски</h2>
          <span v-if="!taskState.loading && !taskState.error && !taskState.notConnected" class="column-head-note">{{ taskCountLabel }}<template v-if="taskState.snapshot"> · на момент генерації</template></span>
        </div>

        <div v-if="taskState.loading" class="skeleton tasks-skeleton"></div>

        <HintBanner v-else-if="taskState.notConnected">
          {{ trackerLabel }} не налаштовано — підключіть його на сторінці
          <IntegrationsLink>Інтеграції</IntegrationsLink>,
          щоб таски підтягувались у звіт.
        </HintBanner>

        <Message v-else-if="taskState.error" severity="warn" :closable="false">
          {{ taskState.error }}
        </Message>

        <ReportTaskList v-else :tasks="taskState.items" />
      </section>

    </div>

    <ReportEmptyState
      v-else
      :variant="emptyVariant"
      :tracker-label="trackerLabel"
      :integrations-hint="integrationsHint"
    />

    <!-- Повні таблиці активності з історичної БД — на всю ширину під колонками -->
    <ActivityBreakdown
      v-if="isDone && !loading && report.employee_id"
      :employee-id="report.employee_id"
      :date="report.report_date"
      :version="report.generated_at || ''"
    />

    <!-- AI-розбір дня — лише для адміністратора (бекенд теж під middleware admin) -->
    <AiAnalysisPanel
      v-if="auth.isAdmin && isDone && !loading && report.employee_id"
      :employee-id="report.employee_id"
      :date="report.report_date"
      :version="report.generated_at || ''"
    />

  </div>
</template>

<style scoped>
.dashboard-page {
  display: flex;
  flex-direction: column;
  flex: 1;
  min-height: 0;
}

.page-message {
  margin-top: 14px;
}

.employee-select {
  min-width: 220px;
}

.gen-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  padding: 12px 22px;
  border-radius: 0;
  font-family: inherit;
  font-size: 14.5px;
  font-weight: 600;
  border: none;
  white-space: nowrap;
  transition: all 0.15s ease;
  flex-shrink: 0;
  background: var(--accent);
  color: #fff;
  cursor: pointer;
  box-shadow: 0 2px 6px rgba(17, 17, 17, 0.18);
}

.skip-btn {
  padding: 12px 18px;
  border: 1.5px solid var(--line);
  border-radius: 0;
  background: var(--surface);
  color: var(--text-dim);
  font-family: inherit;
  font-size: 14px;
  font-weight: 600;
  white-space: nowrap;
  flex-shrink: 0;
  cursor: pointer;
  transition: color 0.15s ease;
}

.skip-btn:hover {
  color: #111111;
}

.gen-btn:disabled {
  cursor: not-allowed;
  opacity: 0.7;
}

.gen-btn.is-loading {
  background: var(--text-dim);
  color: #fff;
  cursor: wait;
  box-shadow: none;
}

.gen-btn.is-done {
  background: var(--surface);
  color: var(--accent);
  border: 1.5px solid var(--line);
  box-shadow: var(--shadow-sm);
}

.column-skeleton {
  height: 380px;
}

.tasks-skeleton {
  height: 200px;
}

.results-grid {
  margin-top: 16px;
  display: grid;
  grid-template-columns: 1.15fr 1fr;
  gap: 18px;
  animation: fadeUp 0.35s ease both;
}

@media (max-width: 900px) {
  .results-grid {
    grid-template-columns: 1fr;
  }
}

.column {
  min-width: 0;
  display: flex;
  flex-direction: column;
}

.column-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 10px;
}

.column-head h2 {
  margin: 0;
  font-size: 14.5px;
  font-weight: 700;
  color: var(--ink);
}

.column-head-tools {
  display: flex;
  align-items: center;
  gap: 8px;
}

.column-head-note {
  font-size: 12px;
  color: var(--muted);
  font-weight: 500;
}

.download-btn {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  background: var(--line);
  border: none;
  border-radius: 0;
  padding: 5px 10px;
  color: var(--accent);
  font-family: inherit;
  font-size: 12px;
  font-weight: 700;
  cursor: pointer;
  transition: all 0.15s ease;
}

.download-btn:hover:not(:disabled) {
  background: #d5f2ee;
}

.download-btn:disabled {
  cursor: wait;
}

.error-details {
  margin: 0;
  white-space: pre-wrap;
  word-break: break-word;
  font-size: 0.85rem;
  font-family: 'IBM Plex Mono', monospace;
}
</style>
