<script setup>
// Фільтри плану (виконавець, «Активні задачі») і дії над проектом.
import UiIcon from '../UiIcon.vue';
import '../../styles/plans-ui.css';

const person = defineModel('person', { type: [String, Number], required: true });
const hideClosed = defineModel('hideClosed', { type: Boolean, required: true });

defineProps({
  people: { type: Array, required: true },
  myEmployeeId: { type: Number, default: null },
  visibleCount: { type: Number, required: true },
  totalCount: { type: Number, required: true },
  canAddTasks: { type: Boolean, default: false },
  canManage: { type: Boolean, default: false },
});

const emit = defineEmits(['new-section', 'new-task', 'settings']);
</script>

<template>
  <div class="toolbar">
    <label class="toolbar-field" title="Виконавець">
      <UiIcon name="user" :size="15" aria-label="Виконавець" />
      <select v-model="person">
        <option value="all">Усі</option>
        <option v-for="p in people" :key="p.id" :value="p.id">
          {{ p.name }}{{ p.id === myEmployeeId ? ' (я)' : '' }}
        </option>
      </select>
    </label>
    <label class="toolbar-check">
      <input v-model="hideClosed" type="checkbox">
      Активні задачі
    </label>
    <span class="toolbar-count">{{ visibleCount }} з {{ totalCount }}</span>

    <span class="toolbar-gap"></span>

    <button v-if="canAddTasks" type="button" class="plan-btn" @click="emit('new-section')">
      <UiIcon name="plus" :size="14" :stroke-width="2.4" class="btn-icon" />
      Розділ
    </button>
    <button v-if="canAddTasks" type="button" class="plan-btn is-accent" @click="emit('new-task')">
      <UiIcon name="plus" :size="14" :stroke-width="2.4" class="btn-icon" />
      Задача
    </button>
    <button
      v-if="canManage"
      type="button"
      class="plan-btn is-icon"
      title="Налаштування проекту"
      aria-label="Налаштування проекту"
      @click="emit('settings')"
    >
      <UiIcon name="settings" :size="16" />
    </button>
  </div>
</template>

<style scoped>
.toolbar {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 10px 14px;
  margin-top: 14px;
  font-size: 13px;
}

.toolbar-field {
  display: flex;
  align-items: center;
  gap: 8px;
}

.toolbar-field svg {
  flex-shrink: 0;
  color: var(--muted-2);
}

.toolbar-field select {
  padding: 7px 8px;
  border: 1px solid #dfe5ea;
  background: var(--surface);
  font: inherit;
  color: var(--ink);
  max-width: 220px;
}

.toolbar-check {
  display: flex;
  align-items: center;
  gap: 6px;
  color: var(--text-dim);
  cursor: pointer;
}

.toolbar-check input {
  accent-color: var(--accent);
}

.toolbar-count {
  color: var(--muted);
  font-variant-numeric: tabular-nums;
}

.toolbar-gap {
  flex: 1;
}
</style>
