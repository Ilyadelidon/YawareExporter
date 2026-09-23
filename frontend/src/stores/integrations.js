import { defineStore } from 'pinia';
import client from '../api/client';

/**
 * Посилання на підключені сервіси працівника: Google Таблиця і активний
 * таск-трекер. Тримаємо окремо від auth, бо вони змінюються на сторінці
 * інтеграцій, а показуються в боковому меню.
 */
export const useIntegrationLinksStore = defineStore('integrationLinks', {
  state: () => ({
    google: null,
    tracker: null,
  }),

  actions: {
    async load() {
      try {
        const { data } = await client.get('/integrations/links');
        this.google = data.google || null;
        this.tracker = data.tracker || null;
      } catch {
        // Кнопки допоміжні: без відповіді просто не показуємо їх.
        this.google = null;
        this.tracker = null;
      }
    },
  },
});
