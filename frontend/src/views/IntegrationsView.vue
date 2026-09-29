<script setup>
// Персональні інтеграції працівника: таск-трекер (Trello або Бітрікс24),
// Google Таблиця для звітів і Telegram-сповіщення. Командні налаштування —
// у «Налаштуваннях» адміністратора.
import GoogleSheetRow from '../components/integrations/GoogleSheetRow.vue';
import TelegramRow from '../components/integrations/TelegramRow.vue';
import TrackerSection from '../components/integrations/TrackerSection.vue';
import { provideIntegrationFeedback } from '../composables/integrations/useIntegrationFeedback';
import '../styles/integrations-ui.css';

provideIntegrationFeedback();
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

    <div class="integrations int-ui">
      <TrackerSection />

      <section class="int-section" aria-labelledby="sec-delivery">
        <header class="section-head">
          <div>
            <h2 id="sec-delivery" class="section-title">Звіти та сповіщення</h2>
            <p class="section-desc">Куди потрапляє готовий звіт і як про нього дізнатися.</p>
          </div>
        </header>

        <div class="panel int-list">
          <GoogleSheetRow />
          <TelegramRow />
        </div>
      </section>
    </div>
  </div>
</template>

<style scoped>
/* Кнопки, поля, секції й небезпечна дія — у styles/integrations-ui.css. */
.integrations-page {
  display: flex;
  flex-direction: column;
}

.integrations {
  display: flex;
  flex-direction: column;
  gap: 22px;
  margin-top: 16px;
  animation: fadeUp 0.35s ease both;
}
</style>
