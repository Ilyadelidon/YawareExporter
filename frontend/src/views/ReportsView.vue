<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import DatePicker from 'primevue/datepicker';
import Message from 'primevue/message';
import Select from 'primevue/select';
import client from '../api/client';
import ActivityBreakdown from '../components/ActivityBreakdown.vue';
import AiAnalysisPanel from '../components/AiAnalysisPanel.vue';
import { useAuthStore } from '../stores/auth';

const auth = useAuthStore();

const employees = ref([]);
const selectedEmployee = ref(null);
const ownEmployeeId = ref(null);
const selectedDate = ref(new Date());
const report = ref(null);
const loading = ref(true);
const generating = ref(false);
const downloading = ref(false);
const errorMessage = ref('');
const tasks = ref([]);
const tasksLoading = ref(false);
const tasksError = ref('');
const trackerNotConnected = ref(false);
const tasksSnapshot = ref(false);
// Який трекер віддав таски: приходить у відповіді /tasks, до першого запиту
// беремо вибір користувача з профілю.
const tasksProvider = ref(null);

let pollTimer = null;

const labelColors = {
  green: '#61bd4f',
  yellow: '#f2d600',
  orange: '#ff9f1a',
  red: '#eb5a46',
  purple: '#c377e0',
  blue: '#0079bf',
  sky: '#00c2e0',
  lime: '#51e898',
  pink: '#ff78cb',
  black: '#344563',
};

const PROVIDER_LABELS = { trello: 'Trello', bitrix: 'Бітрікс24' };

const MONTHS_UK = ['січня', 'лютого', 'березня', 'квітня', 'травня', 'червня', 'липня', 'серпня', 'вересня', 'жовтня', 'листопада', 'грудня'];

const TIME_KEYS = {
  productive: 'Продуктивно',
  neutral: 'Невідомо/нейтрально',
  unproductive: 'Непродуктивний час',
  total: 'Загальний час',
};

const isActive = computed(() => ['pending', 'processing'].includes(report.value?.status));
const isDone = computed(() => report.value?.status === 'completed');
const isFailed = computed(() => report.value?.status === 'failed');
// Звіт свідомо не сформовано: у дні лишився час поза тасками. Це не збій,
// тож показуємо не помилку, а що саме треба поправити в трекері.
const isBlocked = computed(() => report.value?.status === 'blocked');

// Стан персональних інтеграцій працівника: без активного трекера й таблиці
// формування звіту заблоковане. Підключають їх на сторінці «Інтеграції»;
// адмін власних звітів не формує, тому для нього перевірка не діє.
const integrations = ref({ loaded: false, tracker: false, sheets: false, provider: null });
const integrationsOk = computed(() => auth.isAdmin || (integrations.value.loaded && integrations.value.tracker && integrations.value.sheets));

// Назва активного таск-трекера для підказок і банерів.
const trackerLabel = computed(() => PROVIDER_LABELS[
  tasksProvider.value || integrations.value.provider || auth.user?.task_provider
] || 'Trello');

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

const canGenerate = computed(() => !loading.value && !generating.value && !isActive.value && integrationsOk.value && !viewingOther.value && (!auth.isAdmin || selectedEmployee.value));

const employeeName = computed(() => {
  if (report.value?.employee?.name) return report.value.employee.name;
  if (auth.isAdmin) {
    return employees.value.find((employee) => employee.id === selectedEmployee.value)?.name || '';
  }
  return auth.user?.name || '';
});

const dateLabel = computed(() => {
  const date = selectedDate.value;
  if (!date) return '';
  return `${date.getDate()} ${MONTHS_UK[date.getMonth()]} ${date.getFullYear()}`;
});

function parseClock(value) {
  if (typeof value !== 'string') return null;
  const match = value.trim().match(/^(\d+):(\d{2}):(\d{2})$/);
  if (!match) return null;
  return Number(match[1]) * 3600 + Number(match[2]) * 60 + Number(match[3]);
}

const timeStrip = computed(() => {
  const summary = report.value?.summary || {};
  return {
    productive: summary[TIME_KEYS.productive] || '—',
    neutral: summary[TIME_KEYS.neutral] || '—',
    unproductive: summary[TIME_KEYS.unproductive] || '—',
    total: summary[TIME_KEYS.total] || '—',
  };
});

