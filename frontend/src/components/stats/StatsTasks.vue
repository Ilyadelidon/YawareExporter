<script setup>
// Таски за період зі звітів: скільки їх було, скільки часу на них пішло і на які
// пішло найбільше.
import { computed } from 'vue';
import StatsBarList from './StatsBarList.vue';
import { CHART_SERIES } from './chartColors';
import { formatDuration } from '../../utils/duration';

const props = defineProps({
  // { total, seconds, top: [{ name, url, seconds, days, employees }] }
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
      <div class="part-title">Найбільше часу на таски</div>
      <StatsBarList :items="items" empty="У тасок за період немає часу." />
    </template>
    <div v-else class="figures-empty">За період у звітах немає тасок.</div>
  </div>
</template>

<style scoped src="./chart.css"></style>
<style scoped src="./figures.css"></style>
