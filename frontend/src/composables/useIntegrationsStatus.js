import { computed, ref } from 'vue';
import client from '../api/client';
import { useAuthStore } from '../stores/auth';

/**
 * Коротка перевірка готовності персональних інтеграцій: без активного
 * трекера й Google Таблиці формування звіту заблоковане. Деталі й
 * підключення живуть на сторінці «Інтеграції», тут потрібен лише факт
 * «налаштовано / ні». Адмін власних звітів не формує — для нього
 * перевірка не діє.
 */
export function useIntegrationsStatus() {
  const auth = useAuthStore();
  const status = ref({ loaded: false, tracker: false, sheets: false, provider: null });

  const ready = computed(() => auth.isAdmin || (status.value.loaded && status.value.tracker && status.value.sheets));

  async function load() {
    try {
      const { data } = await client.get('/integrations/status');
      status.value = {
        loaded: true,
        tracker: data.tracker.connected,
        sheets: data.sheets.connected,
        provider: data.tracker.provider,
      };
    } catch {
      // Стан не отримали — кнопку не блокуємо, бекенд однаково перевірить інтеграції
      // перед генерацією і поверне зрозумілу помилку.
      status.value = { loaded: true, tracker: true, sheets: true, provider: auth.user?.task_provider || 'trello' };
    }
  }

  return { status, ready, load };
}
