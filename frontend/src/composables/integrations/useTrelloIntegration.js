import { computed, onMounted, onUnmounted, reactive, ref } from 'vue';
import client from '../../api/client';
import { TRELLO_CONNECTED_MESSAGE } from '../../constants/trackers';
import { useIntegrationLinksStore } from '../../stores/integrations';
import { errorMessage } from '../../utils/errors';
import { useIntegrationFeedback } from './useIntegrationFeedback';

const KEY = 'trello';

function authorizeUrl(apiKey) {
  return 'https://trello.com/1/authorize?' + new URLSearchParams({
    key: apiKey,
    name: 'TeamReporter',
    scope: 'read,write',
    expiration: 'never',
    response_type: 'token',
    callback_method: 'fragment',
    return_url: `${window.location.origin}/trello/callback`,
  });
}

/**
 * Trello працівника: власний акаунт (токен з popup-авторизації) і активна
 * дошка, з якої звіт бере таски.
 */
export function useTrelloIntegration() {
  const feedback = useIntegrationFeedback();
  const sidebarLinks = useIntegrationLinksStore();

  const loading = ref(true);
  const state = ref(null);
  const boards = ref([]);
  const boardsLoading = ref(false);
  const selectingBoard = ref(false);
  const creatingBoard = ref(false);
  const disconnecting = ref(false);

  const connected = computed(() => Boolean(state.value?.connected));

  const boardLabel = computed(() => state.value?.board_name || state.value?.board_id || '');

  const status = computed(() => {
    if (!connected.value) return { tone: 'off', label: 'Не підключено' };
    if (!state.value.board_id) return { tone: 'action', label: 'Оберіть дошку' };
    return { tone: 'ok', label: 'Підключено' };
  });

  const meta = computed(() => {
    if (!connected.value) return 'Власний акаунт і дошка, таски з неї потрапляють у звіт';
    const user = `@${state.value.username}`;
    if (state.value.board_id) return `${user} · дошка «${boardLabel.value}»`;
    return `${user} · дошку ще не обрано`;
  });

  async function load() {
    try {
      const { data } = await client.get('/trello/status');
      state.value = data;
      if (data.connected) loadBoards();
    } catch (error) {
      feedback.say(KEY, 'error', errorMessage(error, 'Не вдалося отримати стан Trello.'));
    } finally {
      loading.value = false;
    }
  }

  async function loadBoards() {
    boardsLoading.value = true;
    try {
      const { data } = await client.get('/trello/boards');
      boards.value = data.data;
    } catch (error) {
      feedback.say(KEY, 'error', errorMessage(error, 'Не вдалося отримати список дощок.'));
    } finally {
      boardsLoading.value = false;
    }
  }

  // Після зміни підключення чи дошки: свіжий стан і кнопка в боковому меню.
  async function refresh() {
    await load();
    sidebarLinks.load();
  }

  function connect() {
    feedback.clear();
    window.open(authorizeUrl(state.value.api_key), 'trello-auth', 'width=580,height=720');
  }

  // Popup після збереження токена шле postMessage — оновлюємо стан без
  // перезавантаження і одразу відкриваємо налаштування, щоб обрати дошку.
  async function onAuthMessage(event) {
    if (event.origin !== window.location.origin || event.data?.type !== TRELLO_CONNECTED_MESSAGE) return;
    feedback.say(KEY, 'ok', `Trello підключено як @${event.data.username}. Оберіть або створіть дошку.`);
    await refresh();
    feedback.openManage(KEY);
  }

  function disconnect() {
    return feedback.run(KEY, disconnecting, 'Не вдалося відключити Trello.', async () => {
      await client.delete('/trello/token');
      boards.value = [];
      feedback.closeManage();
      await refresh();
      return 'Trello відключено.';
    });
  }

  function selectBoard(boardId) {
    return feedback.run(KEY, selectingBoard, 'Не вдалося обрати дошку.', async () => {
      const { data } = await client.put('/trello/board', { board_id: boardId });
      await refresh();
      return `Активна дошка: «${data.board.name}».`;
    });
  }

  function createBoard(name) {
    return feedback.run(KEY, creatingBoard, 'Не вдалося створити дошку.', async () => {
      const { data } = await client.post('/trello/boards', { name });
      await refresh();
      return `Дошку «${data.board.name}» створено і зроблено активною.`;
    });
  }

  onMounted(() => {
    window.addEventListener('message', onAuthMessage);
    load();
  });

  onUnmounted(() => {
    window.removeEventListener('message', onAuthMessage);
  });

  return reactive({
    loading,
    state,
    boards,
    boardsLoading,
    selectingBoard,
    creatingBoard,
    disconnecting,
    connected,
    boardLabel,
    status,
    meta,
    connect,
    disconnect,
    selectBoard,
    createBoard,
  });
}
