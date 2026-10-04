<script setup>
// Топ діяльностей за період: колір смуги — продуктивність.
import { computed } from 'vue';
import StatsBarList from './StatsBarList.vue';
import { CHART_SERIES } from './chartColors';
import { formatDuration } from '../../utils/duration';

const props = defineProps({
  // [{ name, category, productivity, seconds }]
  activities: { type: Array, required: true },
  loading: { type: Boolean, default: false },
});

const items = computed(() => props.activities.map((item) => ({
  key: `${item.name}-${item.productivity}`,
  name: item.name,
  note: item.category,
  value: item.seconds,
  display: formatDuration(item.seconds),
  color: (CHART_SERIES[item.productivity] ?? CHART_SERIES.neutral).color,
})));

// У легенді — лише продуктивності, що є в списку, і лише коли їх кілька.
const legend = computed(() => {
  const present = new Set(props.activities.map((item) => item.productivity));
  return Object.entries(CHART_SERIES)
    .filter(([key]) => present.has(key))
    .map(([key, series]) => ({ key, ...series }));
});
</script>

<template>
  <div class="chart-card panel" :class="{ 'is-loading': loading }">
    <div class="chart-head">
      <div class="chart-title">Топ діяльностей</div>
      <ul v-if="legend.length > 1" class="chart-legend">
        <li v-for="s in legend" :key="s.key"><span class="swatch" :style="{ background: s.color }"></span>{{ s.label }}</li>
      </ul>
    </div>
    <StatsBarList :items="items" />
  </div>
</template>

<style scoped src="./chart.css"></style>
