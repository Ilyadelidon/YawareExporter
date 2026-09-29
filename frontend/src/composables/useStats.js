import { reactive, ref } from 'vue';
import client from '../api/client';

/**
 * Статистика по днях за період + ліниво довантажувана розбивка діяльностей
 * для розгорнутих рядків.
 */
export function useStats() {
  const stats = ref([]);
  const totals = ref(null);
  const loading = ref(false);
  const error = ref('');

  // Діяльності дня за id рядка статистики: { entries, error }; немає ключа — ще вантажиться.
  const activities = reactive({});

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
      // День міг перегенеруватись — кеш діяльностей скидаємо разом зі списком.
      Object.keys(activities).forEach((key) => delete activities[key]);
    } catch (e) {
      if (current !== requestId) return;
      error.value = e.response?.data?.message || 'Не вдалося завантажити статистику.';
    } finally {
      if (current === requestId) loading.value = false;
    }
  }

  async function loadActivities(stat) {
    if (activities[stat.id]?.entries) return;
    // Після помилки повторне розгортання пробує ще раз.
    delete activities[stat.id];
    try {
      const { data } = await client.get('/stats/activities', {
        params: { employee_id: stat.employee_id, date: stat.date },
      });
      activities[stat.id] = { entries: data.data, error: '' };
    } catch (e) {
      activities[stat.id] = {
        entries: null,
        error: e.response?.data?.message || 'Не вдалося завантажити діяльності.',
      };
    }
  }

  return { stats, totals, loading, error, activities, load, loadActivities };
}
