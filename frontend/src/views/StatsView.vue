<script setup>
import { computed, onMounted, ref } from 'vue';
import DatePicker from 'primevue/datepicker';
import Select from 'primevue/select';
import Message from 'primevue/message';
import client from '../api/client';
import UiIcon from '../components/UiIcon.vue';
import SegmentedControl from '../components/integrations/SegmentedControl.vue';
import StatsTotals from '../components/stats/StatsTotals.vue';
import StatsDailyChart from '../components/stats/StatsDailyChart.vue';
import StatsTopActivities from '../components/stats/StatsTopActivities.vue';
import StatsTasks from '../components/stats/StatsTasks.vue';
import { dailySeries } from '../utils/statsCharts';
import { matchPreset, periodPresets } from '../utils/statsPresets';
import { useStats } from '../composables/useStats';
import { useAuthStore } from '../stores/auth';
import { toIsoDate } from '../utils/dates';

const auth = useAuthStore();
const { stats, totals, period, topActivities, tasks, loading, error, load } = useStats();

const today = new Date();
const dateFrom = ref(new Date(today.getFullYear(), today.getMonth(), 1));
const dateTo = ref(today);
const selectedEmployee = ref(null);
const employees = ref([]);

const PRESETS = periodPresets(today);
// Підсвічуємо пресет, лише поки дати збігаються з ним.
const activePreset = computed(() => matchPreset(PRESETS, dateFrom.value, dateTo.value));

function applyPreset(value) {
  const preset = PRESETS.find((p) => p.value === value);
  dateFrom.value = preset.from;
  dateTo.value = preset.to;
  loadStats();
}

const hasData = computed(() => Boolean(period.value && stats.value.length));
const dailyChart = computed(() => (period.value
  ? dailySeries(stats.value, period.value.date_from, period.value.date_to)
  : []));

async function loadStats() {
  if (!dateFrom.value || !dateTo.value) {
    return;
  }
  const params = { date_from: toIsoDate(dateFrom.value), date_to: toIsoDate(dateTo.value) };
  if (auth.isAdmin && selectedEmployee.value) {
    params.employee_id = selectedEmployee.value;
  }
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
  <div class="stats-page">
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

    <div class="presets-row">
      <SegmentedControl
        :model-value="activePreset"
        :options="PRESETS"
        :disabled="loading"
        aria-label="Швидкий вибір періоду"
        @update:model-value="applyPreset"
      />
    </div>

    <div v-if="hasData" class="charts-row">
      <StatsDailyChart :days="dailyChart" :loading="loading" />
      <StatsTotals v-if="totals" :totals="totals" :loading="loading" />
    </div>

    <div v-if="hasData" class="charts-row is-even">
      <StatsTopActivities :activities="topActivities" :loading="loading" />
      <StatsTasks v-if="tasks" :tasks="tasks" :show-employee="auth.isAdmin && !selectedEmployee" :loading="loading" />
    </div>

    <div v-if="period && !hasData && !loading" class="panel empty-panel">
      За обраний період даних немає. Статистика наповнюється під час генерації звітів — сформуйте звіт за потрібний день на вкладці «Звіти».
    </div>
  </div>
</template>

<style scoped>
.stats-page {
  display: flex;
  flex-direction: column;
}

.page-message {
  margin-top: 14px;
}

.presets-row {
  margin-top: 16px;
  display: flex;
  flex-wrap: wrap;
}

.charts-row {
  display: grid;
  grid-template-columns: minmax(0, 3fr) minmax(0, 2fr);
  gap: 12px;
  margin-top: 16px;
  animation: fadeUp 0.35s ease both;
}

.charts-row.is-even {
  grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
}

@media (max-width: 1100px) {
  .charts-row,
  .charts-row.is-even {
    grid-template-columns: minmax(0, 1fr);
  }
}

.employee-select {
  min-width: 220px;
}

.empty-panel {
  margin-top: 16px;
  padding: 14px 16px;
  font-size: 13.5px;
  color: var(--muted);
}
</style>
