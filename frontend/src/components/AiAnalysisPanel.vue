<script setup>
// AI-розбір робочого дня. Показується лише адміністратору (батьківський
// ReportsView гейтить через auth.isAdmin, бекенд — через middleware('admin')).
import { computed, onUnmounted, ref, watch } from 'vue';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import Message from 'primevue/message';
import client from '../api/client';
import { formatDurationWithSeconds } from '../utils/duration';

const props = defineProps({
  employeeId: { type: Number, required: true },
  date: { type: String, required: true },
  // generated_at звіту: після перегенерації звіту аналіз теж оновлюється
  version: { type: String, default: '' },
});

const loading = ref(true);
const requesting = ref(false);
const errorMessage = ref('');
const analysis = ref(null);
const configured = ref(true);
// Перелік доступних AI приходить з бекенда разом із розбором: які ключі
// заповнені — вирішує .env, фронт лише дає обрати між налаштованими.
const providers = ref([]);
const provider = ref('');

let pollTimer = null;

const verdictMeta = {
  work_related: { label: 'Робоче', class: 'is-work' },
  personal: { label: 'Особисте', class: 'is-personal' },
  unknown: { label: 'Невідомо', class: 'is-unknown' },
};

const severityMeta = {
  critical: { label: 'Критичне', class: 'is-critical' },
  minor: { label: 'Дрібне', class: 'is-minor' },
};

const violationTypes = {
  personal_time: 'Особистий час',
  no_task_evidence: 'Таски не підтверджені',
  schedule: 'Графік',
  side_work: 'Робота на сторону',
  other: 'Інше',
};

const coverageMeta = {
  confirmed: { label: 'Підтверджено', class: 'is-work' },
  partial: { label: 'Частково', class: 'is-unknown' },
  not_evident: { label: 'Не видно', class: 'is-personal' },
};

const availableProviders = computed(() => providers.value.filter((item) => item.configured));
const status = computed(() => analysis.value?.status || null);
const isPending = computed(() => ['pending', 'processing'].includes(status.value));
const isDone = computed(() => status.value === 'completed');
const result = computed(() => (isDone.value ? analysis.value.result || {} : {}));
const unclear = computed(() => result.value.unclear_activities || []);
const coverage = computed(() => result.value.task_coverage || []);
const recommendations = computed(() => result.value.recommendations || []);
// Критичні йдуть першими: саме через них керівнику приходить лист.
const violations = computed(() => [...(result.value.violations || [])]
  .sort((a, b) => (a.severity === 'critical' ? 0 : 1) - (b.severity === 'critical' ? 0 : 1)));
const hasCritical = computed(() => violations.value.some((item) => item.severity === 'critical'));
// Час листа приходить в UTC — показуємо його в часовому поясі керівника.
const alertedAt = computed(() => (analysis.value?.alerted_at
  ? new Date(analysis.value.alerted_at).toLocaleString('uk-UA', { dateStyle: 'short', timeStyle: 'short' })
  : ''));

function stopPolling() {
  if (pollTimer) {
    clearTimeout(pollTimer);
    pollTimer = null;
  }
}

async function load({ silent = false } = {}) {
  if (!silent) {
    loading.value = true;
  }
  try {
    const { data } = await client.get('/analysis', {
      params: { employee_id: props.employeeId, date: props.date },
    });
    analysis.value = data.data;
    configured.value = data.configured;
    providers.value = data.providers || [];
    errorMessage.value = '';

    if (!availableProviders.value.some((item) => item.name === provider.value)) {
      const preferred = availableProviders.value.find((item) => item.default);
      provider.value = (preferred || availableProviders.value[0])?.name || '';
    }

    stopPolling();
    if (isPending.value) {
      pollTimer = setTimeout(() => load({ silent: true }), 5000);
    }
  } catch (error) {
    errorMessage.value = error.response?.data?.message || 'Не вдалося завантажити AI-аналіз.';
  } finally {
    loading.value = false;
  }
}

async function requestAnalysis() {
  requesting.value = true;
  errorMessage.value = '';
  try {
    const { data } = await client.post('/analysis', {
      employee_id: props.employeeId,
      date: props.date,
      provider: provider.value || undefined,
    });
    analysis.value = data.data;
    stopPolling();
    pollTimer = setTimeout(() => load({ silent: true }), 5000);
  } catch (error) {
    errorMessage.value = error.response?.data?.message || 'Не вдалося запустити аналіз.';
  } finally {
    requesting.value = false;
  }
}

watch(() => [props.employeeId, props.date, props.version], () => {
  stopPolling();
  analysis.value = null;
  load();
}, { immediate: true });

onUnmounted(stopPolling);
</script>

