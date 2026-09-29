<script setup>
import { computed } from 'vue';
import { formatDuration } from '../../utils/duration';

const props = defineProps({
  totals: { type: Object, required: true },
});

const cards = computed(() => [
  { label: 'Днів у вибірці', value: props.totals.days },
  { label: 'Загальний робочий час', value: formatDuration(props.totals.work_seconds) },
  { label: 'Продуктивно', value: formatDuration(props.totals.productive_seconds), class: 'is-productive' },
  { label: 'Непродуктивно', value: formatDuration(props.totals.unproductive_seconds), class: 'is-unproductive' },
  { label: 'Нейтрально', value: formatDuration(props.totals.neutral_seconds), class: 'is-neutral' },
  { label: 'Запізнення разом', value: formatDuration(props.totals.lateness_seconds) },
]);
</script>

<template>
  <div class="totals-row">
    <div v-for="card in cards" :key="card.label" class="total-card" :class="card.class">
      <span class="total-label">{{ card.label }}</span>
      <span class="total-value">{{ card.value }}</span>
    </div>
  </div>
</template>

<style scoped>
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
</style>
