import { defineStore } from 'pinia';
import client from '../api/client';

const POLL_MS = 5000;
const ACTIVE = ['pending', 'processing'];

let pollTimer = null;

/**
 * Звіт, що формується, і вибір на сторінці звітів. Живе поза сторінкою:
 * після переходу в інший розділ і назад сторінка відкривається на тій самій
 * даті й працівнику, а статус звіту тим часом политься тут — тож лоадер
 * лишається, поки звіт реально не готовий.
 */
export const useReportGenerationStore = defineStore('reportGeneration', {
  state: () => ({
    report: null,
    date: null,
    employeeId: null,
  }),

  getters: {
    isActive: (state) => ACTIVE.includes(state.report?.status),
  },

  actions: {
    // Беремо звіт під нагляд; фінальний статус теж зберігаємо, щоб сторінка
    // підхопила результат, навіть якщо користувач повернувся вже після нього.
    track(report) {
      this.report = report || null;
      this.schedule();
    },

    schedule() {
      clearTimeout(pollTimer);
      if (this.isActive && this.report?.id) {
        pollTimer = setTimeout(() => this.refresh(), POLL_MS);
      }
    },

    async refresh() {
      const id = this.report?.id;
      if (!id) return;
      try {
        const { data } = await client.get(`/reports/${id}`);
        // Поки чекали відповідь, могли взяти під нагляд інший звіт
        if (this.report?.id === id) {
          this.report = data.data;
        }
      } catch {
        // тимчасова помилка мережі — наступний тік спробує ще раз
      }
      this.schedule();
    },

    stop() {
      clearTimeout(pollTimer);
      this.$reset();
    },
  },
});
