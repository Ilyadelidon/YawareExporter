<script setup>
// Один рядок інтеграції. Усі інтеграції на сторінці мають однакову будову:
// іконка · назва й деталі · статус (вирівняна колонка) · одна дія праворуч,
// а налаштування розгортаються під самим рядком. Повідомлення про результат
// дії показується теж тут, а не десь унизу сторінки.
defineProps({
  id: { type: String, required: true },
  name: { type: String, required: true },
  meta: { type: String, default: '' },
  // { tone: 'ok' | 'action' | 'error' | 'off', label: string }
  status: { type: Object, required: true },
  tag: { type: String, default: '' },
  open: { type: Boolean, default: false },
  // { tone: 'ok' | 'error', text: string } | null
  notice: { type: Object, default: null },
});
</script>

<template>
  <div class="int-row" :class="{ 'is-open': open }">
    <div class="int-head">
      <div class="int-icon" aria-hidden="true"><slot name="icon" /></div>

      <div class="int-info">
        <div class="int-title">
          <span class="int-name">{{ name }}</span>
          <span v-if="tag" class="int-tag">{{ tag }}</span>
        </div>
        <div v-if="meta" class="int-meta" :title="meta">{{ meta }}</div>
      </div>

      <span class="int-status" :class="`is-${status.tone}`">
        <span class="int-status-dot"></span>{{ status.label }}
      </span>

      <div class="int-action"><slot name="action" /></div>
    </div>

    <div
      v-if="notice"
      class="int-notice"
      :class="`is-${notice.tone}`"
      :role="notice.tone === 'error' ? 'alert' : 'status'"
    >
      {{ notice.text }}
    </div>

    <div v-if="open && $slots.default" :id="`int-panel-${id}`" class="int-panel">
      <slot />
    </div>
  </div>
</template>

<style scoped>
@keyframes panelIn {
  from { opacity: 0; transform: translateY(-4px); }
  to { opacity: 1; transform: translateY(0); }
}

.int-row + .int-row {
  border-top: 1px solid var(--line);
}

/* Фіксовані ширини колонок статусу й дії — статуси всіх рядків стоять
   одна під одною, і сторінку можна прочитати одним поглядом згори вниз. */
.int-head {
  display: grid;
  grid-template-columns: 34px minmax(0, 1fr) 176px 150px;
  grid-template-areas: 'icon info status action';
  align-items: center;
  gap: 14px;
  padding: 13px 18px;
}

.int-icon {
  grid-area: icon;
  width: 34px;
  height: 34px;
  display: flex;
  align-items: center;
  justify-content: center;
  border: 1px solid var(--line);
  background: var(--surface);
}

.int-info {
  grid-area: info;
  min-width: 0;
}

.int-title {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 2px 8px;
}

.int-name {
  font-size: 13.5px;
  font-weight: 700;
  color: #2b2f33;
}

.int-tag {
  font-size: 10.5px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--accent);
  border: 1px solid currentColor;
  padding: 1px 6px;
}

.int-meta {
  font-size: 12px;
  color: var(--muted);
  margin-top: 2px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.int-status {
  grid-area: status;
  justify-self: start;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  font-weight: 600;
  padding: 3px 9px;
  white-space: nowrap;
}

.int-status-dot {
  width: 7px;
  height: 7px;
  flex-shrink: 0;
  /* виняток із глобального border-radius: 0 — крапка лишається круглою */
  border-radius: 50% !important;
  background: currentColor;
}

.int-status.is-ok {
  color: #0e7d70;
  background: #e3f4f1;
}

/* «Потрібна дія»: колір не несе сенсу сам по собі — порожня крапка й текст. */
.int-status.is-action {
  color: #4f5559;
  background: var(--line);
}

.int-status.is-action .int-status-dot {
  background: none;
  box-shadow: inset 0 0 0 1.5px currentColor;
}

.int-status.is-error {
  color: #c2402f;
  background: #faeeec;
}

.int-status.is-off {
  color: var(--muted);
  background: none;
  padding-left: 0;
}

.int-status.is-off .int-status-dot {
  background: var(--muted-2);
}

.int-action {
  grid-area: action;
  display: flex;
  justify-content: flex-end;
}

.int-notice {
  margin: -4px 18px 12px 66px;
  padding: 7px 10px;
  font-size: 12.5px;
}

.int-notice.is-ok {
  color: #0e7d70;
  background: #e3f4f1;
}

.int-notice.is-error {
  color: #c2402f;
  background: #faeeec;
}

/* Налаштування — друга площина (легкий тон), а не вкладена картка.
   Лівий відступ вирівнює вміст із назвою інтеграції. */
.int-panel {
  border-top: 1px solid var(--line);
  background: #f9fafb;
  padding: 16px 18px 18px 66px;
  animation: panelIn 0.18s cubic-bezier(0.25, 1, 0.5, 1) both;
}

@media (max-width: 760px) {
  .int-head {
    grid-template-columns: 34px minmax(0, 1fr) auto;
    grid-template-areas:
      'icon info info'
      '. status action';
    row-gap: 10px;
    padding: 12px 14px;
  }

  .int-meta {
    white-space: normal;
  }

  .int-notice {
    margin: -2px 14px 12px 14px;
  }

  .int-panel {
    padding: 14px;
  }
}

@media (prefers-reduced-motion: reduce) {
  .int-panel {
    animation: none;
  }
}
</style>
