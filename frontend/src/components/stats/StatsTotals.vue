<script setup>
import { computed } from 'vue';
import { formatDuration } from '../../utils/duration';

const props = defineProps({
  totals: { type: Object, required: true },
  loading: { type: Boolean, default: false },
});

const cards = computed(() => [
  { key: 'days', label: 'Днів у вибірці', count: true },
  { key: 'work_seconds', label: 'Загальний робочий час' },
  { key: 'productive_seconds', label: 'Продуктивно', class: 'is-productive' },
  { key: 'neutral_seconds', label: 'Нейтрально', class: 'is-neutral' },
  { key: 'unproductive_seconds', label: 'Непродуктивно', class: 'is-bad' },
  { key: 'lateness_seconds', label: 'Запізнення разом', class: 'is-bad' },
].map((card) => ({
  ...card,
  value: card.count ? props.totals[card.key] : formatDuration(props.totals[card.key]),
})));
</script>

<template>
  <div class="chart-card panel" :class="{ 'is-loading': loading }">
    <div class="chart-head">
      <div class="chart-title">Підсумки періоду</div>
    </div>

    <dl class="totals-list">
      <div v-for="card in cards" :key="card.key" class="total-row" :class="card.class">
        <dt class="total-label">{{ card.label }}</dt>
        <dd class="total-value">{{ card.value }}</dd>
      </div>
    </dl>
  </div>
</template>

<style scoped src="./chart.css"></style>
<style scoped>
.totals-list {
  margin: 0;
}

.total-row {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  align-items: baseline;
  gap: 12px;
  padding: 9px 0;
  border-bottom: 1px solid var(--line);
}

.total-row:last-child {
  border-bottom: 0;
}

.total-label {
  font-size: 13px;
  color: var(--text-dim);
}

.total-value {
  margin: 0;
  font-size: 17px;
  font-weight: 700;
  color: var(--accent);
  font-variant-numeric: tabular-nums;
  text-align: right;
}

.total-row.is-productive .total-value {
  color: #149d8d;
}

.total-row.is-bad .total-value {
  color: #d05353;
}

.total-row.is-neutral .total-value {
  color: #8a8f98;
}
</style>
