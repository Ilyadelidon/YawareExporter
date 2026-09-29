<script setup>
import { computed } from 'vue';
import { SUMMARY_KEYS, TIME_KEYS } from '../../constants/report';
import { parseClock } from '../../utils/duration';

// Підсумок звіту від воркера: смуга часу, розподіл продуктивності і решта
// рядків summary (разом зі службовими — посилання на таблицю, попередження).
const props = defineProps({
  summary: { type: Object, default: null },
});

const timeStrip = computed(() => {
  const summary = props.summary || {};
  return Object.fromEntries(
    Object.entries(TIME_KEYS).map(([name, key]) => [name, summary[key] || '—']),
  );
});

const distribution = computed(() => {
  const summary = props.summary || {};
  const productive = parseClock(summary[TIME_KEYS.productive]) || 0;
  const neutral = parseClock(summary[TIME_KEYS.neutral]) || 0;
  const unproductive = parseClock(summary[TIME_KEYS.unproductive]) || 0;
  const sum = productive + neutral + unproductive;
  if (!sum) return null;
  const productivePct = Math.round((productive / sum) * 100);
  const neutralPct = Math.round((neutral / sum) * 100);
  return {
    productive: productivePct,
    neutral: neutralPct,
    unproductive: 100 - productivePct - neutralPct,
  };
});

const detailRows = computed(() => {
  if (!props.summary) return [];
  const stripKeys = new Set(Object.values(TIME_KEYS));
  return Object.entries(props.summary)
    .filter(([key]) => !stripKeys.has(key))
    .map(([label, value]) => ({
      label,
      value: value === '' || value === null ? '—' : value,
      isSheetLink: label === SUMMARY_KEYS.googleSheet && typeof value === 'string' && value.startsWith('https://'),
      isWarning: label === SUMMARY_KEYS.warnings,
    }));
});
</script>

<template>
  <div v-if="!summary" class="panel empty-panel">
    Дані звіту ще не сформовано.
  </div>

  <template v-else>
    <div class="time-strip">
      <div class="time-card">
        <div class="time-card-label">Продуктивно</div>
        <div class="time-card-value accent">{{ timeStrip.productive }}</div>
      </div>
      <div class="time-card">
        <div class="time-card-label">Нейтрально</div>
        <div class="time-card-value dim">{{ timeStrip.neutral }}</div>
      </div>
      <div class="time-card">
        <div class="time-card-label">Непродуктивно</div>
        <div class="time-card-value muted">{{ timeStrip.unproductive }}</div>
      </div>
      <div class="time-card total">
        <div class="time-card-label">Загальний час</div>
        <div class="time-card-value">{{ timeStrip.total }}</div>
      </div>
    </div>

    <div v-if="distribution" class="panel distribution">
      <div class="distribution-bar">
        <div class="seg-productive" :style="{ width: distribution.productive + '%' }"></div>
        <div class="seg-neutral" :style="{ width: distribution.neutral + '%' }"></div>
        <div class="seg-unproductive" :style="{ width: distribution.unproductive + '%' }"></div>
      </div>
      <div class="distribution-legend">
        <div class="legend-item"><span class="legend-dot seg-productive"></span>Продуктивно · {{ distribution.productive }}%</div>
        <div class="legend-item"><span class="legend-dot seg-neutral"></span>Нейтрально · {{ distribution.neutral }}%</div>
        <div class="legend-item"><span class="legend-dot seg-unproductive outlined"></span>Непродуктивно · {{ distribution.unproductive }}%</div>
      </div>
    </div>

    <div v-if="detailRows.length" class="panel detail-rows">
      <div v-for="row in detailRows" :key="row.label" class="detail-row">
        <span class="detail-label">{{ row.label }}</span>
        <span class="detail-value" :class="{ 'is-warning': row.isWarning }">
          <a v-if="row.isSheetLink" :href="row.value" target="_blank" rel="noopener">Відкрити вкладку звіту ↗</a>
          <template v-else>{{ row.value }}</template>
        </span>
      </div>
    </div>
  </template>
</template>

<style scoped>
.time-strip {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 10px;
  margin-bottom: 10px;
}

@media (max-width: 560px) {
  .time-strip {
    grid-template-columns: repeat(2, 1fr);
  }
}

.time-card {
  background: var(--surface);
  border: 1px solid var(--line);
  border-radius: var(--radius);
  padding: 11px 12px;
  box-shadow: var(--shadow-sm);
}

.time-card-label {
  font-size: 11px;
  font-weight: 600;
  color: var(--muted);
  margin-bottom: 4px;
}

.time-card-value {
  font-size: 16.5px;
  font-weight: 800;
  font-variant-numeric: tabular-nums;
}

.time-card-value.accent { color: var(--accent); }
.time-card-value.dim { color: var(--text-dim); }
.time-card-value.muted { color: var(--muted-2); }

.time-card.total {
  background: var(--accent);
  border-color: var(--accent);
  box-shadow: 0 2px 6px rgba(17, 17, 17, 0.18);
}

.time-card.total .time-card-label {
  color: #b8e6e0;
}

.time-card.total .time-card-value {
  color: #fff;
}

.distribution {
  padding: 14px 16px 12px;
  margin-bottom: 10px;
}

.distribution-bar {
  display: flex;
  height: 8px;
  border-radius: 0;
  overflow: hidden;
  margin-bottom: 10px;
}

.seg-productive { background: var(--accent); }
.seg-neutral { background: #c4c4c4; }
.seg-unproductive { background: var(--line); }

.distribution-legend {
  display: flex;
  gap: 18px;
  flex-wrap: wrap;
}

.legend-item {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  color: var(--text-dim);
}

.legend-dot {
  width: 7px;
  height: 7px;
  border-radius: 0;
}

.legend-dot.outlined {
  border: 1px solid #d8d8d8;
}

.detail-rows {
  overflow: hidden;
}

.detail-row {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 16px;
  padding: 8px 16px;
  border-bottom: 1px solid var(--line);
}

.detail-row:last-child {
  border-bottom: none;
}

.detail-label {
  font-size: 12.5px;
  color: var(--muted);
  font-weight: 500;
  flex-shrink: 0;
}

.detail-value {
  font-size: 12.5px;
  color: var(--ink);
  font-weight: 600;
  text-align: right;
  white-space: pre-wrap;
  word-break: break-word;
}

/* Попередження — те, що в звіті пішло не так; вирізняємо від статистики */
.detail-value.is-warning {
  color: #b26a00;
  font-weight: 500;
}

.empty-panel {
  padding: 20px 16px;
  font-size: 13.5px;
  color: var(--muted);
  text-align: center;
}
</style>
