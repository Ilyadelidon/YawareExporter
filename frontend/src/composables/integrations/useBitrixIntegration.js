import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import client from '../../api/client';
import { useIntegrationLinksStore } from '../../stores/integrations';
import { errorMessage } from '../../utils/errors';
import { useIntegrationFeedback } from './useIntegrationFeedback';

const KEY = 'bitrix';

/**
 * Особистий акаунт працівника на порталі Бітрікс24 команди. Портал підключає
 * адміністратор у «Налаштуваннях», а працівник авторизується на ньому сам.
 */
export function useBitrixIntegration() {
  const feedback = useIntegrationFeedback();
  const sidebarLinks = useIntegrationLinksStore();
  const route = useRoute();
  const router = useRouter();

  const loading = ref(true);
  const state = ref(null);
  const authorizing = ref(false);
  const unlinking = ref(false);

  const workspaceConnected = computed(() => Boolean(state.value?.workspace_connected));
  const connected = computed(() => Boolean(state.value?.connected));

  // Пошта акаунта, під яким працівник увійшов у портал: за нею він упізнає,
  // що підключився правильним акаунтом. Якщо профіль порожній, лишається ім'я.
  const accountLabel = computed(() => state.value?.user_email || state.value?.user_name || '');

  const portalLabel = computed(() => state.value?.portal_host || 'портал');

  const status = computed(() => {
    if (!workspaceConnected.value) return { tone: 'off', label: 'Недоступно' };
    if (!connected.value) return { tone: 'off', label: 'Не підключено' };
    return { tone: 'ok', label: 'Підключено' };
  });

  const meta = computed(() => {
    if (!workspaceConnected.value) return 'Портал команди ще не підключив адміністратор';
    if (!state.value.user_id) return `${portalLabel.value} · увійдіть під своїм акаунтом`;
    return `${portalLabel.value} · ${accountLabel.value}`;
  });

  async function load() {
    try {
      const { data } = await client.get('/bitrix/status');
      state.value = data;
    } catch (error) {
      feedback.say(KEY, 'error', errorMessage(error, 'Не вдалося отримати стан Бітрікс24.'));
    } finally {
      loading.value = false;
    }
  }

  // Авторизація — повний перехід на портал і назад: акаунт визначає сам
  // Бітрікс за виданим токеном, тож вибрати чужий неможливо. Прапорець не
  // скидаємо після успіху — сторінка вже йде на портал.
  async function startAuth() {
    authorizing.value = true;
    feedback.clear();
    try {
      const { data } = await client.post('/bitrix/oauth/start');
      window.location.assign(data.url);
    } catch (error) {
      feedback.say(KEY, 'error', errorMessage(error, 'Не вдалося почати авторизацію в Бітріксі.'));
      authorizing.value = false;
    }
  }

  // Повернення з порталу: бекенд редіректить сюди з результатом у query.
  function readRedirect() {
    const { bitrix: result, bitrix_message: message, ...rest } = route.query;
    if (!result) return;

    if (result === 'connected') {
      feedback.say(KEY, 'ok', 'Бітрікс24 підключено: таски беруться з вашого акаунта на порталі.');
    } else {
      feedback.say(KEY, 'error', message || 'Не вдалося підключити Бітрікс24.');
    }

    // Прибираємо параметри, щоб перезавантаження не повторювало повідомлення.
    router.replace({ query: rest });
  }

  function unlink() {
    return feedback.run(KEY, unlinking, 'Не вдалося відвʼязати акаунт.', async () => {
      const { data } = await client.delete('/bitrix/user');
      feedback.closeManage();
      await load();
      sidebarLinks.load();
      return data.message;
    });
  }

  onMounted(() => {
    readRedirect();
    load();
  });

  return reactive({
    loading,
    state,
    authorizing,
    unlinking,
    workspaceConnected,
    connected,
    accountLabel,
    portalLabel,
    status,
    meta,
    startAuth,
    unlink,
  });
}
