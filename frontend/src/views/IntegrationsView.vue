<script setup>
// Персональні інтеграції працівника: таск-трекер (Trello або Бітрікс24),
// Google Таблиця для звітів і Telegram-сповіщення. Командні налаштування —
// у «Налаштуваннях» адміністратора.
import IntegrationsStrip from '../components/IntegrationsStrip.vue';
</script>

<template>
  <div class="integrations-page">
    <div class="page-head">
      <div class="page-head-info">
        <div class="page-head-icon">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#149d8d" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path></svg>
        </div>
        <div>
          <div class="page-head-title">Інтеграції</div>
          <div class="page-head-subtitle">Таск-трекер, Google Таблиця та Telegram — підключення вашого акаунта</div>
        </div>
      </div>
    </div>

    <IntegrationsStrip />
  </div>
</template>

<style scoped>
.integrations-page {
  display: flex;
  flex-direction: column;
}
</style>
