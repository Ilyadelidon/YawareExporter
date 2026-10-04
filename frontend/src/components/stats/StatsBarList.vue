<script setup>
// Рейтинг горизонтальними смугами: довжина — `value`, праворуч — готовий підпис `display`.
import { computed } from 'vue';

const props = defineProps({
  // [{ key, name, note?, value, display, color, href? }]
  items: { type: Array, required: true },
  empty: { type: String, default: 'Даних за період немає.' },
});

const max = computed(() => Math.max(...props.items.map((item) => item.value), 1));

const rows = computed(() => props.items.map((item) => ({
  ...item,
  width: `${Math.max(1, (item.value / max.value) * 100)}%`,
})));
</script>

<template>
  <div v-if="!items.length" class="bars-empty">{{ empty }}</div>
  <ol v-else class="bars">
    <li v-for="row in rows" :key="row.key" class="bar-row">
      <div class="bar-name" :title="row.name">
        <a v-if="row.href" :href="row.href" target="_blank" rel="noopener">{{ row.name }}</a>
        <template v-else>{{ row.name }}</template>
        <span v-if="row.note" class="bar-note">{{ row.note }}</span>
      </div>
      <div class="bar-track">
        <span class="bar" :style="{ width: row.width, background: row.color }"></span>
      </div>
      <div class="bar-value">{{ row.display }}</div>
    </li>
  </ol>
</template>

<style scoped>
.bars {
  list-style: none;
  margin: 0;
  padding: 4px 0 6px;
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.bar-row {
  display: grid;
  grid-template-columns: minmax(0, 40%) minmax(0, 1fr) auto;
  align-items: center;
  gap: 12px;
  font-size: 13px;
}

.bar-name {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  color: #2b2f33;
}

.bar-name a {
  color: inherit;
  text-decoration: none;
}

.bar-name a:hover {
  color: var(--accent);
  text-decoration: underline;
}

.bar-note {
  margin-left: 6px;
  font-size: 11.5px;
  color: var(--muted);
}

.bar-track {
  height: 12px;
  display: flex;
  align-items: center;
}

/* Рівна основа зліва, заокруглений кінець справа. */
.bar {
  display: block;
  height: 12px;
  border-radius: 0 4px 4px 0;
}

.bar-value {
  font-weight: 600;
  color: #2b2f33;
  font-variant-numeric: tabular-nums;
  text-align: right;
  min-width: 44px;
}

.bars-empty {
  padding: 12px 0;
  font-size: 13px;
  color: var(--muted);
}
</style>
