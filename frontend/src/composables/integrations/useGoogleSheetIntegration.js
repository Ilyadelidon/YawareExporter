import { computed, onMounted, reactive, ref } from 'vue';
import client from '../../api/client';
import { useIntegrationLinksStore } from '../../stores/integrations';
import { errorMessage } from '../../utils/errors';
import { useIntegrationFeedback } from './useIntegrationFeedback';

const KEY = 'sheets';

/**
 * Персональна Google Таблиця для звітів. Google-акаунт сервісу один на всіх
 * (його авторизує адміністратор), таблиця — у кожного своя.
 */
export function useGoogleSheetIntegration() {
  const feedback = useIntegrationFeedback();
  const sidebarLinks = useIntegrationLinksStore();

  const loading = ref(true);
  const state = ref(null);
  const creating = ref(false);
  const linking = ref(false);
  const detaching = ref(false);

  const accountConnected = computed(() => Boolean(state.value?.account_connected));
  const hasSpreadsheet = computed(() => Boolean(state.value?.spreadsheet_id));

  const status = computed(() => {
    if (!accountConnected.value) return { tone: 'off', label: 'Недоступно' };
    if (!hasSpreadsheet.value) return { tone: 'off', label: 'Не підключено' };
    if (!state.value.spreadsheet_title) return { tone: 'error', label: 'Немає доступу' };
    return { tone: 'ok', label: 'Підключено' };
  });

  const meta = computed(() => {
    if (!accountConnected.value) return 'Google-акаунт сервісу ще не авторизував адміністратор';
    if (!hasSpreadsheet.value) return 'Куди вивантажується готовий звіт';
    return state.value.spreadsheet_title || 'Таблицю не вдалося прочитати';
  });

  async function load() {
    try {
      const { data } = await client.get('/google/status');
      state.value = data;
    } catch (error) {
      feedback.say(KEY, 'error', errorMessage(error, 'Не вдалося отримати стан Google-інтеграції.'));
    } finally {
      loading.value = false;
    }
  }

  // Спільний хвіст дій: згорнути налаштування, оновити стан і кнопку в меню.
  async function afterChange(data) {
    feedback.closeManage();
    await load();
    sidebarLinks.load();
    return data.message;
  }

  function create({ name, email }) {
    return feedback.run(KEY, creating, 'Не вдалося створити таблицю.', async () => {
      const { data } = await client.post('/google/spreadsheet', { name: name || null, email: email || null });
      return afterChange(data);
    });
  }

  function link(spreadsheet) {
    return feedback.run(KEY, linking, 'Не вдалося підключити таблицю.', async () => {
      const { data } = await client.post('/google/spreadsheet/link', { spreadsheet });
      return afterChange(data);
    });
  }

  function detach() {
    return feedback.run(KEY, detaching, 'Не вдалося відвʼязати таблицю.', async () => {
      const { data } = await client.delete('/google/spreadsheet');
      return afterChange(data);
    });
  }

  onMounted(load);

  return reactive({
    loading,
    state,
    creating,
    linking,
    detaching,
    accountConnected,
    hasSpreadsheet,
    status,
    meta,
    create,
    link,
    detach,
  });
}
