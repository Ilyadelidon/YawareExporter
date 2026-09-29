<script setup>
// Розгорнутий рядок працівника: що він підключив сам і що AI вже з'ясував про
// його активності. Пам'ять AI вантажиться окремим запитом, тож лише коли
// відкрили її вкладку; інтеграції вже приїхали разом зі списком.
import { computed, ref } from 'vue';
import { TRACKER_LABELS } from '../constants/trackers';
import EmployeeMemoryPanel from './EmployeeMemoryPanel.vue';

const props = defineProps({
  employee: { type: Object, required: true },
});

const tab = ref('integrations');

const tabs = [
  { value: 'integrations', label: 'Інтеграції' },
  { value: 'memory', label: 'AI-розбір активностей' },
];

const integrations = computed(() => props.employee.integrations || { has_account: false });

const rows = computed(() => {
  const info = integrations.value;
  if (!info.has_account) {
    return [];
  }

  const trackerNote = (provider) => (info.task_provider === provider ? 'активний трекер' : null);

  return [
    {
      key: 'trello',
      label: TRACKER_LABELS.trello,
      connected: info.trello.connected,
      detail: info.trello.username ? `@${info.trello.username}` : null,
      note: info.trello.connected && !info.trello.board_url ? 'дошку не обрано' : trackerNote('trello'),
      url: info.trello.board_url,
      linkLabel: 'Дошка',
    },
    {
      key: 'bitrix',
      label: TRACKER_LABELS.bitrix,
      connected: info.bitrix.connected,
      detail: info.bitrix.username,
      note: trackerNote('bitrix'),
    },
    {
      key: 'google',
      label: 'Google Таблиця',
      connected: info.google.connected,
      url: info.google.url,
      linkLabel: 'Відкрити',
    },
    {
      key: 'telegram',
      label: 'Telegram-бот',
      connected: info.telegram.connected,
    },
  ];
});

const connectedCount = computed(() => rows.value.filter((row) => row.connected).length);
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

    <div v-if="tab === 'integrations'" class="integrations">
      <div v-if="!integrations.has_account" class="integrations-empty">
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
            <span v-if="row.connected && row.detail" class="integration-detail">{{ row.detail }}</span>
            <span v-if="row.connected && row.note" class="integration-note">{{ row.note }}</span>
            <a
              v-if="row.connected && row.url"
              :href="row.url"
              target="_blank"
              rel="noopener"
              class="integration-link"
            >{{ row.linkLabel }}</a>
          </li>
        </ul>
      </template>
    </div>

    <EmployeeMemoryPanel v-else :employee-id="employee.id" />
  </div>
</template>

<style scoped>
.details-panel {
  padding: 4px 4px 4px;
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
