<script setup>
import { useIntegrationFeedback } from '../../composables/integrations/useIntegrationFeedback';
import { useTelegramIntegration } from '../../composables/integrations/useTelegramIntegration';
import DangerConfirm from './DangerConfirm.vue';
import IntegrationIcon from './IntegrationIcon.vue';
import IntegrationRow from './IntegrationRow.vue';
import ManageToggle from './ManageToggle.vue';

const feedback = useIntegrationFeedback();
const telegram = useTelegramIntegration();
</script>

<template>
  <div v-if="telegram.loading" class="row-skeleton"><div class="skeleton"></div></div>
  <IntegrationRow
    v-else
    id="telegram"
    name="Telegram"
    :meta="telegram.meta"
    :status="telegram.status"
    :open="feedback.isManaging('telegram') && telegram.connected"
    :notice="feedback.noticeFor('telegram')"
  >
    <template #icon><IntegrationIcon name="telegram" /></template>

    <template #action>
      <ManageToggle
        v-if="telegram.connected"
        target="telegram"
        :expanded="feedback.isManaging('telegram')"
        @toggle="feedback.toggleManage('telegram')"
      />
      <button
        v-else-if="telegram.configured"
        type="button"
        class="btn btn-primary"
        :disabled="telegram.linking"
        @click="telegram.connect"
      >
        <svg v-if="telegram.linking" class="spinner" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" aria-hidden="true"><path d="M21 12a9 9 0 1 1-6.2-8.56"></path></svg>
        {{ telegram.linking ? 'Чекаємо…' : 'Підключити' }}
      </button>
    </template>

    <div class="fields">
      <div class="field">
        <div class="field-label">Що надходить</div>
        <div class="field-control">
          <ul class="field-list">
            <li>Готовий звіт з посиланням на вкладку Google Таблиці</li>
            <li>Нагадування заповнити таски, якщо за день їх немає</li>
            <li>Попередження про помилки генерації</li>
          </ul>
        </div>
      </div>
    </div>

    <DangerConfirm
      action="Відключити Telegram"
      warning="Сповіщення перестануть надходити."
      :busy="telegram.unlinking"
      @confirm="telegram.disconnect"
    />
  </IntegrationRow>
</template>
