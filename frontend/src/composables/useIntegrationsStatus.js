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
    const provider = auth.user?.task_provider || 'trello';
    try {
      const [tracker, google] = await Promise.all([
        client.get(provider === 'bitrix' ? '/bitrix/status' : '/trello/status'),
        client.get('/google/status'),
      ]);
      status.value = {
        loaded: true,
        tracker: Boolean(tracker.data.connected),
        sheets: Boolean(google.data.account_connected && google.data.spreadsheet_id),
        provider,
      };
    } catch {
      // Стан не отримали — кнопку не блокуємо, бекенд однаково перевірить інтеграції
      // перед генерацією і поверне зрозумілу помилку.
      status.value = { loaded: true, tracker: true, sheets: true, provider };
    }
  }

  return { status, ready, load };
}
