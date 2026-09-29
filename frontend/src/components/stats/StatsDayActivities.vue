<script setup>
// Розбивка дня по діяльностях — вміст розгорнутого рядка Статистики.
import ProductivityBadge from '../ProductivityBadge.vue';
import { formatDurationWithSeconds } from '../../utils/duration';

defineProps({
  // { entries, error } з useStats; null — ще вантажиться.
  state: { type: Object, default: null },
});
</script>

<template>
  <div class="activities-box">
    <div v-if="!state" class="activities-note">Завантаження діяльностей…</div>
    <div v-else-if="state.error" class="activities-note is-error">{{ state.error }}</div>
    <div v-else-if="!state.entries.length" class="activities-note">
      Розбивки по діяльностях за цей день немає.
    </div>
    <table v-else class="activities-table">
      <thead>
        <tr>
          <th>Діяльність</th>
          <th>Категорія</th>
          <th>Продуктивність</th>
          <th class="col-duration">Час</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="entry in state.entries" :key="entry.id">
          <td class="col-name">{{ entry.name }}</td>
          <td>{{ entry.category || '—' }}</td>
          <td><ProductivityBadge :value="entry.productivity" /></td>
          <td class="col-duration">{{ formatDurationWithSeconds(entry.duration_seconds) }}</td>
        </tr>
      </tbody>
    </table>
  </div>
</template>

<style scoped>
.activities-box {
  padding: 10px 14px;
}

.activities-note {
  font-size: 13px;
  color: var(--muted);
  padding: 6px 0;
}

.activities-note.is-error {
  color: #b33c3c;
}

.activities-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
}

.activities-table th {
  text-align: left;
  font-size: 11.5px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--muted);
  padding: 6px 10px;
  border-bottom: 1px solid var(--line);
}

.activities-table td {
  padding: 6px 10px;
  border-bottom: 1px solid var(--line);
  vertical-align: top;
}

.activities-table tr:last-child td {
  border-bottom: none;
}

.col-name {
  word-break: break-word;
  max-width: 480px;
}

.col-duration {
  text-align: right;
  white-space: nowrap;
  font-variant-numeric: tabular-nums;
}
</style>
