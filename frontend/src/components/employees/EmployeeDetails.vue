<script setup>
// Розгорнутий рядок працівника: що він підключив сам і що AI вже з'ясував про
// його активності. Пам'ять AI вантажиться окремим запитом, тож лише коли
// відкрили її вкладку.
import { ref } from 'vue';
import EmployeeIntegrations from './EmployeeIntegrations.vue';
import EmployeeMemory from './EmployeeMemory.vue';

defineProps({
  employee: { type: Object, required: true },
});

const tab = ref('integrations');

const tabs = [
  { value: 'integrations', label: 'Інтеграції' },
  { value: 'memory', label: 'AI-розбір активностей' },
];
</script>

<template>
  <div class="details-panel">
    <div class="details-tabs" role="tablist">
      <button
        v-for="item in tabs"
        :key="item.value"
        type="button"
        role="tab"
        class="details-tab"
        :class="{ 'is-active': tab === item.value }"
        :aria-selected="tab === item.value"
        @click="tab = item.value"
      >{{ item.label }}</button>
    </div>

    <EmployeeIntegrations v-if="tab === 'integrations'" :integrations="employee.integrations" />
    <EmployeeMemory v-else :employee-id="employee.id" />
  </div>
</template>

<style scoped>
.details-panel {
  padding: 4px;
}

.details-tabs {
  display: flex;
  gap: 4px;
  border-bottom: 1px solid var(--line);
}

.details-tab {
  margin-bottom: -1px;
  padding: 8px 12px;
  border: none;
  border-bottom: 2px solid transparent;
  background: transparent;
  font: inherit;
  font-size: 13px;
  font-weight: 600;
  color: var(--muted);
  cursor: pointer;
  transition: color 0.12s ease, border-color 0.12s ease;
}

.details-tab:hover {
  color: var(--text-dim);
}

.details-tab.is-active {
  border-bottom-color: var(--accent);
  color: var(--accent);
}
</style>
