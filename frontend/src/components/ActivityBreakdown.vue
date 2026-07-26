<script setup>
// Повна розбивка активності за день звіту: таблиця діяльностей з історичної БД
// (activity_entries) + офлайн активність зі знімка daily_stats.idle_activities.
import { computed, ref, watch } from 'vue';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import Message from 'primevue/message';
import client from '../api/client';
import { formatDurationWithSeconds } from '../utils/duration';

const props = defineProps({
  employeeId: { type: Number, required: true },
  date: { type: String, required: true },
  // generated_at звіту: змінюється після перегенерації — тригер перезавантаження
  version: { type: String, default: '' },
});

const loading = ref(true);
const errorMessage = ref('');
const entries = ref([]);
const dailyStat = ref(null);
const productivityFilter = ref(null);

const productivityMeta = {
  productive: { label: 'Продуктивно', class: 'is-productive' },
  unproductive: { label: 'Непродуктивно', class: 'is-unproductive' },
  neutral: { label: 'Нейтрально', class: 'is-neutral' },
};

const filterOptions = computed(() => {
  const counts = { productive: 0, unproductive: 0, neutral: 0 };
  entries.value.forEach((entry) => {
    if (counts[entry.productivity] !== undefined) {
      counts[entry.productivity] += 1;
    }
  });
  return [
    { value: null, label: 'Усі', count: entries.value.length },
    ...Object.entries(productivityMeta)
      .filter(([value]) => counts[value] > 0)
      .map(([value, meta]) => ({ value, label: meta.label, count: counts[value] })),
  ];
});

const filteredEntries = computed(() => entries.value.filter(
  (entry) => !productivityFilter.value || entry.productivity === productivityFilter.value,
));

function activitiesWord(n) {
  const mod10 = n % 10;
  const mod100 = n % 100;
  if (mod100 >= 11 && mod100 <= 14) return 'діяльностей';
  if (mod10 === 1) return 'діяльність';
  if (mod10 >= 2 && mod10 <= 4) return 'діяльності';
  return 'діяльностей';
}

const headNote = computed(() => {
  if (loading.value || errorMessage.value || !entries.value.length) {
    return '';
  }
  return `${entries.value.length} ${activitiesWord(entries.value.length)}`;
});

// idle_activities — знімок таблиці «Офлайн активність» з Yaware: перший рядок
// заголовки, решта дані; порожні колонки прибираємо.
const idleTable = computed(() => {
  const rows = dailyStat.value?.idle_activities;
  if (!Array.isArray(rows) || rows.length < 2) {
    return null;
  }
  const width = Math.max(...rows.map((row) => row.length));
  const usedColumns = Array.from({ length: width }, (_, i) => i)
    .filter((i) => rows.some((row) => String(row[i] ?? '').trim() !== ''));
  const pick = (row) => usedColumns.map((i) => String(row[i] ?? ''));
  return { header: pick(rows[0]), rows: rows.slice(1).map(pick) };
});

async function load() {
  loading.value = true;
  errorMessage.value = '';
  productivityFilter.value = null;
  try {
    const [activitiesResponse, statsResponse] = await Promise.all([
      client.get('/stats/activities', {
        params: { employee_id: props.employeeId, date: props.date },
      }),
      client.get('/stats', {
        params: { date_from: props.date, date_to: props.date, employee_id: props.employeeId },
      }),
    ]);
    entries.value = activitiesResponse.data.data;
    dailyStat.value = statsResponse.data.data?.[0] || null;
  } catch (error) {
    entries.value = [];
    dailyStat.value = null;
    errorMessage.value = error.response?.data?.message || 'Не вдалося завантажити розбивку активності.';
  } finally {
    loading.value = false;
  }
}

watch(() => [props.employeeId, props.date, props.version], load, { immediate: true });
</script>

