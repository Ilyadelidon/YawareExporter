<script setup>
// Таски за період зі звітів: скільки їх було і скільки часу на них пішло, у якій
// колонці трекера кожна зараз і на які пішло найбільше часу.
import { computed } from 'vue';
import StatsBarList from './StatsBarList.vue';
import { CHART_SERIES } from './chartColors';
import { formatDuration } from '../../utils/duration';

const props = defineProps({
  // { total, seconds, lists: [{ list, count }], top: [{ name, url, list, seconds, days, employees }] }
  tasks: { type: Object, required: true },
  // Таски кількох працівників разом — біля назви показуємо, чия вона.
  showEmployee: { type: Boolean, default: false },
  loading: { type: Boolean, default: false },
});

const items = computed(() => props.tasks.top.map((task, i) => ({
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
      <div class="chart-title">Таски за період</div>
    </div>

    <template v-if="tasks.total">
      <div class="figures">
        <div class="figure">
          <span class="figure-value">{{ tasks.total }}</span>
          <span class="figure-label">тасок у звітах</span>
        </div>
        <div class="figure">
          <span class="figure-value">{{ formatDuration(tasks.seconds) || '0:00' }}</span>
          <span class="figure-label">на таски разом</span>
        </div>
      </div>
      <ul v-if="tasks.lists.length" class="statuses" aria-label="Таски за колонкою трекера">
        <li v-for="row in tasks.lists" :key="row.list">
          <span class="status-count">{{ row.count }}</span>
          {{ row.list }}
        </li>
      </ul>
      <div class="part-title">Найбільше часу на таски</div>
      <StatsBarList :items="items" empty="У тасок за період немає часу." />
    </template>
    <div v-else class="tasks-empty">За період у звітах немає тасок.</div>
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

.tasks-empty {
  padding: 18px 0;
  font-size: 13px;
  color: var(--muted);
}
</style>
