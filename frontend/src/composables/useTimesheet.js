import { ref } from 'vue';
import client from '../api/client';
import { monthDays, toIsoDate, toMonthParam } from '../utils/dates';
import { errorMessage } from '../utils/errors';

/**
 * Табель за вибраний місяць: рядки працівників і колонки днів.
 */
export function useTimesheet() {
  const rows = ref([]);
  const days = ref([]);
  const loading = ref(true);
  const error = ref('');

  // Номер останнього запиту: табель попереднього місяця, що прийшов
  // пізніше, не має підмінити поточний.
  let requestId = 0;

  async function load(month) {
    const current = ++requestId;
    loading.value = true;
    error.value = '';
    try {
      const { data } = await client.get('/timesheet', { params: { month: toMonthParam(month) } });
      if (current !== requestId) return;
      rows.value = data.data;
      days.value = monthDays(data.month, data.days_in_month, toIsoDate(new Date()));
    } catch (e) {
      if (current !== requestId) return;
      // Рядки попереднього місяця під новим місяцем вводили б в оману.
      rows.value = [];
      error.value = errorMessage(e, 'Не вдалося завантажити табель.');
    } finally {
      if (current === requestId) loading.value = false;
    }
  }

  return { rows, days, loading, error, load };
}
