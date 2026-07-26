<script setup>
import { onMounted, reactive, ref } from 'vue';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import DatePicker from 'primevue/datepicker';
import Select from 'primevue/select';
import Message from 'primevue/message';
import client from '../api/client';
import { useAuthStore } from '../stores/auth';
import { formatDuration, formatDurationWithSeconds } from '../utils/duration';

const auth = useAuthStore();

const stats = ref([]);
const totals = ref(null);
const employees = ref([]);
const loading = ref(true);
const errorMessage = ref('');
const selectedEmployee = ref(null);
const dateFrom = ref(null);
const dateTo = ref(null);
const expandedRows = ref({});
const activitiesByStat = reactive({});

const productivityMeta = {
  productive: { label: 'Продуктивно', class: 'is-productive' },
  unproductive: { label: 'Непродуктивно', class: 'is-unproductive' },
  neutral: { label: 'Нейтрально', class: 'is-neutral' },
};

function toLocalIso(date) {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

async function loadStats() {
  if (!dateFrom.value || !dateTo.value) {
    return;
  }
  loading.value = true;
  errorMessage.value = '';
  try {
    const params = { date_from: toLocalIso(dateFrom.value), date_to: toLocalIso(dateTo.value) };
    if (auth.isAdmin && selectedEmployee.value) {
      params.employee_id = selectedEmployee.value;
    }
    const { data } = await client.get('/stats', { params });
    stats.value = data.data;
    totals.value = data.totals;
    expandedRows.value = {};
  } catch (error) {
    errorMessage.value = error.response?.data?.message || 'Не вдалося завантажити історію.';
  } finally {
    loading.value = false;
  }
}

async function loadEmployees() {
  if (!auth.isAdmin) {
    return;
  }
  const { data } = await client.get('/employees');
  employees.value = data.data;
}

async function onRowExpand(event) {
  const stat = event.data;
  if (activitiesByStat[stat.id]) {
    return;
  }
  try {
    const { data } = await client.get('/stats/activities', {
      params: { employee_id: stat.employee_id, date: stat.date },
    });
    activitiesByStat[stat.id] = data.data;
  } catch {
    activitiesByStat[stat.id] = [];
  }
}

onMounted(async () => {
  const today = new Date();
  dateFrom.value = new Date(today.getFullYear(), today.getMonth(), 1);
  dateTo.value = today;
  await Promise.all([loadStats(), loadEmployees()]);
});
</script>

<template>
  <div class="history-page">
    <div class="page-head">
      <div class="page-head-info">
        <div class="page-head-icon">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#149d8d" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
        </div>
        <div>
          <div class="page-head-title">Статистика</div>
          <div class="page-head-subtitle">Накопичена статистика по днях і працівниках</div>
        </div>
      </div>
      <div class="page-head-actions">
        <Select
          v-if="auth.isAdmin"
          v-model="selectedEmployee"
          :options="employees"
          option-label="name"
          option-value="id"
          placeholder="Усі працівники"
          show-clear
          filter
          class="employee-select"
        />
        <div class="field-pill">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#149d8d" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
          <DatePicker v-model="dateFrom" date-format="dd.mm.yy" :manual-input="false" />
        </div>
        <div class="field-pill">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#149d8d" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
          <DatePicker v-model="dateTo" date-format="dd.mm.yy" :manual-input="false" />
        </div>
        <button class="btn-accent" type="button" :disabled="loading" @click="loadStats">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
          Показати
        </button>
      </div>
    </div>

    <Message v-if="errorMessage" severity="error" :closable="true" class="page-message" @close="errorMessage = ''">
      {{ errorMessage }}
    </Message>

    <div v-if="totals" class="totals-row">
      <div class="total-card">
        <span class="total-label">Днів у вибірці</span>
        <span class="total-value">{{ totals.days }}</span>
      </div>
      <div class="total-card">
        <span class="total-label">Загальний час</span>
        <span class="total-value">{{ formatDuration(totals.total_seconds) }}</span>
      </div>
      <div class="total-card is-productive">
        <span class="total-label">Продуктивно</span>
        <span class="total-value">{{ formatDuration(totals.productive_seconds) }}</span>
      </div>
      <div class="total-card is-unproductive">
        <span class="total-label">Непродуктивно</span>
        <span class="total-value">{{ formatDuration(totals.unproductive_seconds) }}</span>
      </div>
      <div class="total-card is-neutral">
        <span class="total-label">Нейтрально</span>
        <span class="total-value">{{ formatDuration(totals.neutral_seconds) }}</span>
      </div>
      <div class="total-card">
        <span class="total-label">Запізнення разом</span>
        <span class="total-value">{{ formatDuration(totals.lateness_seconds) }}</span>
      </div>
    </div>

    <div class="panel table-panel">
      <DataTable
        v-model:expanded-rows="expandedRows"
        :value="stats"
        :loading="loading"
        paginator
        :rows="31"
        data-key="id"
        @row-expand="onRowExpand"
      >
        <template #empty>
          <div class="table-empty">
            За обраний період даних немає. Статистика наповнюється під час генерації звітів — сформуйте звіт за потрібний день на вкладці «Звіти».
          </div>
        </template>
        <Column expander style="width: 42px" />
        <Column field="date" header="Дата" sortable>
          <template #body="{ data }"><span class="date-value">{{ data.date }}</span></template>
        </Column>
        <Column v-if="auth.isAdmin" field="employee.name" header="Працівник" sortable />
        <Column field="first_action" header="Перша дія">
          <template #body="{ data }">{{ data.first_action || '—' }}</template>
        </Column>
        <Column field="last_action" header="Остання дія">
          <template #body="{ data }">{{ data.last_action || '—' }}</template>
        </Column>
        <Column field="lateness_seconds" header="Запізнення" sortable>
          <template #body="{ data }">
            <span :class="{ 'lateness-value': data.lateness_seconds > 0 }">{{ formatDuration(data.lateness_seconds) }}</span>
          </template>
        </Column>
        <Column field="productive_seconds" header="Продуктивно" sortable>
          <template #body="{ data }">{{ formatDuration(data.productive_seconds) }}</template>
        </Column>
        <Column field="unproductive_seconds" header="Непродуктивно" sortable>
          <template #body="{ data }">{{ formatDuration(data.unproductive_seconds) }}</template>
        </Column>
        <Column field="neutral_seconds" header="Нейтрально" sortable>
          <template #body="{ data }">{{ formatDuration(data.neutral_seconds) }}</template>
        </Column>
        <Column field="total_seconds" header="Разом" sortable>
          <template #body="{ data }">
            <strong>{{ formatDuration(data.total_seconds) }}</strong>
          </template>
        </Column>

        <template #expansion="{ data }">
          <div class="activities-box">
            <div v-if="!activitiesByStat[data.id]" class="activities-loading">Завантаження діяльностей…</div>
            <div v-else-if="activitiesByStat[data.id].length === 0" class="activities-loading">
              Розбивки по діяльностях за цей день немає.
            </div>
            <table v-else class="activities-table">
              <thead>
                <tr>
                  <th>Діяльність</th>
                  <th>Категорія</th>
                  <th>Продуктивність</th>
                  <th class="col-duration">Час</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="entry in activitiesByStat[data.id]" :key="entry.id">
                  <td class="col-name">{{ entry.name }}</td>
                  <td>{{ entry.category || '—' }}</td>
                  <td>
                    <span class="productivity-badge" :class="productivityMeta[entry.productivity]?.class">
                      {{ productivityMeta[entry.productivity]?.label || entry.productivity }}
                    </span>
                  </td>
                  <td class="col-duration">{{ formatDurationWithSeconds(entry.duration_seconds) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </template>
      </DataTable>
    </div>
  </div>
</template>

<style scoped>
.history-page {
  display: flex;
  flex-direction: column;
}

.page-message {
  margin-top: 14px;
}

.employee-select {
  min-width: 220px;
}

.totals-row {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
  gap: 12px;
  margin-top: 16px;
}

.total-card {
  background: var(--surface);
  border: 1px solid var(--line);
  border-radius: 0;
  padding: 12px 14px;
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.total-label {
  font-size: 11.5px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--muted);
}

.total-value {
  font-size: 20px;
  font-weight: 700;
  color: var(--accent);
  font-variant-numeric: tabular-nums;
}

.total-card.is-productive .total-value {
  color: #149d8d;
}

.total-card.is-unproductive .total-value {
  color: #d05353;
}

.total-card.is-neutral .total-value {
  color: #8a8f98;
}

.table-panel {
  margin-top: 16px;
  overflow-x: auto;
  animation: fadeUp 0.35s ease both;
}

/* На вузьких екранах таблиця скролиться в межах панелі, а не обрізається */
.table-panel :deep(.p-datatable-table) {
  min-width: 760px;
}

.table-empty {
  padding: 10px 4px;
  font-size: 13.5px;
  color: var(--muted);
}

.date-value {
  white-space: nowrap;
  font-variant-numeric: tabular-nums;
}

.lateness-value {
  color: #d05353;
  font-weight: 600;
}

.activities-box {
  padding: 10px 14px;
}

.activities-loading {
  font-size: 13px;
  color: var(--muted);
  padding: 6px 0;
}

.activities-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
}

.activities-table th {
  text-align: left;
  font-size: 11.5px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--muted);
  padding: 6px 10px;
  border-bottom: 1px solid var(--line);
}

.activities-table td {
  padding: 6px 10px;
  border-bottom: 1px solid var(--line);
  vertical-align: top;
}

.activities-table tr:last-child td {
  border-bottom: none;
}

.col-name {
  word-break: break-word;
  max-width: 480px;
}

.col-duration {
  text-align: right;
  white-space: nowrap;
  font-variant-numeric: tabular-nums;
}

.productivity-badge {
  display: inline-block;
  padding: 2px 8px;
  border-radius: 0;
  font-size: 11.5px;
  font-weight: 600;
  background: var(--line);
  color: var(--muted);
}

.productivity-badge.is-productive {
  background: #d5f2ee;
  color: #0e7d70;
}

.productivity-badge.is-unproductive {
  background: #fbe3e3;
  color: #b33c3c;
}
</style>