<template>
  <section class="analysis-section">
    <div class="section-head">
      <div class="section-head-titles">
        <h2>AI-аналіз дня</h2>
        <span v-if="isDone && analysis.model" class="section-note">{{ analysis.model }}</span>
      </div>
      <div v-if="configured && !loading && !isPending" class="section-head-actions">
        <select
          v-if="availableProviders.length > 1"
          v-model="provider"
          class="provider-select"
          :disabled="requesting"
        >
          <option v-for="item in availableProviders" :key="item.name" :value="item.name">
            {{ item.label }}
          </option>
        </select>
        <button
          type="button"
          class="analysis-action"
          :disabled="requesting"
          @click="requestAnalysis"
        >
          {{ analysis ? 'Проаналізувати ще раз' : 'Проаналізувати день' }}
        </button>
      </div>
    </div>

    <Message v-if="errorMessage" severity="warn" :closable="false">{{ errorMessage }}</Message>

    <div v-if="loading" class="skeleton analysis-skeleton"></div>

    <div v-else-if="!configured" class="panel empty-panel">
      AI-аналітику не налаштовано: додайте ANTHROPIC_API_KEY або DEEPSEEK_API_KEY у backend/.env.
    </div>

    <div v-else-if="isPending" class="panel empty-panel">
      Аналіз готується — це займає до кількох хвилин. Сторінка оновиться сама.
    </div>

    <Message v-else-if="status === 'failed'" severity="error" :closable="false">
      {{ analysis.error_message || 'Аналіз не вдався.' }}
    </Message>

    <div v-else-if="!analysis" class="panel empty-panel">
      Розбору за цей день ще немає. Він створюється автоматично після формування
      звіту — або натисніть «Проаналізувати день».
    </div>

    <template v-else-if="isDone">
      <div class="panel summary-panel">
        <p class="summary-text">{{ result.summary }}</p>
        <p v-if="result.focus_assessment" class="summary-focus">{{ result.focus_assessment }}</p>
      </div>

      <template v-if="violations.length">
        <div class="section-head sub">
          <div class="section-head-titles">
            <h2>Порушення</h2>
            <span class="section-note">{{ violations.length }}</span>
          </div>
          <span v-if="alertedAt" class="section-note">Лист керівнику надіслано {{ alertedAt }}</span>
          <span v-else-if="hasCritical" class="section-note">
            Лист не надіслано — пошту вказують у розділі «Працівники»
          </span>
        </div>

        <div class="violations-list">
          <div
            v-for="(item, i) in violations"
            :key="i"
            class="panel violation-panel"
            :class="{ 'is-critical': item.severity === 'critical' }"
          >
            <div class="violation-head">
              <span class="verdict-badge" :class="severityMeta[item.severity]?.class">
                {{ severityMeta[item.severity]?.label || item.severity }}
              </span>
              <span class="violation-type">{{ violationTypes[item.type] || 'Інше' }}</span>
            </div>
            <p class="violation-details">{{ item.details }}</p>
            <p v-if="item.evidence" class="violation-evidence">Підстава: {{ item.evidence }}</p>
            <p v-if="item.question" class="violation-question">
              <strong>Що запитати:</strong> {{ item.question }}
            </p>
          </div>
        </div>
      </template>

      <div class="section-head sub">
        <div class="section-head-titles">
          <h2>Незрозумілі активності</h2>
          <span class="section-note">{{ unclear.length }}</span>
        </div>
      </div>

      <div v-if="!unclear.length" class="panel empty-panel">
        Активностей поза посадою й тасками не знайдено.
      </div>

      <div v-else class="panel table-panel">
        <DataTable :value="unclear" sort-field="duration_seconds" :sort-order="-1" data-key="name">
          <Column field="name" header="Діяльність" sortable />
          <Column field="verdict" header="Вердикт" sortable style="width: 120px">
            <template #body="{ data }">
              <span class="verdict-badge" :class="verdictMeta[data.verdict]?.class">
                {{ verdictMeta[data.verdict]?.label || data.verdict }}
              </span>
            </template>
          </Column>
          <Column field="duration_seconds" header="Час" sortable style="width: 96px">
            <template #body="{ data }">
              <span class="duration-value">{{ formatDurationWithSeconds(data.duration_seconds) }}</span>
            </template>
          </Column>
          <Column field="reasoning" header="Обґрунтування" />
        </DataTable>
      </div>

      <template v-if="coverage.length">
        <div class="section-head sub">
          <div class="section-head-titles">
            <h2>Таски дня</h2>
          </div>
        </div>
        <div class="panel table-panel">
          <DataTable :value="coverage" data-key="task">
            <Column field="task" header="Таска" />
            <Column field="status" header="Стан" style="width: 140px">
              <template #body="{ data }">
                <span class="verdict-badge" :class="coverageMeta[data.status]?.class">
                  {{ coverageMeta[data.status]?.label || data.status }}
                </span>
              </template>
            </Column>
            <Column field="evidence" header="Підстава" />
          </DataTable>
        </div>
      </template>

      <div v-if="recommendations.length" class="notes-grid">
        <div class="panel notes-panel">
          <div class="notes-title">Рекомендації</div>
          <ul class="notes-list">
            <li v-for="(item, i) in recommendations" :key="i">{{ item }}</li>
          </ul>
        </div>
      </div>
    </template>
  </section>
