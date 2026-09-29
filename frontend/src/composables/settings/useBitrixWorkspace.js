import { computed, ref } from 'vue';
import client from '../../api/client';
import { errorMessage } from '../../utils/errors';
import { useSettingsRow } from './useSettingsRow';

/**
 * Робоча область Бітрікс24 одна на команду, тому підключає її адміністратор:
 * портал і реквізити локального застосунку (OAuth 2.0). Доступ до тасок дає не
 * вона, а особистий токен кожного працівника — його видає сам Бітрікс.
 */
export function useBitrixWorkspace() {
  const { open, notice, say, run } = useSettingsRow();

  const loading = ref(true);
  const state = ref(null);
  const saving = ref(false);
  const disconnecting = ref(false);
  const form = ref({ portalUrl: '', clientId: '', clientSecret: '' });

  const connected = computed(() => Boolean(state.value?.connected));
  const portalLabel = computed(() => state.value?.portal_host || '');

  // Той самий redirect_uri треба вказати в налаштуваннях застосунку на порталі —
  // Бітрікс звіряє його побайтово, тож адресу дає бекенд, а не фронтенд.
  const redirectUri = computed(() => state.value?.redirect_uri || '');

  const status = computed(() => (connected.value
    ? { tone: 'ok', label: 'Підключено' }
    : { tone: 'off', label: 'Не підключено' }));

  const meta = computed(() => {
    if (!connected.value) return 'Один портал на всю команду — без нього працівники не зможуть обрати Бітрікс';
    return state.value.connected_by
      ? `${portalLabel.value} · підключив ${state.value.connected_by}`
      : portalLabel.value;
  });

  const canSubmit = computed(() => Object.values(form.value).every((value) => value.trim() !== ''));

  function toggle() {
    open.value = !open.value;
  }

  async function load() {
    try {
      const { data } = await client.get('/bitrix/workspace');
      state.value = data;
    } catch (error) {
      say('error', errorMessage(error, 'Не вдалося отримати стан Бітрікс24.'));
    } finally {
      loading.value = false;
    }
  }

  // Відповідь на зміну — уже новий стан порталу, перепитувати його не треба.
  function connect() {
    if (!canSubmit.value || saving.value) return Promise.resolve(false);
    return run(saving, 'Не вдалося підключити портал.', async () => {
      const { data } = await client.post('/bitrix/workspace', {
        portal_url: form.value.portalUrl.trim(),
        client_id: form.value.clientId.trim(),
        client_secret: form.value.clientSecret.trim(),
      });
      state.value = data;
      form.value.clientSecret = '';
      open.value = false;
      return data.message;
    });
  }

  function disconnect() {
    return run(disconnecting, 'Не вдалося відключити портал.', async () => {
      const { data } = await client.delete('/bitrix/workspace');
      state.value = data;
      open.value = false;
      return data.message;
    });
  }

  return {
    loading,
    state,
    open,
    notice,
    form,
    saving,
    disconnecting,
    connected,
    portalLabel,
    redirectUri,
    status,
    meta,
    canSubmit,
    load,
    toggle,
    connect,
    disconnect,
  };
}
