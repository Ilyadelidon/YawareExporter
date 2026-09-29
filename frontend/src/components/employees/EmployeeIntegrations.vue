<script setup>
// Що працівник підключив сам. Дані вже приїхали разом зі списком працівників.
import { computed } from 'vue';
import { integrationRows } from '../../utils/employees';

const props = defineProps({
  integrations: { type: Object, default: null },
});

const hasAccount = computed(() => Boolean(props.integrations?.has_account));
const rows = computed(() => integrationRows(props.integrations));
const connectedCount = computed(() => rows.value.filter((row) => row.connected).length);
</script>

<template>
  <div class="integrations">
    <div v-if="!hasAccount" class="integrations-empty">
      Працівник ще жодного разу не входив у систему — інтеграцій немає.
    </div>

    <template v-else>
      <div v-if="!connectedCount" class="integrations-empty">
        Жодної інтеграції не підключено.
      </div>

      <ul class="integrations-list">
        <li v-for="row in rows" :key="row.key" class="integration-row">
          <span class="integration-dot" :class="{ 'is-on': row.connected }"></span>
          <span class="integration-name">{{ row.label }}</span>
          <span class="integration-status" :class="{ 'is-on': row.connected }">
            {{ row.connected ? 'Підключено' : 'Не підключено' }}
          </span>
          <template v-if="row.connected">
            <span v-if="row.detail" class="integration-detail">{{ row.detail }}</span>
            <span v-if="row.note" class="integration-note">{{ row.note }}</span>
            <a v-if="row.url" :href="row.url" target="_blank" rel="noopener" class="integration-link">
              {{ row.linkLabel }}
            </a>
          </template>
        </li>
      </ul>
    </template>
  </div>
</template>

<style scoped>
.integrations {
  padding: 12px 4px 4px;
}

.integrations-empty {
  padding: 0 0 10px;
  font-size: 13px;
  color: var(--muted);
}

.integrations-list {
  margin: 0;
  padding: 0;
  list-style: none;
}

.integration-row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 6px 12px;
  padding: 8px 0;
  font-size: 13.5px;
  border-bottom: 1px solid var(--line);
}

.integration-row:last-child {
  border-bottom: none;
}

.integration-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: var(--muted-2);
}

.integration-dot.is-on {
  background: var(--accent);
}

.integration-name {
  min-width: 130px;
  font-weight: 600;
}

.integration-status {
  min-width: 100px;
  color: var(--muted);
}

.integration-status.is-on {
  color: var(--accent);
}

.integration-detail {
  color: var(--text-dim);
}

.integration-note {
  font-size: 12px;
  color: var(--muted);
}

.integration-link {
  margin-left: auto;
  font-size: 12.5px;
  color: var(--accent);
}
</style>
