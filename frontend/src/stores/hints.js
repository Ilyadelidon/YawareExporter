import { defineStore } from 'pinia';
import client from '../api/client';
import { shiftIsoDate } from '../utils/dates';
import { useAuthStore } from './auth';

// Дні, які працівник сам пропустив (відпустка, лікарняний), — лише в його
// браузері. Старші за цей строк уже не потрапляють у підказки й викидаються.
const SKIP_TTL_DAYS = 30;

const skipKey = (userId) => `hints.skipped.${userId}`;

/**
 * Підказки працівнику в боковому меню: будні без готового звіту й без
 * відміченої задачі в «Планах». Стор, а не стан меню, — щоб сторінки планів
 * і звітів могли одразу оновити чи прибрати підказку.
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
    planDays: (state) => state.plans.filter((iso) => !state.skipped.includes(iso)),
    reportDays: (state) => state.reports.filter((item) => !state.skipped.includes(item.date)),

    /**
     * Одна підказка й один день за раз. Пріоритет: спершу відмітити задачі в
     * Планах, потім сформувати пропущені звіти, і лише тоді — звіти, що
     * чекають правки тасок у трекері. Дні приходять від найсвіжішого:
     * закрили вчора — зʼявляється позавчора.
     */
    active() {
      if (this.planDays.length) {
        return { kind: 'plans', date: this.planDays[0] };
      }
      const missing = this.reportDays.find((item) => item.status !== 'blocked');
      if (missing) {
        return { kind: 'report', date: missing.date };
      }
      const blocked = this.reportDays.find((item) => item.status === 'blocked');
      return blocked ? { kind: 'blocked', date: blocked.date } : null;
    },

    // Чи нагадує якась підказка про цей день.
    hasDay() {
      return (iso) => this.planDays.includes(iso) || this.reportDays.some((item) => item.date === iso);
    },
  },

  actions: {
    async load() {
      const auth = useAuthStore();
      if (auth.isAdmin) return;

      if (auth.user?.id !== this.userId) {
        this.userId = auth.user?.id ?? null;
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
      if (this.skipped.includes(iso)) return;
      this.skipped = [...this.skipped, iso];
      this.writeSkipped();
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
      if (this.today) {
        const oldest = shiftIsoDate(this.today, -SKIP_TTL_DAYS);
        this.skipped = this.skipped.filter((iso) => iso >= oldest);
      }
      try {
        localStorage.setItem(skipKey(this.userId), JSON.stringify(this.skipped));
      } catch {
        // Сховище недоступне — пропуск діятиме до перезавантаження сторінки.
      }
    },
  },
});
