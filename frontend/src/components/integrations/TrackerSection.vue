<script setup>
import { computed, ref } from 'vue';
import client from '../../api/client';
import { TRACKER_LABELS } from '../../constants/trackers';
import { useIntegrationFeedback } from '../../composables/integrations/useIntegrationFeedback';
import { useBitrixIntegration } from '../../composables/integrations/useBitrixIntegration';
import { useTrelloIntegration } from '../../composables/integrations/useTrelloIntegration';
import { useAuthStore } from '../../stores/auth';
import { useIntegrationLinksStore } from '../../stores/integrations';
import BitrixRow from './BitrixRow.vue';
import SegmentedControl from './SegmentedControl.vue';
import TrelloRow from './TrelloRow.vue';

// Таск-трекер: звідки звіт бере завдання за день. Перемикання нічого не
// відв'язує — налаштування обох трекерів лишаються на місці.
const auth = useAuthStore();
const sidebarLinks = useIntegrationLinksStore();
const feedback = useIntegrationFeedback();
const trello = useTrelloIntegration();
const bitrix = useBitrixIntegration();

const provider = computed(() => auth.user?.task_provider || 'trello');
const switching = ref(false);
const loading = computed(() => trello.loading || bitrix.loading);

// Бітрікс не можна зробити активним, поки адміністратор не підключив портал команди.
const sourceOptions = computed(() => [
  { value: 'trello', label: TRACKER_LABELS.trello },
  {
    value: 'bitrix',
    label: TRACKER_LABELS.bitrix,
    disabled: !bitrix.workspaceConnected,
    title: !bitrix.workspaceConnected && !bitrix.loading ? 'Портал команди ще не підключив адміністратор' : null,
  },
]);

function switchProvider(next) {
  if (next === provider.value || switching.value) return;
  feedback.run(next, switching, 'Не вдалося перемкнути таск-трекер.', async () => {
    const { data } = await client.put('/tasks/provider', { provider: next });
    if (auth.user) auth.user.task_provider = data.provider;
    sidebarLinks.load();
    const ready = data.provider === 'bitrix' ? bitrix.connected : trello.connected;
    // Якщо новий трекер ще не готовий до звітів — підказуємо, що зробити далі.
    return ready ? data.message : `${data.message} Підключіть акаунт, інакше таски у звіт не потраплять.`;
  });
}
</script>

<template>
  <section class="int-section" aria-labelledby="sec-tracker">
    <header class="section-head">
      <div>
        <h2 id="sec-tracker" class="section-title">Таск-трекер</h2>
        <p class="section-desc">Звідки звіт бере завдання за день. Налаштування неактивного трекера зберігаються.</p>
      </div>

      <div class="source">
        <span id="source-label" class="source-label">Таски для звіту з</span>
        <SegmentedControl
          :model-value="provider"
          :options="sourceOptions"
          :disabled="loading || switching"
          aria-labelledby="source-label"
          @update:model-value="switchProvider"
        />
      </div>
    </header>

    <div class="panel int-list" :aria-busy="loading">
      <template v-if="loading">
        <div v-for="n in 2" :key="n" class="row-skeleton"><div class="skeleton"></div></div>
      </template>

      <template v-else>
        <TrelloRow :trello="trello" :active="provider === 'trello'" />
        <BitrixRow :bitrix="bitrix" :active="provider === 'bitrix'" />
      </template>
    </div>
  </section>
</template>

<style scoped>
.source {
  display: flex;
  align-items: center;
  gap: 10px;
}

.source-label {
  font-size: 12px;
  font-weight: 600;
  color: var(--text-dim);
}

@media (max-width: 760px) {
  .source {
    width: 100%;
    justify-content: space-between;
  }
}
</style>