const distribution = computed(() => {
  const summary = report.value?.summary || {};
  const productive = parseClock(summary[TIME_KEYS.productive]);
  const neutral = parseClock(summary[TIME_KEYS.neutral]);
  const unproductive = parseClock(summary[TIME_KEYS.unproductive]);
  const sum = (productive || 0) + (neutral || 0) + (unproductive || 0);
  if (!sum) return null;
  const productivePct = Math.round(((productive || 0) / sum) * 100);
  const neutralPct = Math.round(((neutral || 0) / sum) * 100);
  return {
    productive: productivePct,
    neutral: neutralPct,
    unproductive: 100 - productivePct - neutralPct,
  };
});

const detailRows = computed(() => {
  const summary = report.value?.summary;
  if (!summary) return [];
  const stripValues = new Set(Object.values(TIME_KEYS));
  return Object.entries(summary)
    .filter(([key]) => !stripValues.has(key))
    .map(([label, value]) => ({ label, value: value === '' || value === null ? '—' : value }));
});

const taskCountLabel = computed(() => {
  const n = tasks.value.length;
  const mod10 = n % 10;
  const mod100 = n % 100;
  const word = (mod10 >= 1 && mod10 <= 4 && (mod100 < 12 || mod100 > 14)) ? 'завдання' : 'завдань';
  return `${n} ${word}`;
});

function isUrl(value) {
  return typeof value === 'string' && value.startsWith('https://');
}

