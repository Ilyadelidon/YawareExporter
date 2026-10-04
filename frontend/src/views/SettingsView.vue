<script setup>
// Налаштування (лише адмін): командні інтеграції й сповіщення, які
// налаштовуються один раз на всю команду, а не по кожному працівнику.
import UiIcon from '../components/UiIcon.vue';
import AiAnalysisRow from '../components/settings/AiAnalysisRow.vue';
import AlertEmailsRow from '../components/settings/AlertEmailsRow.vue';
import BitrixWorkspaceRow from '../components/settings/BitrixWorkspaceRow.vue';
import OpsTelegramRow from '../components/settings/OpsTelegramRow.vue';
</script>

<template>
  <div class="settings-page">
    <div class="page-head">
      <div class="page-head-info">
        <div class="page-head-icon">
          <UiIcon name="settings" :size="20" color="#149d8d" />
        </div>
        <div>
          <div class="page-head-title">Налаштування</div>
          <div class="page-head-subtitle">Таск-трекер команди, AI-розбір, сповіщення керівнику й технічні сповіщення</div>
        </div>
      </div>
    </div>

    <div class="settings-stack">
      <!-- Командні налаштування трекера: портал Бітрікса один на всіх працівників -->
      <BitrixWorkspaceRow />

      <!-- Чи запускати AI-розбір дня після кожного звіту -->
      <AiAnalysisRow />

      <!-- Куди керівнику писати про критичні порушення з AI-розбору дня -->
      <AlertEmailsRow />

      <!-- Службовий Telegram адміністратора: збої і ранковий прогін -->
      <OpsTelegramRow />
    </div>
  </div>
</template>

<style scoped>
.settings-page {
  display: flex;
  flex-direction: column;
}

.settings-stack {
  display: flex;
  flex-direction: column;
  gap: 22px;
  margin-top: 16px;
  animation: fadeUp 0.35s ease both;
}
</style>
