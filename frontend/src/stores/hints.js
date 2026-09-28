import { defineStore } from 'pinia';
import client from '../api/client';

// Дні, які працівник сам пропустив (відпустка, лікарняний), — лише в його
// браузері. Старші за цей строк уже не потрапляють у підказки й викидаються.
const SKIP_TTL_DAYS = 30;

function skipKey(userId) {
  return `hints.skipped.${userId}`;
}

/**
 * Підказки працівнику в боковому меню: будні без готового звіту й без
 * відміченої задачі в «Планах». Стор, а не стан меню, — щоб сторінка планів
 * могла одразу прибрати підказку після відмітки дня.
 */
export const useHintsStore = defineStore('hints', {
  state: () => ({
    loaded: false,
    today: null,
    reports: [],
    plans: [],
    skipped: [],
    userId: null,
  }),

  getters: {
    reportDays: (state) => state.reports.filter((item) => !state.skipped.includes(item.date)),
    planDays: (state) => state.plans.filter((iso) => !state.skipped.includes(iso)),
  },

  actions: {
    async load(userId) {
      if (userId && userId !== this.userId) {
        this.userId = userId;
        this.readSkipped();
      }
      try {
        const { data } = await client.get('/hints');
        this.today = data.today;
        this.reports = data.reports;
        this.plans = data.plans;
      } catch {
        // Підказки допоміжні: при збої лишаємо попередні.
      } finally {
        this.loaded = true;
      }
    },

    skip(iso) {
      if (!this.skipped.includes(iso)) {
        this.skipped = [...this.skipped, iso];
        this.writeSkipped();
      }
    },

    readSkipped() {
      try {
        const stored = JSON.parse(localStorage.getItem(skipKey(this.userId)) || '[]');
        this.skipped = Array.isArray(stored) ? stored : [];
      } catch {
        this.skipped = [];
      }
    },

    writeSkipped() {
      const border = new Date();
      border.setDate(border.getDate() - SKIP_TTL_DAYS);
      const minIso = border.toISOString().slice(0, 10);
      this.skipped = this.skipped.filter((iso) => iso >= minIso);
      try {
        localStorage.setItem(skipKey(this.userId), JSON.stringify(this.skipped));
      } catch {
        // Сховище недоступне — пропуск діятиме до перезавантаження сторінки.
      }
    },
  },
});
