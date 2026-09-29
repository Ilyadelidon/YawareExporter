<script setup>
import { useIntegrationFeedback } from '../../composables/integrations/useIntegrationFeedback';
import DangerConfirm from './DangerConfirm.vue';
import IntegrationIcon from './IntegrationIcon.vue';
import IntegrationRow from './IntegrationRow.vue';
import ManageToggle from './ManageToggle.vue';

defineProps({
  // useBitrixIntegration()
  bitrix: { type: Object, required: true },
  // Бітрікс24 — джерело тасків для звіту
  active: { type: Boolean, default: false },
});

const feedback = useIntegrationFeedback();
</script>

<template>
  <IntegrationRow
    id="bitrix"
    name="Бітрікс24"
    :meta="bitrix.meta"
    :status="bitrix.status"
    :tag="active ? 'Активний' : ''"
    :open="feedback.isManaging('bitrix') && bitrix.connected"
    :notice="feedback.noticeFor('bitrix')"
  >
    <template #icon><IntegrationIcon name="bitrix" /></template>

    <template #action>
      <ManageToggle
        v-if="bitrix.connected"
        target="bitrix"
        :expanded="feedback.isManaging('bitrix')"
        @toggle="feedback.toggleManage('bitrix')"
      />
      <button
        v-else-if="bitrix.workspaceConnected"
        type="button"
        class="btn btn-primary"
        :disabled="bitrix.authorizing"
        title="Вас перекине на портал команди, де треба увійти й підтвердити доступ"
        @click="bitrix.startAuth"
      >
        {{ bitrix.authorizing ? 'Переходимо…' : 'Увійти' }}
      </button>
    </template>

    <p v-if="!active" class="panel-hint is-top">
      Зараз таски беруться з Trello. Щоб брати їх з Бітрікс24, перемкніть джерело вгорі.
    </p>

    <div class="fields">
      <div class="field">
        <div class="field-label">Портал</div>
        <div class="field-control">
          <a :href="bitrix.state.portal_url" target="_blank" rel="noopener" class="field-link is-inline">{{ bitrix.portalLabel }} ↗</a>
        </div>
      </div>

      <div class="field">
        <div class="field-label">Ваш акаунт</div>
        <div class="field-control">
          <div class="control-row">
            <span class="field-value control-grow">{{ bitrix.accountLabel }}</span>
            <button type="button" class="btn btn-secondary" :disabled="bitrix.authorizing" @click="bitrix.startAuth">
              {{ bitrix.authorizing ? 'Переходимо…' : 'Оновити доступ' }}
            </button>
          </div>
          <p class="field-hint">
            У звіт потрапляють таски, де ви відповідальний, з плановими датами на цей день.
            Запити йдуть від вашого імені, чужих тасків сервіс не бачить.
          </p>
        </div>
      </div>
    </div>

    <DangerConfirm
      action="Відвʼязати акаунт"
      warning="Портал команди залишиться підключеним."
      confirm-label="Так, відвʼязати"
      busy-label="Відвʼязуємо…"
      :busy="bitrix.unlinking"
      @confirm="bitrix.unlink"
    />
  </IntegrationRow>
</template>