<template>
  <section class="activity-section">
    <div class="section-head">
      <div class="section-head-titles">
        <h2>Активність за день</h2>
        <span v-if="headNote" class="section-note">{{ headNote }}</span>
      </div>
      <div v-if="!loading && entries.length" class="filter-chips" role="group" aria-label="Фільтр за продуктивністю">
        <button
          v-for="option in filterOptions"
          :key="option.label"
          type="button"
          class="filter-chip"
          :class="{ 'is-active': productivityFilter === option.value }"
          @click="productivityFilter = option.value"
        >
          {{ option.label }}
          <span class="filter-chip-count">{{ option.count }}</span>
        </button>
      </div>
    </div>

    <div v-if="loading" class="skeleton activity-skeleton"></div>

    <Message v-else-if="errorMessage" severity="warn" :closable="false">
      {{ errorMessage }}
    </Message>

    <div v-else-if="!entries.length" class="panel empty-panel">
      Розбивки активності за цей день немає в історичній базі. Вона наповнюється
      під час генерації — сформуйте звіт ще раз, щоб зʼявились дані.
    </div>

    <div v-else class="panel table-panel">
      <DataTable
        :value="filteredEntries"
        paginator
        :rows="15"
        :always-show-paginator="false"
        sort-field="duration_seconds"
        :sort-order="-1"
        data-key="id"
      >
        <template #empty>
          <div class="table-empty">Нічого не знайдено за цим фільтром.</div>
        </template>
        <Column field="name" header="Діяльність" sortable>
          <template #body="{ data }">
            <span class="entry-name">{{ data.name }}</span>
          </template>
        </Column>
        <Column field="category" header="Категорія" sortable>
          <template #body="{ data }">{{ data.category || '—' }}</template>
        </Column>
        <Column field="productivity" header="Продуктивність" sortable>
          <template #body="{ data }">
            <span class="productivity-badge" :class="productivityMeta[data.productivity]?.class">
              {{ productivityMeta[data.productivity]?.label || data.productivity }}
            </span>
          </template>
        </Column>
        <Column field="duration_seconds" header="Час" sortable style="width: 96px">
          <template #body="{ data }">
            <span class="duration-value">{{ formatDurationWithSeconds(data.duration_seconds) }}</span>
          </template>
        </Column>
      </DataTable>
    </div>

    <template v-if="!loading && idleTable">
      <div class="section-head sub">
        <div class="section-head-titles">
          <h2>Офлайн активність</h2>
        </div>
      </div>
      <div class="panel idle-panel">
        <table class="idle-table">
          <thead>
            <tr>
              <th v-for="(cell, i) in idleTable.header" :key="i">{{ cell }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(row, r) in idleTable.rows" :key="r">
              <td v-for="(cell, i) in row" :key="i">{{ cell }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </section>
</template>

<style scoped>
.activity-section {
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

.filter-chips {
  display: flex;
}

.filter-chip {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  border: 1px solid var(--line);
  border-right-width: 0;
  background: var(--surface);
  padding: 6px 11px;
  font-family: inherit;
  font-size: 12px;
  font-weight: 600;
  color: var(--text-dim);
  cursor: pointer;
  transition: all 0.15s ease;
}

.filter-chip:last-child {
  border-right-width: 1px;
}

.filter-chip:hover:not(.is-active) {
  background: var(--line);
}

.filter-chip.is-active {
  background: var(--accent);
  border-color: var(--accent);
  color: #fff;
}

.filter-chip-count {
  font-size: 11px;
  font-weight: 700;
  color: var(--muted);
  font-variant-numeric: tabular-nums;
}

.filter-chip.is-active .filter-chip-count {
  color: #b8e6e0;
}

.activity-skeleton {
  height: 260px;
}

.table-panel {
  overflow-x: auto;
}

/* На вузьких екранах таблиця скролиться в межах панелі, а не обрізається */
.table-panel :deep(.p-datatable-table) {
  min-width: 560px;
}

.table-empty,
.empty-panel {
  padding: 20px 16px;
  font-size: 13.5px;
  color: var(--muted);
}

.empty-panel {
  text-align: center;
}

.entry-name {
  word-break: break-word;
}

.duration-value {
  display: block;
  text-align: right;
  white-space: nowrap;
  font-variant-numeric: tabular-nums;
  font-weight: 600;
  color: var(--ink);
}

.productivity-badge {
  display: inline-block;
  padding: 2px 8px;
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

.idle-panel {
  overflow-x: auto;
}

.idle-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
}

.idle-table th {
  text-align: left;
  font-size: 11.5px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--muted);
  padding: 8px 12px;
  border-bottom: 1px solid var(--line);
  white-space: nowrap;
}

.idle-table td {
  padding: 7px 12px;
  border-bottom: 1px solid var(--line);
  color: var(--text-dim);
  vertical-align: top;
}

.idle-table tr:last-child td {
  border-bottom: none;
}

/* Сегментований ряд чипів не вміщається на мобільній ширині — переносимо
   рядами; після переносу спільні межі втрачають сенс, кожен чип зі своєю. */
@media (max-width: 640px) {
  .filter-chips {
    flex-wrap: wrap;
    gap: 6px;
  }

  .filter-chip,
  .filter-chip:last-child {
    border-right-width: 1px;
  }
}
</style>