</template>

<style scoped>
.analysis-section {
  margin-top: 22px;
  animation: fadeUp 0.35s ease both;
}

.section-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 10px 16px;
  margin-bottom: 10px;
}

.section-head.sub {
  margin-top: 18px;
}

.section-head-titles {
  display: flex;
  align-items: baseline;
  gap: 10px;
  min-width: 0;
}

.section-head h2 {
  margin: 0;
  font-size: 14.5px;
  font-weight: 700;
  color: var(--ink);
}

.section-note {
  font-size: 12px;
  color: var(--muted);
  font-weight: 500;
  font-variant-numeric: tabular-nums;
}

.section-head-actions {
  display: flex;
  align-items: center;
  gap: 8px;
}

.provider-select {
  padding: 6px 8px;
  border: 1px solid var(--line);
  background: var(--surface);
  font-family: inherit;
  font-size: 12px;
  font-weight: 600;
  color: var(--text-dim);
  cursor: pointer;
}

.provider-select:disabled {
  opacity: 0.55;
  cursor: default;
}

.analysis-action {
  padding: 6px 11px;
  border: 1px solid var(--line);
  background: var(--surface);
  font-family: inherit;
  font-size: 12px;
  font-weight: 600;
  color: var(--text-dim);
  cursor: pointer;
  transition: all 0.15s ease;
}

.analysis-action:hover:not(:disabled) {
  border-color: var(--accent);
  color: var(--accent);
}

.analysis-action:disabled {
  opacity: 0.55;
  cursor: default;
}

.summary-panel {
  padding: 14px 16px;
}

.summary-text {
  margin: 0;
  font-size: 13.5px;
  line-height: 1.55;
  color: var(--ink);
}

.summary-focus {
  margin: 8px 0 0;
  font-size: 13px;
  line-height: 1.55;
  color: var(--muted);
}

.empty-panel {
  padding: 14px 16px;
  font-size: 13px;
  line-height: 1.55;
  color: var(--muted);
}

.table-panel {
  overflow-x: auto;
}

.table-panel :deep(.p-datatable-table) {
  min-width: 560px;
}

.verdict-badge {
  display: inline-block;
  padding: 2px 8px;
  font-size: 11.5px;
  font-weight: 600;
  white-space: nowrap;
  background: var(--line);
  color: var(--muted);
}

.verdict-badge.is-work {
  background: #d5f2ee;
  color: #0e7d70;
}

.verdict-badge.is-personal,
.verdict-badge.is-critical {
  background: #fbe3e3;
  color: #b33c3c;
}

.violations-list {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.violation-panel {
  padding: 13px 15px;
  animation: fadeUp 0.35s ease both;
}

.violation-panel.is-critical {
  border-left: 3px solid #b33c3c;
}

.violation-head {
  display: flex;
  align-items: center;
  gap: 9px;
  margin-bottom: 7px;
}

.violation-type {
  font-size: 12px;
  font-weight: 600;
  color: var(--muted);
}

.violation-details {
  margin: 0;
  font-size: 13.5px;
  line-height: 1.55;
  color: var(--ink);
}

.violation-evidence {
  margin: 5px 0 0;
  font-size: 12.5px;
  line-height: 1.5;
  color: var(--muted);
}

.violation-question {
  margin: 8px 0 0;
  font-size: 13px;
  line-height: 1.5;
  color: var(--text-dim);
}

.duration-value {
  display: block;
  text-align: right;
  white-space: nowrap;
  font-variant-numeric: tabular-nums;
  font-weight: 600;
  color: var(--ink);
}

.notes-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
  gap: 12px;
  margin-top: 18px;
}

.summary-panel,
.empty-panel,
.notes-panel {
  animation: fadeUp 0.35s ease both;
}

.notes-panel {
  padding: 14px 16px;
}

.notes-title {
  font-size: 13px;
  font-weight: 700;
  color: var(--ink);
  margin-bottom: 8px;
}

.notes-list {
  margin: 0;
  padding-left: 18px;
  font-size: 13px;
  line-height: 1.6;
  color: var(--muted);
}

.analysis-skeleton {
  height: 120px;
  border-radius: 10px;
}
</style>
