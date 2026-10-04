<script setup>
// Легенда графіка: кольоровий квадратик і підпис для кожного ряду.
defineProps({
  // [{ key, label, color }]
  series: { type: Array, required: true },
});
</script>

<template>
  <ul class="chart-legend">
    <li v-for="s in series" :key="s.key"><span class="swatch" :style="{ background: s.color }"></span>{{ s.label }}</li>
  </ul>
</template>

<style scoped>
.chart-legend {
  display: flex;
  flex-wrap: wrap;
  gap: 4px 14px;
  margin: 0;
  padding: 0;
  list-style: none;
  font-size: 12px;
  color: var(--text-dim);
}

.chart-legend li {
  display: inline-flex;
  align-items: center;
  gap: 6px;
}

.swatch {
  width: 10px;
  height: 10px;
  border-radius: 2px;
}
</style>
