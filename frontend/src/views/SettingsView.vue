<script setup>
// Налаштування (лише адмін): командні інтеграції й сповіщення, які
// налаштовуються один раз на всю команду, а не по кожному працівнику.
import AlertEmailPanel from '../components/AlertEmailPanel.vue';
import BitrixWorkspacePanel from '../components/BitrixWorkspacePanel.vue';
</script>

<template>
  <div class="settings-page">
    <div class="page-head">
      <div class="page-head-info">
        <div class="page-head-icon">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#149d8d" stroke-width="2"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
        </div>
        <div>
          <div class="page-head-title">Налаштування</div>
          <div class="page-head-subtitle">Таск-трекер команди та сповіщення керівнику</div>
        </div>
      </div>
    </div>

    <div class="settings-stack">
      <!-- Командні налаштування трекера: портал Бітрікса один на всіх працівників -->
      <BitrixWorkspacePanel />

      <!-- Куди керівнику писати про критичні порушення з AI-розбору дня -->
      <AlertEmailPanel />
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
