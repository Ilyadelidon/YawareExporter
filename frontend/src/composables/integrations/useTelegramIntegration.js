import { computed, onMounted, onUnmounted, reactive, ref } from 'vue';
import client from '../../api/client';
import { errorMessage } from '../../utils/errors';
import { useIntegrationFeedback } from './useIntegrationFeedback';

const KEY = 'telegram';

// Скільки чекаємо, поки користувач натисне Start у боті, і як часто питаємо.
const LINK_WAIT_MS = 120000;
const POLL_INTERVAL_MS = 3000;

/**
 * Особисті Telegram-сповіщення: одноразове посилання на бота, а далі чекаємо,
 * поки користувач натисне в ньому Start.
 */
export function useTelegramIntegration() {
  const feedback = useIntegrationFeedback();

  const loading = ref(true);
  const state = ref(null);
  const linking = ref(false);
  const unlinking = ref(false);
  let pollTimer = null;

  const configured = computed(() => Boolean(state.value?.configured));
  const connected = computed(() => Boolean(state.value?.connected));

  const status = computed(() => {
    if (connected.value) return { tone: 'ok', label: 'Підключено' };
    if (!configured.value) return { tone: 'off', label: 'Недоступно' };
    if (linking.value) return { tone: 'action', label: 'Чекаємо на Start' };
    return { tone: 'off', label: 'Не підключено' };
  });

  const meta = computed(() => {
    if (linking.value) return 'Відкрийте бота в Telegram і натисніть Start';
    if (connected.value) return 'Сповіщення про звіти й таски приходять у ваш Telegram';
    if (!configured.value) return 'Бота сповіщень ще не налаштував адміністратор';
    return 'Сповіщення про готові звіти і незаповнені таски';
  });

  async function load() {
    try {
      const { data } = await client.get('/telegram/status');
      state.value = data;
    } catch {
      // блок Telegram не критичний — без статусу просто показуємо «Недоступно»
    } finally {
      loading.value = false;
    }
  }

  async function connect() {
    linking.value = true;
    feedback.clear();
    try {
      const { data } = await client.post('/telegram/link');
      window.open(data.url, '_blank', 'noopener');
      poll(Date.now() + LINK_WAIT_MS);
    } catch (error) {
      linking.value = false;
      feedback.say(KEY, 'error', errorMessage(error, 'Не вдалося створити посилання на бота.'));
    }
  }

  function poll(deadline) {
    clearTimeout(pollTimer);
    pollTimer = setTimeout(async () => {
      await load();
      if (connected.value) {
        linking.value = false;
        feedback.say(KEY, 'ok', 'Telegram підключено, сповіщення активні.');
        return;
      }
      if (Date.now() < deadline) {
        poll(deadline);
      } else {
        linking.value = false;
        feedback.say(KEY, 'error', 'Не дочекались підтвердження. Натисніть «Підключити» ще раз і тисніть Start у боті.');
      }
    }, POLL_INTERVAL_MS);
  }

  function disconnect() {
    return feedback.run(KEY, unlinking, 'Не вдалося відключити Telegram.', async () => {
      const { data } = await client.delete('/telegram/link');
      feedback.closeManage();
      await load();
      return data.message;
    });
  }

  onMounted(load);
  onUnmounted(() => clearTimeout(pollTimer));

  return reactive({
    loading,
    state,
    linking,
    unlinking,
    configured,
    connected,
    status,
    meta,
    connect,
    disconnect,
  });
}
