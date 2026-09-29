<script setup>
import { onMounted, ref } from 'vue';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import DatePicker from 'primevue/datepicker';
import Select from 'primevue/select';
import Message from 'primevue/message';
import client from '../api/client';
import UiIcon from '../components/UiIcon.vue';
import StatsTotals from '../components/stats/StatsTotals.vue';
import StatsDayActivities from '../components/stats/StatsDayActivities.vue';
import { useStats } from '../composables/useStats';
import { useAuthStore } from '../stores/auth';
import { toIsoDate } from '../utils/dates';
import { formatDuration } from '../utils/duration';

const auth = useAuthStore();
const { stats, totals, loading, error, activities, load, loadActivities } = useStats();

const today = new Date();
const dateFrom = ref(new Date(today.getFullYear(), today.getMonth(), 1));
const dateTo = ref(today);
const selectedEmployee = ref(null);
const employees = ref([]);
const expandedRows = ref({});

// Колонки тривалостей: однаковий формат, відрізняються лише полем і заголовком.
const durationColumns = [
  { field: 'productive_seconds', header: 'Продуктивно' },
  { field: 'unproductive_seconds', header: 'Непродуктивно' },
  { field: 'neutral_seconds', header: 'Нейтрально' },
];

async function loadStats() {
  if (!dateFrom.value || !dateTo.value) {
    return;
  }
  const params = { date_from: toIsoDate(dateFrom.value), date_to: toIsoDate(dateTo.value) };
  if (auth.isAdmin && selectedEmployee.value) {
    params.employee_id = selectedEmployee.value;
  }
  expandedRows.value = {};
  await load(params);
}

async function loadEmployees() {
  if (!auth.isAdmin) {
    return;
  }
  const { data } = await client.get('/employees');
  employees.value = data.data;
}

onMounted(() => Promise.all([loadStats(), loadEmployees()]));
</script>

<template>
  <div class="history-page">
    <div class="page-head">
      <div class="page-head-info">
        <div class="page-head-icon">
          <UiIcon name="clock" :size="20" color="var(--accent)" />
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
          <UiIcon name="calendar" :size="15" color="var(--accent)" />
          <DatePicker v-model="dateFrom" date-format="dd.mm.yy" :manual-input="false" select-other-months />
        </div>
        <div class="field-pill">
          <UiIcon name="calendar" :size="15" color="var(--accent)" />
          <DatePicker v-model="dateTo" date-format="dd.mm.yy" :manual-input="false" select-other-months />
        </div>
        <button class="btn-accent" type="button" :disabled="loading" @click="loadStats">
          <UiIcon name="search" :stroke-width="2.5" />
          Показати
        </button>
      </div>
    </div>

    <Message v-if="error" severity="error" :closable="true" class="page-message" @close="error = ''">
      {{ error }}
    </Message>

    <StatsTotals v-if="totals" :totals="totals" />

    <div class="panel table-panel">
      <DataTable
        v-model:expanded-rows="expandedRows"
        :value="stats"
        :loading="loading"
        paginator
        :rows="31"
        data-key="id"
        @row-expand="loadActivities($event.data)"
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
        <Column v-for="col in durationColumns" :key="col.field" :field="col.field" :header="col.header" sortable>
          <template #body="{ data }">{{ formatDuration(data[col.field]) }}</template>
        </Column>
        <Column field="total_seconds" header="Разом" sortable>
          <template #body="{ data }">
            <strong>{{ formatDuration(data.total_seconds) }}</strong>
          </template>
        </Column>

        <template #expansion="{ data }">
          <StatsDayActivities :state="activities[data.id]" />
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
</style>
