import { ref } from 'vue';
import client from '../api/client';

/**
 * Статистика за період: дні, підсумки, топ діяльностей, таски зі звітів і задачі плану.
 */
export function useStats() {
  const stats = ref([]);
  const totals = ref(null);
  // Фактичний період відповіді { date_from, date_to } — графіки будують дні саме з нього.
  const period = ref(null);
  // Топ діяльностей за період.
  const topActivities = ref([]);
  // Таски за період зі звітів: { total, seconds, top }.
  const tasks = ref(null);
  // Задачі з плану за період: { total, done, review, top }.
  const planTasks = ref(null);
  const loading = ref(false);
  const error = ref('');

  // Номер останнього запиту: відповідь за попередній період, що прийшла
  // пізніше, не має перезаписати новий.
  let requestId = 0;

  async function load(params) {
    const current = ++requestId;
    loading.value = true;
    error.value = '';
    try {
      const { data } = await client.get('/stats', { params });
      if (current !== requestId) return;
      stats.value = data.data;
      totals.value = data.totals;
      period.value = data.period;
      topActivities.value = data.activities;
      tasks.value = data.tasks;
      planTasks.value = data.plan_tasks;
    } catch (e) {
      if (current !== requestId) return;
      error.value = e.response?.data?.message || 'Не вдалося завантажити статистику.';
    } finally {
      if (current === requestId) loading.value = false;
    }
  }

  return { stats, totals, period, topActivities, tasks, planTasks, loading, error, load };
}
