<script setup>
// Час по днях: стовпчик на день, складений із продуктивного, нейтрального й непродуктивного.
import { computed, ref } from 'vue';
import { useElementWidth } from '../../composables/useElementWidth';
import { labelEvery, niceTicks } from '../../utils/statsCharts';
import { formatDottedDate } from '../../utils/dates';
import { formatDuration } from '../../utils/duration';
import { CHART_SERIES } from './chartColors';

const props = defineProps({
  // dailySeries(): [{ date, productive, neutral, unproductive }] у секундах
  days: { type: Array, required: true },
  loading: { type: Boolean, default: false },
});

const HEIGHT = 220;
const PAD = { top: 10, right: 8, bottom: 26, left: 44 };
const GAP = 2;
const RADIUS = 4;

// Порядок знизу вгору: продуктивне — основа стовпчика.
const SERIES = ['productive', 'neutral', 'unproductive'].map((key) => ({ key, ...CHART_SERIES[key] }));

const box = ref(null);
const width = useElementWidth(box);
const hovered = ref(null);

const plotWidth = computed(() => Math.max(0, width.value - PAD.left - PAD.right));
const plotHeight = HEIGHT - PAD.top - PAD.bottom;

const ticks = computed(() => {
  const maxHours = Math.max(...props.days.map((d) => (d.productive + d.neutral + d.unproductive) / 3600), 0);
  return niceTicks(maxHours);
});
const yMax = computed(() => ticks.value[ticks.value.length - 1] * 3600);
const y = (seconds) => PAD.top + plotHeight - (seconds / yMax.value) * plotHeight;

const band = computed(() => plotWidth.value / Math.max(1, props.days.length));
const barWidth = computed(() => Math.max(2, Math.min(24, band.value - 4)));
const xCenter = (i) => PAD.left + band.value * (i + 0.5);

// Верхній кут заокруглений, біля основи — прямий.
function topRounded(x, top, w, h, r) {
  const rr = Math.min(r, w / 2, h);
  return `M${x},${top + h}V${top + rr}Q${x},${top} ${x + rr},${top}H${x + w - rr}Q${x + w},${top} ${x + w},${top + rr}V${top + h}Z`;
}

const columns = computed(() => props.days.map((day, i) => {
  const x = xCenter(i) - barWidth.value / 2;
  const visible = SERIES.filter((s) => day[s.key] > 0);
  let cursor = 0;
  const segments = visible.map((s, idx) => {
    const bottom = y(cursor);
    cursor += day[s.key];
    const isTop = idx === visible.length - 1;
    // Проміжок 2px кольору фону відділяє сегменти; верхній сегмент його не має.
    const top = y(cursor) + (isTop ? 0 : GAP);
    const h = Math.max(0, bottom - top);
    return {
      key: s.key,
      color: s.color,
      d: isTop ? topRounded(x, top, barWidth.value, h, RADIUS) : `M${x},${top}h${barWidth.value}v${h}h${-barWidth.value}Z`,
    };
  });
  return { day, i, segments, total: cursor };
}));

const every = computed(() => labelEvery(props.days.length, plotWidth.value));
const xLabels = computed(() => props.days
  .map((day, i) => ({ i, text: formatDottedDate(day.date).slice(0, 5) }))
  .filter(({ i }) => i % every.value === 0));

const tooltip = computed(() => {
  if (hovered.value === null) return null;
  const col = columns.value[hovered.value];
  if (!col) return null;
  const cx = xCenter(col.i);
  const onRight = cx < width.value / 2;
  const offset = barWidth.value / 2 + 10;
  return {
    style: { left: `${cx + (onRight ? offset : -offset)}px`, transform: onRight ? 'none' : 'translateX(-100%)' },
    date: formatDottedDate(col.day.date),
    total: col.total,
    rows: [...SERIES].reverse().map((s) => ({ ...s, value: col.day[s.key] })),
  };
});
</script>

<template>
  <div class="chart-card panel" :class="{ 'is-loading': loading }">
    <div class="chart-head">
      <div class="chart-title">Час по днях</div>
      <ul class="chart-legend">
        <li v-for="s in SERIES" :key="s.key"><span class="swatch" :style="{ background: s.color }"></span>{{ s.label }}</li>
      </ul>
    </div>

    <div ref="box" class="chart-box" @pointerleave="hovered = null">
      <svg v-if="width" :width="width" :height="HEIGHT" role="img" aria-label="Продуктивний, нейтральний і непродуктивний час по днях">
        <g class="grid">
          <template v-for="t in ticks" :key="t">
            <line :x1="PAD.left" :x2="width - PAD.right" :y1="y(t * 3600)" :y2="y(t * 3600)" />
            <text :x="PAD.left - 8" :y="y(t * 3600)" text-anchor="end" dominant-baseline="middle">{{ t }} год</text>
          </template>
        </g>

        <g v-for="col in columns" :key="col.day.date" :class="{ 'is-dim': hovered !== null && hovered !== col.i }">
          <path v-for="seg in col.segments" :key="seg.key" :d="seg.d" :fill="seg.color" />
        </g>

        <g class="axis-x">
          <text v-for="l in xLabels" :key="l.i" :x="xCenter(l.i)" :y="HEIGHT - 8" text-anchor="middle">{{ l.text }}</text>
        </g>

        <!-- Зона наведення — уся смуга дня, а не лише пофарбовані пікселі. -->
        <rect
          v-for="col in columns"
          :key="`hit-${col.day.date}`"
          class="hit"
          :x="PAD.left + band * col.i"
          :y="PAD.top"
          :width="band"
          :height="plotHeight"
          tabindex="0"
          :aria-label="`${formatDottedDate(col.day.date)}: разом ${formatDuration(col.total)}`"
          @pointerenter="hovered = col.i"
          @focus="hovered = col.i"
          @blur="hovered = null"
        />
      </svg>

      <div v-if="tooltip" class="chart-tooltip" :style="tooltip.style">
        <div class="tt-date">{{ tooltip.date }}</div>
        <div v-if="!tooltip.total" class="tt-empty">Даних немає</div>
        <template v-else>
          <div v-for="row in tooltip.rows" :key="row.key" class="tt-row">
            <span class="tt-key" :style="{ background: row.color }"></span>
            <strong>{{ formatDuration(row.value) }}</strong>
            <span class="tt-label">{{ row.label }}</span>
          </div>
          <div class="tt-row tt-total"><strong>{{ formatDuration(tooltip.total) }}</strong><span class="tt-label">разом</span></div>
        </template>
      </div>
    </div>
  </div>
</template>

<style scoped src="./chart.css"></style>
