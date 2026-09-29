import { computed, reactive } from 'vue';
import client from '../api/client';
import { trackerLabel } from '../constants/trackers';

/**
 * Таски звіту. Готовий звіт містить знімок тасок на момент генерації —
 * показуємо його, щоб зміни в трекері не переписували вже сформовані звіти.
 * Живий запит /tasks лишається fallback-ом для старих звітів без знімка.
 *
 * @param {() => string|null} fallbackProvider трекер, поки /tasks ще не відповів
 */
export function useReportTasks(fallbackProvider) {
  const state = reactive({
    items: [],
    loading: false,
    error: '',
    notConnected: false,
    // Показано знімок зі звіту, а не живий список із трекера.
    snapshot: false,
    // Який трекер віддав таски: приходить у відповіді /tasks.
    provider: null,
  });

  const label = computed(() => trackerLabel(state.provider || fallbackProvider()));

  // Номер останнього запиту: відповідь на запит за попередню дату, що
  // прийшла пізніше, не має перезаписати таски нової.
  let requestId = 0;

  function reset() {
    requestId += 1;
    state.items = [];
    state.loading = false;
    state.error = '';
    state.notConnected = false;
    state.snapshot = false;
  }

  function applyFromReport(report, isoDate) {
    if (Array.isArray(report?.tasks)) {
      reset();
      state.items = report.tasks;
      state.snapshot = true;
      return;
    }
    load(isoDate);
  }

  async function load(isoDate) {
    reset();
    const request = requestId;
    state.loading = true;
    try {
      const { data } = await client.get('/tasks', { params: { date: isoDate } });
      if (request !== requestId) return;
      state.items = data.data;
      state.provider = data.provider || state.provider;
    } catch (error) {
      if (request !== requestId) return;
      state.provider = error.response?.data?.provider || state.provider;
      if (error.response?.data?.not_connected) {
        state.notConnected = true;
      } else {
        state.error = error.response?.data?.message || `Не вдалося отримати таски з ${label.value}.`;
      }
    }
    state.loading = false;
  }

  return { state, label, reset, applyFromReport };
}
