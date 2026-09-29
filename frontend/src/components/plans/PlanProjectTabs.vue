<script setup>
// Вкладки проектів. Створення проекту — там, де проекти, а не серед дій
// усієї сторінки.
import UiIcon from '../UiIcon.vue';

defineProps({
  projects: { type: Array, required: true },
  activeId: { type: Number, default: null },
  canCreate: { type: Boolean, default: false },
});

const emit = defineEmits(['select', 'create']);
</script>

<template>
  <div class="project-tabs">
    <div class="project-tabs-list" role="tablist">
      <button
        v-for="project in projects"
        :key="project.id"
        type="button"
        role="tab"
        class="project-tab"
        :class="{ 'is-active': project.id === activeId, 'is-archived': project.archived }"
        :aria-selected="project.id === activeId"
        @click="emit('select', project.id)"
      >
        {{ project.name }}
        <span class="project-tab-count">{{ project.tasks_count }}</span>
      </button>
    </div>
    <button
      v-if="canCreate"
      type="button"
      class="project-tab is-new"
      title="Створити проект"
      @click="emit('create')"
    >
      <UiIcon name="plus" :size="14" :stroke-width="2.4" />
      Проект
    </button>
  </div>
</template>

<style scoped>
.project-tabs {
  display: flex;
  align-items: stretch;
  gap: 12px;
  margin-top: 16px;
  border-bottom: 1px solid #dfe5ea;
}

.project-tabs-list {
  display: flex;
  gap: 2px;
  flex: 1;
  min-width: 0;
  overflow-x: auto;
  overflow-y: hidden;
}

.project-tab {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 16px;
  border: none;
  border-bottom: 2px solid transparent;
  margin-bottom: -1px;
  background: transparent;
  font: inherit;
  font-size: 13.5px;
  font-weight: 600;
  color: var(--text-dim);
  cursor: pointer;
  white-space: nowrap;
}

.project-tab:hover {
  color: var(--ink);
}

.project-tab.is-active {
  color: var(--accent);
  border-bottom-color: var(--accent);
}

.project-tab.is-archived {
  color: var(--muted-2);
}

.project-tab.is-new {
  flex-shrink: 0;
  gap: 6px;
  color: var(--muted);
}

.project-tab.is-new:hover {
  color: var(--accent);
}

.project-tab-count {
  font-size: 11.5px;
  font-weight: 600;
  padding: 1px 6px;
  background: var(--line);
  color: var(--muted);
  font-variant-numeric: tabular-nums;
}
</style>
