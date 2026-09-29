<script setup>
// Сітка табеля: працівники × дні місяця, праворуч — підсумки.
import { formatDuration } from '../../utils/duration';

defineProps({
  rows: { type: Array, required: true },
  days: { type: Array, required: true },
});

const cellDuration = (seconds) => (seconds ? formatDuration(seconds) : '');
</script>

<template>
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
        >{{ cellDuration(row.days[d.iso]) }}</td>
        <td class="col-total">{{ cellDuration(row.total_seconds) || '—' }}</td>
        <td class="col-count">{{ row.days_worked || '—' }}</td>
      </tr>
    </tbody>
  </table>
</template>

<style scoped>
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

/* Підсумкові колонки — окремий блок: тонована смуга з жирною лінією
   зліва, щоб очі одразу відділяли їх від сітки днів. */
.col-total,
.col-count {
  background: #eef7f6;
}

.sheet thead .col-total,
.sheet thead .col-count {
  color: var(--accent);
  background: #e3f1ef;
}

.col-total {
  font-weight: 700;
  color: var(--accent);
  padding-left: 10px !important;
  padding-right: 12px !important;
  border-left: 2px solid var(--accent);
  position: sticky;
  right: 52px;
}

.col-count {
  padding-right: 14px !important;
  font-weight: 600;
  color: var(--text-dim);
  position: sticky;
  right: 0;
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
