import { computed, getCurrentScope, onScopeDispose, ref } from 'vue';
import client from '../../api/client';
import { errorMessage } from '../../utils/errors';
import { listSummary, startCommand } from '../../utils/settings';
import { useSettingsRow } from './useSettingsRow';

// Скільки чекаємо, поки адміністратор натисне Start у боті, і як часто питаємо.
const LINK_WAIT_MS = 120000;
const POLL_INTERVAL_MS = 3000;

/**
 * Технічний Telegram адміністратора: підсумок ранкового прогону і тривоги
 * про збої. Чати окремі від особистих сповіщень про звіти (сторінка
 * «Інтеграції»), кожен адміністратор підключає свої сам, і чатів може бути
 * кілька — особистий, спільна група підтримки, черговий канал.
 */
export function useOpsTelegram() {
  const { open, notice, say } = useSettingsRow();

  const loading = ref(true);
  const configured = ref(false);
  const chats = ref([]);
  const maxChats = ref(5);
  const linking = ref(false);
  // Команда для підключення групи: у групі deep-link не працює, туди треба
  // покликати бота й написати /start з кодом.
  const linkCommand = ref(null);
  // id чату, з яким зараз щось роблять: щоб крутилась лише його кнопка
  const testingId = ref(null);
  const removingId = ref(null);
  const confirmingRemove = ref(null);
  let pollTimer = null;
  let disposed = false;

  const connected = computed(() => chats.value.length > 0);
  const full = computed(() => chats.value.length >= maxChats.value);
  const busy = computed(() => testingId.value !== null || removingId.value !== null);

  const status = computed(() => {
    if (connected.value) {
      return {
        tone: 'ok',
        label: chats.value.length === 1 ? 'Підключено' : `Підключено: ${chats.value.length}`,
      };
    }
    if (!configured.value) return { tone: 'off', label: 'Недоступно' };
    if (linking.value) return { tone: 'action', label: 'Чекаємо на Start' };
    return { tone: 'off', label: 'Не підключено' };
  });

  const meta = computed(() => {
    if (linking.value && !connected.value) return 'Відкрийте бота в Telegram і натисніть Start';
    if (!configured.value) return 'Telegram-бот не налаштований на сервері';
    return listSummary(chats.value.map((chat) => chat.title), 'Збої сервісу й підсумок ранкової генерації звітів');
  });

  function apply(data) {
    configured.value = Boolean(data.configured);
    chats.value = data.chats || [];
    maxChats.value = data.max_chats || maxChats.value;
    // Чат могли прибрати з іншої вкладки — підтвердження на ньому вже не про що.
    if (!chats.value.some((chat) => chat.id === confirmingRemove.value)) confirmingRemove.value = null;
  }

  async function load() {
    try {
      const { data } = await client.get('/alerts/telegram');
      apply(data);
    } catch (error) {
      say('error', errorMessage(error, 'Не вдалося отримати стан технічних сповіщень.'));
    } finally {
      loading.value = false;
    }
  }

  function toggle() {
    confirmingRemove.value = null;
    open.value = !open.value;
  }

  async function connect() {
    linking.value = true;
    notice.value = null;
    const known = new Set(chats.value.map((chat) => chat.id));
    try {
      const { data } = await client.post('/alerts/telegram/link');
      linkCommand.value = startCommand(data.url);
      open.value = true;
      window.open(data.url, '_blank', 'noopener');
      poll(known, Date.now() + LINK_WAIT_MS);
    } catch (error) {
      linking.value = false;
      say('error', errorMessage(error, 'Не вдалося створити посилання на бота.'));
    }
  }

  // Полимо стан, поки адміністратор тисне Start у Telegram. Чекаємо саме
  // нового чату: у списку вже можуть бути підключені, а якийсь могли
  // прибрати з іншої вкладки — тож порівнюємо id, а не кількість.
  function poll(known, deadline) {
    clearTimeout(pollTimer);
    pollTimer = setTimeout(async () => {
      await load();
      if (disposed) return;

      const added = chats.value.find((chat) => !known.has(chat.id));
      if (added) {
        stopLinking();
        say('ok', `Чат «${added.title}» підключено.`);
      } else if (Date.now() < deadline) {
        poll(known, deadline);
      } else {
        stopLinking();
        say('error', 'Не дочекались підтвердження. Натисніть «Додати чат» ще раз і тисніть Start у боті.');
      }
    }, POLL_INTERVAL_MS);
  }

  function stopLinking() {
    clearTimeout(pollTimer);
    linking.value = false;
    linkCommand.value = null;
  }

  async function sendTest(chat) {
    testingId.value = chat.id;
    notice.value = null;
    try {
      const { data } = await client.post(`/alerts/telegram/${chat.id}/test`);
      say('ok', data.message);
    } catch (error) {
      say('error', error.response?.status === 429
        ? 'Забагато пробних повідомлень — зачекайте хвилину.'
        : errorMessage(error, 'Не вдалося надіслати пробне повідомлення.'));
    } finally {
      testingId.value = null;
    }
  }

  function cancelRemove() {
    confirmingRemove.value = null;
  }

  async function remove(chat) {
    // Прибрати останній чат — це лишитись без тривог зовсім; питаємо на місці.
    if (chats.value.length === 1 && confirmingRemove.value !== chat.id) {
      confirmingRemove.value = chat.id;
      return;
    }

    removingId.value = chat.id;
    notice.value = null;
    try {
      // Відповідь — уже новий список чатів, перепитувати його не треба.
      const { data } = await client.delete(`/alerts/telegram/${chat.id}`);
      apply(data);
      if (!connected.value) open.value = false;
      say('ok', data.message);
    } catch (error) {
      say('error', errorMessage(error, 'Не вдалося відключити чат.'));
    } finally {
      removingId.value = null;
    }
  }

  // Сторінку закрили посеред очікування — полити далі нема для кого.
  function dispose() {
    disposed = true;
    clearTimeout(pollTimer);
  }

  if (getCurrentScope()) onScopeDispose(dispose);

  return {
    loading,
    open,
    notice,
    configured,
    chats,
    maxChats,
    linking,
    linkCommand,
    testingId,
    removingId,
    confirmingRemove,
    connected,
    full,
    busy,
    status,
    meta,
    load,
    toggle,
    connect,
    sendTest,
    cancelRemove,
    remove,
    dispose,
  };
}
