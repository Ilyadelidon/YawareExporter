<script setup>
// Задачі з плану за період: над скількома працювали і над якими найбільше — за відмітками
// днів у Планах (часу в Планах немає, тож міра — дні).
import { computed } from 'vue';
import StatsBarList from './StatsBarList.vue';
import { CHART_SERIES } from './chartColors';

const props = defineProps({
  // { total, days, top: [{ name, project, status, days, employee }] }
  tasks: { type: Object, required: true },
  // Задачі кількох працівників разом — біля назви показуємо, чия вона.
  showEmployee: { type: Boolean, default: false },
  loading: { type: Boolean, default: false },
});

const items = computed(() => props.tasks.top.map((task, i) => ({
  key: `${i}-${task.name}`,
  name: task.name,
  note: [task.project, task.status, props.showEmployee && task.employee].filter(Boolean).join(' · '),
  value: task.days,
  display: `${task.days} дн.`,
  color: CHART_SERIES.productive.color,
})));
</script>

<template>
  <div class="chart-card panel" :class="{ 'is-loading': loading }">
    <div class="chart-head">
      <div class="chart-title">Задачі з плану за період</div>
    </div>

    <template v-if="tasks.total">
      <div class="figures">
        <div class="figure">
          <span class="figure-value">{{ tasks.total }}</span>
          <span class="figure-label">задач у роботі</span>
        </div>
        <div class="figure">
          <span class="figure-value">{{ tasks.days }}</span>
          <span class="figure-label">дн. відміток разом</span>
        </div>
      </div>
      <div class="part-title">Найбільше днів у роботі</div>
      <StatsBarList :items="items" />
    </template>
    <div v-else class="figures-empty">За період у Планах немає відміток.</div>
  </div>
</template>

<style scoped src="./chart.css"></style>
<style scoped src="./figures.css"></style>
