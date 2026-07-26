<script setup>
// Табель (лише адмін): матриця «працівник × дні місяця», у клітинках —
// фактично відпрацьований час із історичної БД, без норм і підсвіток.
import { onMounted, ref, watch } from 'vue';
import DatePicker from 'primevue/datepicker';
import Message from 'primevue/message';
import client from '../api/client';
import { formatDuration } from '../utils/duration';

const selectedMonth = ref(new Date());
const loading = ref(true);
const errorMessage = ref('');
const rows = ref([]);
const days = ref([]);

function monthParam(date) {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`;
}

async function load() {
  loading.value = true;
  errorMessage.value = '';
  try {
    const { data } = await client.get('/timesheet', { params: { month: monthParam(selectedMonth.value) } });
    rows.value = data.data;
    const [year, month] = data.month.split('-').map(Number);
    days.value = Array.from({ length: data.days_in_month }, (_, i) => {
      const weekday = new Date(year, month - 1, i + 1).getDay();
      return {
        day: i + 1,
        iso: `${data.month}-${String(i + 1).padStart(2, '0')}`,
        weekend: weekday === 0 || weekday === 6,
      };
    });
  } catch (error) {
    errorMessage.value = error.response?.data?.message || 'Не вдалося завантажити табель.';
  } finally {
    loading.value = false;
  }
}

watch(selectedMonth, () => {
  if (selectedMonth.value) load();
});

onMounted(load);
</script>

<template>
  <div class="timesheet-page">
    <div class="page-head">
      <div class="page-head-info">
        <div class="page-head-icon">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#149d8d" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line><line x1="9" y1="14" x2="9" y2="22"></line><line x1="15" y1="14" x2="15" y2="22"></line><line x1="3" y1="18" x2="21" y2="18"></line></svg>
        </div>
        <div>
          <div class="page-head-title">Табель</div>
          <div class="page-head-subtitle">Фактично відпрацьований час по працівниках за місяць</div>
        </div>
      </div>
      <div class="page-head-actions">
        <div class="field-pill">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#149d8d" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
          <DatePicker v-model="selectedMonth" view="month" date-format="mm.yy" :manual-input="false" />
        </div>
      </div>
    </div>

    <Message v-if="errorMessage" severity="error" :closable="true" class="page-message" @close="errorMessage = ''">
      {{ errorMessage }}
    </Message>

    <div v-if="loading" class="skeleton sheet-skeleton"></div>

    <div v-else-if="!rows.length" class="panel empty-panel">
      Працівників немає — додайте їх на сторінці «Працівники».
    </div>

    <div v-else class="panel sheet-panel">
      <table class="sheet">
        <thead>
          <tr>
            <th class="col-name">Працівник</th>
            <th v-for="d in days" :key="d.iso" class="col-day" :class="{ 'is-weekend': d.weekend }">{{ d.day }}</th>
            <th class="col-total">Разом</th>
            <th class="col-count">Днів</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in rows" :key="row.id">
            <td class="col-name">{{ row.name }}</td>
            <td
              v-for="d in days"
              :key="d.iso"
              class="col-day cell"
              :class="{ 'is-weekend': d.weekend }"
            >{{ row.days[d.iso] ? formatDuration(row.days[d.iso]) : '' }}</td>
            <td class="col-total">{{ row.total_seconds ? formatDuration(row.total_seconds) : '—' }}</td>
            <td class="col-count">{{ row.days_worked || '—' }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<style scoped>
.timesheet-page {
  display: flex;
  flex-direction: column;
}

.page-message {
  margin-top: 14px;
}

.sheet-skeleton {
  height: 200px;
  margin-top: 16px;
}

.empty-panel {
  margin-top: 16px;
  padding: 20px 16px;
  font-size: 13.5px;
  color: var(--muted);
  text-align: center;
}

.sheet-panel {
  margin-top: 16px;
  overflow-x: auto;
  animation: fadeUp 0.35s ease both;
}

.sheet {
  width: 100%;
  border-collapse: collapse;
  font-size: 12px;
}

.sheet th {
  font-size: 11px;
  font-weight: 600;
  color: var(--muted);
  border-bottom: 1px solid var(--line);
  padding: 8px 4px;
  text-align: center;
  white-space: nowrap;
}

.sheet td {
  border-bottom: 1px solid var(--line);
  padding: 7px 4px;
  text-align: center;
  color: var(--text-dim);
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
}

.sheet tr:last-child td {
  border-bottom: none;
}

.col-name {
  text-align: left !important;
  padding-left: 14px !important;
  padding-right: 14px !important;
  font-size: 13px;
  font-weight: 600;
  color: var(--ink);
  position: sticky;
  left: 0;
  background: var(--surface);
  min-width: 150px;
}

th.col-name {
  color: var(--muted);
  font-weight: 600;
}

.col-day.is-weekend {
  background: #f6f6f6;
}

.cell {
  min-width: 42px;
}

.col-total {
  font-weight: 700;
  color: var(--accent);
  padding-left: 10px !important;
  padding-right: 12px !important;
  border-left: 1px solid var(--line);
  position: sticky;
  right: 52px;
  background: var(--surface);
}

.col-count {
  padding-right: 14px !important;
  color: var(--muted);
  position: sticky;
  right: 0;
  background: var(--surface);
  min-width: 52px;
}

/* На мобільній ширині sticky-колонки з'їдають майже весь екран —
   звужуємо колонку імені й знімаємо липкість підсумків, щоб лишалось
   вікно для днів. Селектор із тегом обов'язковий: інакше `white-space`
   з `.sheet td` перебиває перенос і довге прізвище лізе на клітинки днів. */
@media (max-width: 640px) {
  .sheet td.col-name,
  .sheet th.col-name {
    min-width: 96px;
    max-width: 132px;
    padding-left: 10px !important;
    padding-right: 10px !important;
    white-space: normal;
    overflow-wrap: anywhere;
  }

  .col-total,
  .col-count {
    position: static;
  }
}
</style>
