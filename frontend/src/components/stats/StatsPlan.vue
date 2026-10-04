<script setup>
// План за період: стан задач плану зараз, скільки з них були в роботі (відмітки днів у Планах)
// і таски зі звітів, на які пішло найбільше часу.
import { computed } from 'vue';
import StatsBarList from './StatsBarList.vue';
import { CHART_SERIES } from './chartColors';
import { formatDuration } from '../../utils/duration';

const props = defineProps({
  // { total, statuses: [{ status, label, count }], worked_tasks, worked_days }
  plan: { type: Object, required: true },
  // [{ name, url, seconds, days, employees }]
  tasks: { type: Array, default: () => [] },
  // Таски кількох працівників разом — біля назви показуємо, чия вона.
  showEmployee: { type: Boolean, default: false },
  loading: { type: Boolean, default: false },
});

const items = computed(() => props.tasks.map((task, i) => ({
  key: `${i}-${task.url ?? task.name}`,
  name: task.name,
  href: task.url,
  note: [`${task.days} дн.`, props.showEmployee && task.employees.join(', ')].filter(Boolean).join(' · '),
  value: task.seconds,
  display: formatDuration(task.seconds),
  color: CHART_SERIES.productive.color,
})));
</script>

<template>
  <div class="chart-card panel" :class="{ 'is-loading': loading }">
    <div class="chart-head">
      <div class="chart-title">План за період</div>
    </div>

    <template v-if="plan.total || plan.worked_tasks">
      <div class="figures">
        <div class="figure">
          <span class="figure-value">{{ plan.total }}</span>
          <span class="figure-label">задач у плані зараз</span>
        </div>
        <div class="figure">
          <span class="figure-value">{{ plan.worked_tasks }}</span>
          <span class="figure-label">у роботі за період · {{ plan.worked_days }} дн. відміток</span>
        </div>
      </div>
      <ul v-if="plan.statuses.length" class="statuses" aria-label="Задачі плану за статусом">
        <li v-for="row in plan.statuses" :key="row.status">
          <span class="status-count">{{ row.count }}</span>
          {{ row.label }}
        </li>
      </ul>
    </template>
    <div v-else class="plan-empty">Задач у Планах немає.</div>

    <div class="part-title">Найбільше часу на таски</div>
    <StatsBarList :items="items" empty="За період у звітах немає тасок із часом." />
  </div>
</template>

<style scoped src="./chart.css"></style>
<style scoped>
.figures {
  display: flex;
  flex-wrap: wrap;
  gap: 4px 24px;
  margin-bottom: 8px;
}

.figure {
  display: flex;
  align-items: baseline;
  gap: 6px;
}

.figure-value {
  font-size: 22px;
  font-weight: 700;
  color: #2b2f33;
}

.figure-label {
  font-size: 12.5px;
  color: var(--text-dim);
}

.statuses {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin: 0 0 14px;
  padding: 0;
  list-style: none;
}

.statuses li {
  font-size: 12px;
  color: var(--text-dim);
  background: var(--line);
  padding: 3px 8px;
}

.status-count {
  font-weight: 700;
  color: #2b2f33;
  margin-right: 2px;
}

.part-title {
  font-size: 11.5px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--muted);
  margin-bottom: 6px;
}

.plan-empty {
  padding: 2px 0 14px;
  font-size: 13px;
  color: var(--muted);
}
</style>