function toIso(date) {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

function schedulePoll() {
  clearTimeout(pollTimer);
  if (isActive.value && report.value?.id) {
    pollTimer = setTimeout(refreshReport, 5000);
  }
}

async function refreshReport() {
  if (!report.value?.id) return;
  try {
    const { data } = await client.get(`/reports/${report.value.id}`);
    report.value = data.data;
  } catch {
    // тимчасова помилка мережі — наступний тік поллінгу спробує ще раз
  }
  if (isDone.value && !tasks.value.length) {
    applyTasks();
  }
  schedulePoll();
}

async function loadReport() {
  clearTimeout(pollTimer);
  loading.value = true;
  errorMessage.value = '';
  report.value = null;
  tasks.value = [];
  tasksError.value = '';
  trackerNotConnected.value = false;
  tasksSnapshot.value = false;

  try {
    if (auth.isAdmin && !selectedEmployee.value) {
      return;
    }
    const isoDate = toIso(selectedDate.value);
    const params = { date_from: isoDate, date_to: isoDate };
    if (auth.isAdmin) {
      params.employee_id = selectedEmployee.value;
    }
    const { data } = await client.get('/reports', { params });
    report.value = data.data?.[0] || null;
  } catch (error) {
    errorMessage.value = error.response?.data?.message || 'Не вдалося завантажити звіт.';
  } finally {
    loading.value = false;
  }

  if (isDone.value) {
    applyTasks();
  }
  schedulePoll();
}

async function loadEmployees() {
  if (!auth.isAdmin) {
    return;
  }
  const { data } = await client.get('/employees');
  employees.value = data.data;
  const own = employees.value.find((employee) => employee.user_id === auth.user?.id);
  ownEmployeeId.value = own?.id || null;
  selectedEmployee.value = own?.id || employees.value[0]?.id || null;
}

async function generateReport() {
  if (!canGenerate.value) return;

  errorMessage.value = '';
  generating.value = true;
  tasks.value = [];
  tasksError.value = '';
  trackerNotConnected.value = false;
  tasksSnapshot.value = false;

  try {
    const payload = { report_date: toIso(selectedDate.value) };
    if (auth.isAdmin) {
      payload.employee_id = selectedEmployee.value;
    }
    const { data } = await client.post('/reports', payload);
    report.value = data.data;
    schedulePoll();
  } catch (error) {
    errorMessage.value = error.response?.data?.message || 'Не вдалося поставити звіт у чергу.';
  } finally {
    generating.value = false;
  }
}

// Готовий звіт містить знімок тасок на момент генерації — показуємо його,
// щоб зміни в трекері не переписували вже сформовані звіти. Живий запит
// лишається fallback-ом для старих звітів без знімка.
function applyTasks() {
  if (Array.isArray(report.value?.tasks)) {
    tasks.value = report.value.tasks;
    tasksSnapshot.value = true;
    tasksError.value = '';
    trackerNotConnected.value = false;
    return;
  }
  loadTasks(toIso(selectedDate.value));
}

async function loadTasks(date) {
  tasksLoading.value = true;
  tasksError.value = '';
  trackerNotConnected.value = false;
  tasksSnapshot.value = false;
  try {
    const { data } = await client.get('/tasks', { params: { date } });
    tasks.value = data.data;
    tasksProvider.value = data.provider || tasksProvider.value;
  } catch (error) {
    tasksProvider.value = error.response?.data?.provider || tasksProvider.value;
    if (error.response?.data?.not_connected) {
      trackerNotConnected.value = true;
    } else {
      tasksError.value = error.response?.data?.message || `Не вдалося отримати таски з ${trackerLabel.value}.`;
    }
  } finally {
    tasksLoading.value = false;
  }
}

async function downloadReport() {
  if (!report.value?.id) return;
  downloading.value = true;
  try {
    const response = await client.get(`/reports/${report.value.id}/download`, { responseType: 'blob' });
    const url = URL.createObjectURL(response.data);
    const link = document.createElement('a');
    link.href = url;
    link.download = `yaware-report-${report.value.report_date}.xlsx`;
    link.click();
    URL.revokeObjectURL(url);
  } catch {
    errorMessage.value = 'Не вдалося завантажити файл звіту.';
  } finally {
    downloading.value = false;
  }
}

// Коротка перевірка готовності інтеграцій — деталі й підключення живуть
// на сторінці «Інтеграції», тут потрібен лише факт «налаштовано / ні».
async function loadIntegrations() {
  const provider = auth.user?.task_provider || 'trello';
  try {
    const [tracker, google] = await Promise.all([
      client.get(provider === 'bitrix' ? '/bitrix/status' : '/trello/status'),
      client.get('/google/status'),
    ]);
    integrations.value = {
      loaded: true,
      tracker: Boolean(tracker.data.connected),
      sheets: Boolean(google.data.account_connected && google.data.spreadsheet_id),
      provider,
    };
  } catch {
    // Стан не отримали — кнопку не блокуємо, бекенд однаково перевірить інтеграції
    // перед генерацією і поверне зрозумілу помилку.
    integrations.value = { loaded: true, tracker: true, sheets: true, provider };
  }
}

watch([selectedDate, selectedEmployee], () => {
  if (selectedDate.value) {
    loadReport();
  }
});

onMounted(async () => {
  if (!auth.isAdmin) loadIntegrations();
  await loadEmployees();
  // для адміна з обраним працівником loadReport уже викликав watch
  if (!auth.isAdmin || !selectedEmployee.value) {
    await loadReport();
  }
});

onUnmounted(() => clearTimeout(pollTimer));
</script>

<template>
  <div class="dashboard-page">

    <!-- Heading + controls -->
    <div class="page-head">
      <div class="page-head-info">
        <div class="page-head-icon">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#149d8d" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><line x1="10" y1="9" x2="8" y2="9"></line></svg>
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
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#149d8d" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
          <DatePicker v-model="selectedDate" date-format="dd.mm.yy" :manual-input="false" select-other-months />
        </div>
        <button
          v-if="!viewingOther"
          class="gen-btn"
          :class="{ 'is-loading': generating || isActive, 'is-done': isDone && !generating && !isActive }"
          type="button"
          :disabled="!canGenerate"
          @click="generateReport"
        >
          <template v-if="generating || isActive">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="animation: spin 0.8s linear infinite;"><path d="M21 12a9 9 0 1 1-6.219-8.56"></path></svg>
            Формування…
          </template>
          <template v-else-if="isDone">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>
            Сформовано · оновити
          </template>
          <template v-else>
            <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><polygon points="5 3 19 12 5 21 5 3"></polygon></svg>
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
    <div v-if="integrationsHint && isDone" class="hint-banner page-message">
      <div class="hint-banner-icon">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path></svg>
      </div>
      <div>
        <div class="hint-banner-title">Формування звіту недоступне</div>
        <div class="hint-banner-text">
          {{ integrationsHint }}
          <router-link class="hint-link" :to="{ name: 'integrations' }">Перейти до інтеграцій</router-link>
        </div>
      </div>
    </div>

    <!-- Loading / generating skeleton -->
    <div v-if="loading || generating || isActive" class="results-grid">
      <div class="skeleton column-skeleton"></div>
      <div class="skeleton column-skeleton"></div>
    </div>

    <!-- Results -->
    <div v-else-if="isDone" class="results-grid">

      <!-- Report breakdown -->
      <section class="column">
        <div class="column-head">
          <h2>Розбір звіту</h2>
          <div class="column-head-tools">
            <span class="status-badge">
              <span class="dot"></span>
              Готовий
            </span>
            <button class="download-btn" type="button" :disabled="downloading" @click="downloadReport">
              <svg v-if="!downloading" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
              <svg v-else width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="animation: spin 0.8s linear infinite;"><path d="M21 12a9 9 0 1 1-6.219-8.56"></path></svg>
              XLSX
            </button>
          </div>
        </div>

        <div v-if="report.summary" class="time-strip">
          <div class="time-card">
            <div class="time-card-label">Продуктивно</div>
            <div class="time-card-value accent">{{ timeStrip.productive }}</div>
          </div>
          <div class="time-card">
            <div class="time-card-label">Нейтрально</div>
            <div class="time-card-value dim">{{ timeStrip.neutral }}</div>
          </div>
          <div class="time-card">
            <div class="time-card-label">Непродуктивно</div>
            <div class="time-card-value muted">{{ timeStrip.unproductive }}</div>
          </div>
          <div class="time-card total">
            <div class="time-card-label">Загальний час</div>
            <div class="time-card-value">{{ timeStrip.total }}</div>
          </div>
        </div>

        <div v-if="distribution" class="panel distribution">
          <div class="distribution-bar">
            <div class="seg-productive" :style="{ width: distribution.productive + '%' }"></div>
            <div class="seg-neutral" :style="{ width: distribution.neutral + '%' }"></div>
            <div class="seg-unproductive" :style="{ width: distribution.unproductive + '%' }"></div>
          </div>
          <div class="distribution-legend">
            <div class="legend-item"><span class="legend-dot seg-productive"></span>Продуктивно · {{ distribution.productive }}%</div>
            <div class="legend-item"><span class="legend-dot seg-neutral"></span>Нейтрально · {{ distribution.neutral }}%</div>
            <div class="legend-item"><span class="legend-dot seg-unproductive outlined"></span>Непродуктивно · {{ distribution.unproductive }}%</div>
          </div>
        </div>

        <div v-if="detailRows.length" class="panel detail-rows">
          <div v-for="row in detailRows" :key="row.label" class="detail-row">
            <span class="detail-label">{{ row.label }}</span>
            <span class="detail-value">
              <a v-if="isUrl(row.value)" :href="row.value" target="_blank" rel="noopener">Відкрити вкладку звіту ↗</a>
              <template v-else>{{ row.value }}</template>
            </span>
          </div>
        </div>

        <div v-if="!report.summary" class="panel empty-panel">
          Дані звіту ще не сформовано.
        </div>
      </section>

      <!-- Tasks section -->
      <section class="column">
        <div class="column-head">
          <h2>Виконані таски</h2>
          <span v-if="!tasksLoading && !tasksError && !trackerNotConnected" class="column-head-note">{{ taskCountLabel }}<template v-if="tasksSnapshot"> · на момент генерації</template></span>
        </div>

        <div v-if="tasksLoading" class="skeleton tasks-skeleton"></div>

        <div v-else-if="trackerNotConnected" class="hint-banner">
          <div class="hint-banner-icon">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path></svg>
          </div>
          <div class="hint-banner-text">
            {{ trackerLabel }} не налаштовано — підключіть його на сторінці
            <router-link class="hint-link" :to="{ name: 'integrations' }">Інтеграції</router-link>,
            щоб таски підтягувались у звіт.
          </div>
        </div>

        <Message v-else-if="tasksError" severity="warn" :closable="false">
          {{ tasksError }}
        </Message>

        <div v-else-if="!tasks.length" class="panel empty-panel">
          Тасок за цю дату немає.
        </div>

        <div v-else class="task-list">
          <div v-for="task in tasks" :key="task.id" class="panel task-card">
            <div class="task-main">
              <div class="task-title-row">
                <a :href="task.url" target="_blank" rel="noopener" class="task-title">{{ task.name }}</a>
                <span v-if="task.due_complete" class="task-status">Готово</span>
              </div>
              <div class="task-meta">
                <span v-if="task.list">{{ task.list }}</span>
                <span
                  v-for="label in task.labels"
                  :key="label.name + label.color"
                  class="task-label"
                  :style="{ backgroundColor: labelColors[label.color] || '#b3bac5' }"
                >{{ label.name }}</span>
              </div>
              <div v-if="task.comment" class="task-comment">{{ task.comment }}</div>
            </div>
            <div v-if="task.start || task.due" class="task-time">
              <span v-if="task.start">{{ task.start }}</span>
              <span v-if="task.start && task.due"> — </span>
              <span v-if="task.due">{{ task.due }}</span>
            </div>
          </div>
        </div>
      </section>

    </div>

    <!-- Empty state -->
    <div v-else class="empty-state">
      <div class="empty-state-icon">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#a8a8a8" stroke-width="1.8"><rect x="3" y="4" width="18" height="18" rx="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
      </div>
      <template v-if="auth.isAdmin && !selectedEmployee">
        <div class="empty-state-title">Оберіть працівника</div>
        <div class="empty-state-text">Щоб переглянути або сформувати звіт, оберіть працівника у верхній панелі</div>
      </template>
      <template v-else-if="isFailed">
        <div class="empty-state-title">Не вдалося сформувати звіт</div>
        <div class="empty-state-text">Спробуйте натиснути «Сформувати звіт» ще раз або перевірте повідомлення про помилку вище</div>
      </template>
      <template v-else-if="isBlocked">
        <div class="empty-state-title">Є час поза тасками</div>
        <div class="empty-state-text">Додайте у {{ trackerLabel }} таски з часом початку й завершення так, щоб вони покрили весь робочий день, і натисніть «Сформувати звіт» ще раз</div>
      </template>
      <template v-else-if="viewingOther">
        <div class="empty-state-title">Звіту за цю дату немає</div>
        <div class="empty-state-text">Звіти формують самі працівники — тут можна лише переглядати вже сформовані</div>
      </template>
      <template v-else-if="integrationsHint">
        <div class="empty-state-title">Підключіть інтеграції</div>
        <div class="empty-state-text">
          {{ integrationsHint }}
          <router-link class="hint-link" :to="{ name: 'integrations' }">Перейти до інтеграцій</router-link>
        </div>
      </template>
      <template v-else>
        <div class="empty-state-title">Звіт ще не сформовано</div>
        <div class="empty-state-text">Натисніть «Сформувати звіт», щоб отримати розбір активності та список виконаних завдань</div>
      </template>
    </div>

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

.time-strip {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 10px;
  margin-bottom: 10px;
}

@media (max-width: 560px) {
  .time-strip {
    grid-template-columns: repeat(2, 1fr);
  }
}

.time-card {
  background: var(--surface);
  border: 1px solid var(--line);
  border-radius: var(--radius);
  padding: 11px 12px;
  box-shadow: var(--shadow-sm);
}

.time-card-label {
  font-size: 11px;
  font-weight: 600;
  color: var(--muted);
  margin-bottom: 4px;
}

.time-card-value {
  font-size: 16.5px;
  font-weight: 800;
  font-variant-numeric: tabular-nums;
}

.time-card-value.accent { color: var(--accent); }
.time-card-value.dim { color: var(--text-dim); }
.time-card-value.muted { color: var(--muted-2); }

.time-card.total {
  background: var(--accent);
  border-color: var(--accent);
  box-shadow: 0 2px 6px rgba(17, 17, 17, 0.18);
}

.time-card.total .time-card-label {
  color: #b8e6e0;
}

.time-card.total .time-card-value {
  color: #fff;
}

.distribution {
  padding: 14px 16px 12px;
  margin-bottom: 10px;
}

.distribution-bar {
  display: flex;
  height: 8px;
  border-radius: 0;
  overflow: hidden;
  margin-bottom: 10px;
}

.seg-productive { background: var(--accent); }
.seg-neutral { background: #c4c4c4; }
.seg-unproductive { background: var(--line); }

.distribution-legend {
  display: flex;
  gap: 18px;
  flex-wrap: wrap;
}

.legend-item {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  color: var(--text-dim);
}

.legend-dot {
  width: 7px;
  height: 7px;
  border-radius: 0;
}

.legend-dot.outlined {
  border: 1px solid #d8d8d8;
}

.detail-rows {
  overflow: hidden;
}

.detail-row {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 16px;
  padding: 8px 16px;
  border-bottom: 1px solid var(--line);
}

.detail-row:last-child {
  border-bottom: none;
}

.detail-label {
  font-size: 12.5px;
  color: var(--muted);
  font-weight: 500;
  flex-shrink: 0;
}

.detail-value {
  font-size: 12.5px;
  color: var(--ink);
  font-weight: 600;
  text-align: right;
  white-space: pre-wrap;
  word-break: break-word;
}

.empty-panel {
  padding: 20px 16px;
  font-size: 13.5px;
  color: var(--muted);
  text-align: center;
}

.empty-state {
  margin-top: 20px;
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  text-align: center;
  padding: 60px 20px;
  border: 1.5px dashed var(--line);
  border-radius: 0;
  background: var(--surface);
  box-shadow: var(--shadow-sm);
  animation: fadeUp 0.35s ease both;
}

.empty-state-icon {
  width: 52px;
  height: 52px;
  border-radius: 0;
  background: var(--app-bg);
  display: flex;
  align-items: center;
  justify-content: center;
  margin-bottom: 16px;
}

.empty-state-title {
  font-size: 15px;
  font-weight: 600;
  color: var(--ink);
  margin-bottom: 6px;
}

.empty-state-text {
  font-size: 13.5px;
  color: var(--muted);
  max-width: 340px;
}

/* Посилання на сторінку інтеграцій у підказках про ненастроєні підключення */
.hint-link {
  color: var(--accent);
  font-weight: 600;
  text-decoration: none;
  white-space: nowrap;
}

.hint-link:hover {
  text-decoration: underline;
}

.task-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.task-card {
  padding: 11px 14px;
  display: flex;
  align-items: flex-start;
  gap: 12px;
}

.task-main {
  flex: 1;
  min-width: 0;
}

.task-title-row {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 2px;
}

.task-title {
  font-size: 13.5px;
  font-weight: 600;
  color: var(--ink);
  text-decoration: none;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.task-title:hover {
  text-decoration: underline;
}

.task-status {
  font-size: 10px;
  font-weight: 700;
  padding: 1px 7px;
  border-radius: 0;
  background: var(--line);
  color: var(--ink);
  flex-shrink: 0;
}

.task-meta {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 6px;
  font-size: 11.5px;
  color: var(--muted);
}

.task-label {
  color: #fff;
  border-radius: 0;
  padding: 0 6px;
  font-size: 10px;
  font-weight: 600;
  line-height: 16px;
  overflow-wrap: anywhere;
}

/* Коментарі з таск-трекера часто містять довгі посилання — вони мають переноситись
   усередині картки, а не розтягувати сторінку. */
.task-comment {
  margin-top: 4px;
  font-size: 12px;
  color: var(--text-dim);
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}

.task-time {
  text-align: right;
  flex-shrink: 0;
  font-size: 11px;
  color: var(--muted-2);
  font-variant-numeric: tabular-nums;
}

.error-details {
  margin: 0;
  white-space: pre-wrap;
  word-break: break-word;
  font-size: 0.85rem;
  font-family: 'IBM Plex Mono', monospace;
}

/* Довгий інтервал часу таски не лишає місця тексту на мобільній ширині —
   складаємо картку вертикально, час іде під назвою. align-items: stretch
   обов'язковий: із flex-start колонка отримує ширину найдовшого рядка назви
   і розсуває всю сторінку вшир. */
@media (max-width: 640px) {
  .task-card {
    flex-direction: column;
    align-items: stretch;
    gap: 4px;
  }

  /* На вузькому екрані обрізана назва майже нечитабельна — краще перенос */
  .task-title-row {
    align-items: flex-start;
  }

  .task-title {
    overflow: visible;
    white-space: normal;
    overflow-wrap: anywhere;
  }

  .task-time {
    text-align: left;
  }
}
</style>
