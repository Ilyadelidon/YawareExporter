<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import Select from 'primevue/select';
import client from '../api/client';
import { useAuthStore } from '../stores/auth';
import { useIntegrationLinksStore } from '../stores/integrations';
import IntegrationRow from './IntegrationRow.vue';
import '../styles/integrations-ui.css';

const auth = useAuthStore();
// Кнопки переходу в боковому меню беруть стан звідси — оновлюємо його
// разом зі статусами, щоб меню не відставало від щойно зробленого підключення.
const sidebarLinks = useIntegrationLinksStore();
const route = useRoute();
const router = useRouter();

// null | 'trello' | 'bitrix' | 'sheets' | 'telegram' — чиї налаштування розгорнуті
const managing = ref(null);

// Повідомлення про результат дії показується під рядком тієї інтеграції,
// якої воно стосується: { key, tone: 'ok' | 'error', text }.
const notice = ref(null);

// Ключ дії, яка чекає на підтвердження («Так, відключити» / «Скасувати»).
const confirming = ref(null);

function say(key, tone, text) {
  notice.value = { key, tone, text };
}

function noticeFor(key) {
  return notice.value?.key === key ? notice.value : null;
}

function resetFeedback() {
  notice.value = null;
  confirming.value = null;
}

function toggleManage(which) {
  confirming.value = null;
  managing.value = managing.value === which ? null : which;
}

// ─── Таск-трекер ────────────────────────────────────────────────────────

// Активний таск-трекер користувача. Перемикання нічого не відв'язує:
// налаштування обох провайдерів лишаються на місці.
const provider = ref(auth.user?.task_provider || 'trello');
const providerSwitching = ref(false);
const isBitrix = computed(() => provider.value === 'bitrix');

const loading = ref(true);
const status = ref(null);
const boards = ref([]);
const boardsLoading = ref(false);
const selectedBoardId = ref(null);
const newBoardName = ref('');
const selectingBoard = ref(false);
const creatingBoard = ref(false);
const disconnecting = ref(false);

const connected = computed(() => Boolean(status.value?.connected));

const bitrixLoading = ref(true);
const bitrix = ref(null);
const bitrixAuthorizing = ref(false);
const bitrixUnlinking = ref(false);

const bitrixConnected = computed(() => Boolean(bitrix.value?.connected));
// Бітрікс не можна зробити активним, поки адміністратор не підключив портал команди.
const bitrixSwitchBlocked = computed(() => !bitrix.value?.workspace_connected);

const trelloStatus = computed(() => {
  if (!connected.value) return { tone: 'off', label: 'Не підключено' };
  if (!status.value.board_id) return { tone: 'action', label: 'Оберіть дошку' };
  return { tone: 'ok', label: 'Підключено' };
});

const trelloMeta = computed(() => {
  if (!connected.value) return 'Власний акаунт і дошка, таски з неї потрапляють у звіт';
  const user = `@${status.value.username}`;
  if (status.value.board_id) return `${user} · дошка «${status.value.board_name || status.value.board_id}»`;
  return `${user} · дошку ще не обрано`;
});

// Пошта акаунта, під яким працівник увійшов у портал: за нею він упізнає,
// що підключився правильним акаунтом. Якщо профіль порожній, лишається ім'я.
const bitrixAccountLabel = computed(
  () => bitrix.value?.user_email || bitrix.value?.user_name || '',
);

const bitrixPortalLabel = computed(
  () => bitrix.value?.portal_url?.replace(/^https:\/\//, '').replace(/\/$/, '') || 'портал',
);

const bitrixStatus = computed(() => {
  if (!bitrix.value?.workspace_connected) return { tone: 'off', label: 'Недоступно' };
  if (!bitrixConnected.value) return { tone: 'off', label: 'Не підключено' };
  return { tone: 'ok', label: 'Підключено' };
});

const bitrixMeta = computed(() => {
  if (!bitrix.value?.workspace_connected) return 'Портал команди ще не підключив адміністратор';
  if (!bitrix.value.user_id) return `${bitrixPortalLabel.value} · увійдіть під своїм акаунтом`;
  return `${bitrixPortalLabel.value} · ${bitrixAccountLabel.value}`;
});

async function loadStatus() {
  try {
    const { data } = await client.get('/trello/status');
    status.value = data;
    sidebarLinks.load();
    if (data.connected) loadBoards();
  } catch (error) {
    say('trello', 'error', error.response?.data?.message || 'Не вдалося отримати стан Trello.');
  } finally {
    loading.value = false;
  }
}

async function loadBoards() {
  boardsLoading.value = true;
  try {
    const { data } = await client.get('/trello/boards');
    boards.value = data.data;
    selectedBoardId.value = status.value?.board_id || null;
  } catch (error) {
    say('trello', 'error', error.response?.data?.message || 'Не вдалося отримати список дощок.');
  } finally {
    boardsLoading.value = false;
  }
}

function connect() {
  resetFeedback();
  const returnUrl = `${window.location.origin}/trello/callback`;
  const url = 'https://trello.com/1/authorize?' + new URLSearchParams({
    key: status.value.api_key,
    name: 'TeamReporter',
    scope: 'read,write',
    expiration: 'never',
    response_type: 'token',
    callback_method: 'fragment',
    return_url: returnUrl,
  });
  window.open(url, 'trello-auth', 'width=580,height=720');
}

// Popup після успішного збереження токена шле postMessage — оновлюємо статус без
// перезавантаження і одразу відкриваємо налаштування, щоб обрати дошку.
async function onAuthMessage(event) {
  if (event.origin !== window.location.origin || event.data?.type !== 'trello-connected') return;
  say('trello', 'ok', `Trello підключено як @${event.data.username}. Оберіть або створіть дошку.`);
  await loadStatus();
  managing.value = 'trello';
}

async function disconnect() {
  disconnecting.value = true;
  resetFeedback();
  try {
    await client.delete('/trello/token');
    boards.value = [];
    selectedBoardId.value = null;
    managing.value = null;
    say('trello', 'ok', 'Trello відключено.');
    await loadStatus();
  } catch (error) {
    say('trello', 'error', error.response?.data?.message || 'Не вдалося відключити Trello.');
  } finally {
    disconnecting.value = false;
  }
}

async function selectBoard() {
  if (!selectedBoardId.value) return;
  selectingBoard.value = true;
  resetFeedback();
  try {
    const { data } = await client.put('/trello/board', { board_id: selectedBoardId.value });
    say('trello', 'ok', `Активна дошка: «${data.board.name}».`);
    await loadStatus();
  } catch (error) {
    say('trello', 'error', error.response?.data?.message || 'Не вдалося обрати дошку.');
  } finally {
    selectingBoard.value = false;
  }
}

async function createBoard() {
  if (!newBoardName.value.trim() || creatingBoard.value) return;
  creatingBoard.value = true;
  resetFeedback();
  try {
    const { data } = await client.post('/trello/boards', { name: newBoardName.value.trim() });
    say('trello', 'ok', `Дошку «${data.board.name}» створено і зроблено активною.`);
    newBoardName.value = '';
    await loadStatus();
  } catch (error) {
    say('trello', 'error', error.response?.data?.message || 'Не вдалося створити дошку.');
  } finally {
    creatingBoard.value = false;
  }
}

async function loadBitrixStatus() {
  try {
    const { data } = await client.get('/bitrix/status');
    bitrix.value = data;
    sidebarLinks.load();
  } catch (error) {
    say('bitrix', 'error', error.response?.data?.message || 'Не вдалося отримати стан Бітрікс24.');
  } finally {
    bitrixLoading.value = false;
  }
}

// Авторизація в Бітріксі — повний перехід на портал і назад: акаунт визначає
// сам Бітрікс за виданим токеном, тож вибрати чужий неможливо.
async function startBitrixAuth() {
  bitrixAuthorizing.value = true;
  resetFeedback();
  try {
    const { data } = await client.post('/bitrix/oauth/start');
    window.location.assign(data.url);
  } catch (error) {
    say('bitrix', 'error', error.response?.data?.message || 'Не вдалося почати авторизацію в Бітріксі.');
    bitrixAuthorizing.value = false;
  }
}

// Повернення з порталу: бекенд редіректить сюди з результатом у query.
function readBitrixRedirect() {
  const { bitrix: result, bitrix_message: message, ...rest } = route.query;
  if (!result) return;

  if (result === 'connected') {
    say('bitrix', 'ok', 'Бітрікс24 підключено: таски беруться з вашого акаунта на порталі.');
  } else {
    say('bitrix', 'error', message || 'Не вдалося підключити Бітрікс24.');
  }

  // Прибираємо параметри, щоб перезавантаження не повторювало повідомлення.
  router.replace({ query: rest });
}

async function unlinkBitrixUser() {
  bitrixUnlinking.value = true;
  resetFeedback();
  try {
    const { data } = await client.delete('/bitrix/user');
    managing.value = null;
    say('bitrix', 'ok', data.message);
    await loadBitrixStatus();
  } catch (error) {
    say('bitrix', 'error', error.response?.data?.message || 'Не вдалося відвʼязати акаунт.');
  } finally {
    bitrixUnlinking.value = false;
  }
}

// Перемикання трекера: звіти після нього беруть таски з іншого джерела,
// але налаштування попереднього лишаються — можна повернутись назад.
async function switchProvider(next) {
  if (next === provider.value || providerSwitching.value) return;
  if (next === 'bitrix' && bitrixSwitchBlocked.value) return;
  providerSwitching.value = true;
  resetFeedback();
  try {
    const { data } = await client.put('/tasks/provider', { provider: next });
    provider.value = data.provider;
    if (auth.user) auth.user.task_provider = data.provider;
    sidebarLinks.load();
    const ready = data.provider === 'bitrix' ? bitrixConnected.value : connected.value;
    // Якщо новий трекер ще не готовий до звітів — підказуємо, що зробити далі.
    say(data.provider, 'ok', ready
      ? data.message
      : `${data.message} Підключіть акаунт, інакше таски у звіт не потраплять.`);
  } catch (error) {
    say(next, 'error', error.response?.data?.message || 'Не вдалося перемкнути таск-трекер.');
  } finally {
    providerSwitching.value = false;
  }
}

// ─── Google Таблиця ─────────────────────────────────────────────────────

const googleLoading = ref(true);
const google = ref(null);
const sheetMode = ref('create'); // 'create' | 'link'
const sheetName = ref('');
const sheetEmail = ref('');
const sheetLink = ref('');
const googleSaving = ref(false);
const googleLinking = ref(false);
const googleDetaching = ref(false);

const hasSpreadsheet = computed(() => Boolean(google.value?.spreadsheet_id));

const sheetsStatus = computed(() => {
  if (!google.value?.account_connected) return { tone: 'off', label: 'Недоступно' };
  if (!hasSpreadsheet.value) return { tone: 'off', label: 'Не підключено' };
  if (!google.value.spreadsheet_title) return { tone: 'error', label: 'Немає доступу' };
  return { tone: 'ok', label: 'Підключено' };
});

const sheetsMeta = computed(() => {
  if (!google.value?.account_connected) return 'Google-акаунт сервісу ще не авторизував адміністратор';
  if (!hasSpreadsheet.value) return 'Куди вивантажується готовий звіт';
  return google.value.spreadsheet_title || 'Таблицю не вдалося прочитати';
});

async function loadGoogleStatus() {
  try {
    const { data } = await client.get('/google/status');
    google.value = data;
    sidebarLinks.load();
    if (!sheetName.value) sheetName.value = `Звіти — ${auth.user?.name || ''}`.trim();
    if (!sheetEmail.value) sheetEmail.value = auth.user?.email || '';
  } catch (error) {
    say('sheets', 'error', error.response?.data?.message || 'Не вдалося отримати стан Google-інтеграції.');
  } finally {
    googleLoading.value = false;
  }
}

async function createSpreadsheet() {
  googleSaving.value = true;
  resetFeedback();
  try {
    const { data } = await client.post('/google/spreadsheet', {
      name: sheetName.value.trim() || null,
      email: sheetEmail.value.trim() || null,
    });
    managing.value = null;
    say('sheets', 'ok', data.message);
    await loadGoogleStatus();
  } catch (error) {
    say('sheets', 'error', error.response?.data?.message || 'Не вдалося створити таблицю.');
  } finally {
    googleSaving.value = false;
  }
}

async function linkSpreadsheet() {
  if (!sheetLink.value.trim() || googleLinking.value) return;
  googleLinking.value = true;
  resetFeedback();
  try {
    const { data } = await client.post('/google/spreadsheet/link', {
      spreadsheet: sheetLink.value.trim(),
    });
    sheetLink.value = '';
    managing.value = null;
    say('sheets', 'ok', data.message);
    await loadGoogleStatus();
  } catch (error) {
    say('sheets', 'error', error.response?.data?.message || 'Не вдалося підключити таблицю.');
  } finally {
    googleLinking.value = false;
  }
}

async function detachSpreadsheet() {
  googleDetaching.value = true;
  resetFeedback();
  try {
    const { data } = await client.delete('/google/spreadsheet');
    managing.value = null;
    say('sheets', 'ok', data.message);
    await loadGoogleStatus();
  } catch (error) {
    say('sheets', 'error', error.response?.data?.message || 'Не вдалося відв\'язати таблицю.');
  } finally {
    googleDetaching.value = false;
  }
}

// ─── Telegram ───────────────────────────────────────────────────────────

const telegramLoading = ref(true);
const telegram = ref(null);
const telegramLinking = ref(false);
const telegramUnlinking = ref(false);
let telegramPollTimer = null;

const telegramConnected = computed(() => Boolean(telegram.value?.connected));

const telegramStatus = computed(() => {
  if (telegramConnected.value) return { tone: 'ok', label: 'Підключено' };
  if (!telegram.value?.configured) return { tone: 'off', label: 'Недоступно' };
  if (telegramLinking.value) return { tone: 'action', label: 'Чекаємо на Start' };
  return { tone: 'off', label: 'Не підключено' };
});

const telegramMeta = computed(() => {
  if (telegramLinking.value) return 'Відкрийте бота в Telegram і натисніть Start';
  if (telegramConnected.value) return 'Сповіщення про звіти й таски приходять у ваш Telegram';
  if (!telegram.value?.configured) return 'Бота сповіщень ще не налаштував адміністратор';
  return 'Сповіщення про готові звіти і незаповнені таски';
});

async function loadTelegramStatus() {
  try {
    const { data } = await client.get('/telegram/status');
    telegram.value = data;
  } catch {
    // блок Telegram не критичний — без статусу просто показуємо «Недоступно»
  } finally {
    telegramLoading.value = false;
  }
}

async function connectTelegram() {
  telegramLinking.value = true;
  resetFeedback();
  try {
    const { data } = await client.post('/telegram/link');
    window.open(data.url, '_blank', 'noopener');
    pollTelegram(Date.now() + 120000);
  } catch (error) {
    telegramLinking.value = false;
    say('telegram', 'error', error.response?.data?.message || 'Не вдалося створити посилання на бота.');
  }
}

// Полимо статус, поки користувач тисне Start у Telegram (до 2 хв).
function pollTelegram(deadline) {
  clearTimeout(telegramPollTimer);
  telegramPollTimer = setTimeout(async () => {
    await loadTelegramStatus();
    if (telegramConnected.value) {
      telegramLinking.value = false;
      say('telegram', 'ok', 'Telegram підключено, сповіщення активні.');
      return;
    }
    if (Date.now() < deadline) {
      pollTelegram(deadline);
    } else {
      telegramLinking.value = false;
      say('telegram', 'error', 'Не дочекались підтвердження. Натисніть «Підключити» ще раз і тисніть Start у боті.');
    }
  }, 3000);
}

async function disconnectTelegram() {
  telegramUnlinking.value = true;
  resetFeedback();
  try {
    const { data } = await client.delete('/telegram/link');
    managing.value = null;
    say('telegram', 'ok', data.message);
    await loadTelegramStatus();
  } catch (error) {
    say('telegram', 'error', error.response?.data?.message || 'Не вдалося відключити Telegram.');
  } finally {
    telegramUnlinking.value = false;
  }
}

onMounted(() => {
  window.addEventListener('message', onAuthMessage);
  readBitrixRedirect();
  loadStatus();
  loadBitrixStatus();
  loadGoogleStatus();
  loadTelegramStatus();
});

onUnmounted(() => {
  window.removeEventListener('message', onAuthMessage);
  clearTimeout(telegramPollTimer);
});
</script>

<template>
  <div class="integrations int-ui">
    <!-- ── Таск-трекер ─────────────────────────────────────────── -->
    <section class="int-section" aria-labelledby="sec-tracker">
      <header class="section-head">
        <div>
          <h2 id="sec-tracker" class="section-title">Таск-трекер</h2>
          <p class="section-desc">Звідки звіт бере завдання за день. Налаштування неактивного трекера зберігаються.</p>
        </div>

        <div class="source">
          <span id="source-label" class="source-label">Таски для звіту з</span>
          <div class="segmented" role="radiogroup" aria-labelledby="source-label">
            <button
              type="button"
              role="radio"
              class="segmented-opt"
              :aria-checked="!isBitrix"
              :disabled="loading || bitrixLoading || providerSwitching"
              @click="switchProvider('trello')"
            >Trello</button>
            <button
              type="button"
              role="radio"
              class="segmented-opt"
              :aria-checked="isBitrix"
              :disabled="loading || bitrixLoading || providerSwitching || bitrixSwitchBlocked"
              :title="bitrixSwitchBlocked && !bitrixLoading ? 'Портал команди ще не підключив адміністратор' : null"
              @click="switchProvider('bitrix')"
            >Бітрікс24</button>
          </div>
        </div>
      </header>

      <div class="panel int-list" :aria-busy="loading || bitrixLoading">
        <template v-if="loading || bitrixLoading">
          <div v-for="n in 2" :key="n" class="row-skeleton"><div class="skeleton"></div></div>
        </template>

        <template v-else>
          <!-- Trello -->
          <IntegrationRow
            id="trello"
            name="Trello"
            :meta="trelloMeta"
            :status="trelloStatus"
            :tag="!isBitrix ? 'Активний' : ''"
            :open="managing === 'trello' && connected"
            :notice="noticeFor('trello')"
          >
            <template #icon>
              <svg width="18" height="18" viewBox="0 0 24 24"><rect x="2" y="2" width="20" height="20" fill="#0079BF"></rect><rect x="4.5" y="4.5" width="6.5" height="10.5" fill="#ffffff"></rect><rect x="13" y="4.5" width="6.5" height="6.5" fill="#ffffff"></rect></svg>
            </template>

            <template #action>
              <button
                v-if="connected"
                type="button"
                class="btn btn-secondary"
                :aria-expanded="managing === 'trello'"
                aria-controls="int-panel-trello"
                @click="toggleManage('trello')"
              >
                Налаштування
                <svg class="chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><polyline points="6 9 12 15 18 9"></polyline></svg>
              </button>
              <button v-else type="button" class="btn btn-primary" @click="connect">Підключити</button>
            </template>

            <p v-if="isBitrix" class="panel-hint is-top">
              Зараз таски беруться з Бітрікс24. Дошка Trello зберігається, але у звіт не потрапляє.
            </p>

            <div class="fields">
              <div class="field">
                <div class="field-label">Акаунт</div>
                <div class="field-control">
                  <span class="field-value">@{{ status.username }}</span>
                </div>
              </div>

              <div class="field">
                <label class="field-label" for="trello-board">Активна дошка</label>
                <div class="field-control">
                  <div class="control-row">
                    <Select
                      v-model="selectedBoardId"
                      input-id="trello-board"
                      :options="boards"
                      option-label="name"
                      option-value="id"
                      :loading="boardsLoading"
                      placeholder="Оберіть дошку"
                      class="control-grow"
                    />
                    <button
                      type="button"
                      class="btn btn-secondary"
                      :disabled="selectingBoard || !selectedBoardId || selectedBoardId === status.board_id"
                      @click="selectBoard"
                    >
                      {{ selectingBoard ? 'Зберігаємо…' : 'Зберегти' }}
                    </button>
                  </div>
                  <a
                    v-if="status.board_url"
                    :href="status.board_url"
                    target="_blank"
                    rel="noopener"
                    class="field-link"
                  >Відкрити «{{ status.board_name || status.board_id }}» у Trello ↗</a>
                </div>
              </div>

              <div class="field">
                <label class="field-label" for="trello-new-board">Нова дошка</label>
                <div class="field-control">
                  <div class="control-row">
                    <input
                      id="trello-new-board"
                      v-model="newBoardName"
                      type="text"
                      class="input control-grow"
                      placeholder="Назва дошки"
                      maxlength="255"
                      @keyup.enter="createBoard"
                    />
                    <button
                      type="button"
                      class="btn btn-secondary"
                      :disabled="creatingBoard || !newBoardName.trim()"
                      @click="createBoard"
                    >
                      {{ creatingBoard ? 'Створюємо…' : 'Створити' }}
                    </button>
                  </div>
                  <p class="field-hint">Створюється зі списками й мітками з шаблону і одразу стає активною.</p>
                </div>
              </div>
            </div>

            <div class="danger">
              <template v-if="confirming !== 'trello'">
                <button type="button" class="btn btn-danger-ghost" @click="confirming = 'trello'">Відключити Trello</button>
              </template>
              <template v-else>
                <span class="danger-text">Токен буде відкликано, обрану дошку скинуто.</span>
                <div class="danger-actions">
                  <button type="button" class="btn btn-secondary" :disabled="disconnecting" @click="confirming = null">Скасувати</button>
                  <button type="button" class="btn btn-danger" :disabled="disconnecting" @click="disconnect">
                    {{ disconnecting ? 'Відключаємо…' : 'Так, відключити' }}
                  </button>
                </div>
              </template>
            </div>
          </IntegrationRow>

          <!-- Бітрікс24 -->
          <IntegrationRow
            id="bitrix"
            name="Бітрікс24"
            :meta="bitrixMeta"
            :status="bitrixStatus"
            :tag="isBitrix ? 'Активний' : ''"
            :open="managing === 'bitrix' && bitrixConnected"
            :notice="noticeFor('bitrix')"
          >
            <template #icon>
              <svg width="18" height="18" viewBox="0 0 24 24"><rect x="2" y="2" width="20" height="20" fill="#1B65A6"></rect><circle cx="12" cy="12" r="6.2" fill="none" stroke="#ffffff" stroke-width="2.4"></circle><circle cx="12" cy="12" r="2" fill="#ffffff"></circle></svg>
            </template>

            <template #action>
              <button
                v-if="bitrixConnected"
                type="button"
                class="btn btn-secondary"
                :aria-expanded="managing === 'bitrix'"
                aria-controls="int-panel-bitrix"
                @click="toggleManage('bitrix')"
              >
                Налаштування
                <svg class="chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><polyline points="6 9 12 15 18 9"></polyline></svg>
              </button>
              <button
                v-else-if="bitrix?.workspace_connected"
                type="button"
                class="btn btn-primary"
                :disabled="bitrixAuthorizing"
                title="Вас перекине на портал команди, де треба увійти й підтвердити доступ"
                @click="startBitrixAuth"
              >
                {{ bitrixAuthorizing ? 'Переходимо…' : 'Увійти' }}
              </button>
            </template>

            <p v-if="!isBitrix" class="panel-hint is-top">
              Зараз таски беруться з Trello. Щоб брати їх з Бітрікс24, перемкніть джерело вгорі.
            </p>

            <div class="fields">
              <div class="field">
                <div class="field-label">Портал</div>
                <div class="field-control">
                  <a :href="bitrix.portal_url" target="_blank" rel="noopener" class="field-link is-inline">{{ bitrixPortalLabel }} ↗</a>
                </div>
              </div>

              <div class="field">
                <div class="field-label">Ваш акаунт</div>
                <div class="field-control">
                  <div class="control-row">
                    <span class="field-value control-grow">{{ bitrixAccountLabel }}</span>
                    <button type="button" class="btn btn-secondary" :disabled="bitrixAuthorizing" @click="startBitrixAuth">
                      {{ bitrixAuthorizing ? 'Переходимо…' : 'Оновити доступ' }}
                    </button>
                  </div>
                  <p class="field-hint">
                    У звіт потрапляють таски, де ви відповідальний, з плановими датами на цей день.
                    Запити йдуть від вашого імені, чужих тасків сервіс не бачить.
                  </p>
                </div>
              </div>
            </div>

            <div class="danger">
              <template v-if="confirming !== 'bitrix'">
                <button type="button" class="btn btn-danger-ghost" @click="confirming = 'bitrix'">Відвʼязати акаунт</button>
              </template>
              <template v-else>
                <span class="danger-text">Портал команди залишиться підключеним.</span>
                <div class="danger-actions">
                  <button type="button" class="btn btn-secondary" :disabled="bitrixUnlinking" @click="confirming = null">Скасувати</button>
                  <button type="button" class="btn btn-danger" :disabled="bitrixUnlinking" @click="unlinkBitrixUser">
                    {{ bitrixUnlinking ? 'Відвʼязуємо…' : 'Так, відвʼязати' }}
                  </button>
                </div>
              </template>
            </div>
          </IntegrationRow>
        </template>
      </div>
    </section>

    <!-- ── Звіти та сповіщення ─────────────────────────────────── -->
    <section class="int-section" aria-labelledby="sec-delivery">
      <header class="section-head">
        <div>
          <h2 id="sec-delivery" class="section-title">Звіти та сповіщення</h2>
          <p class="section-desc">Куди потрапляє готовий звіт і як про нього дізнатися.</p>
        </div>
      </header>

      <div class="panel int-list" :aria-busy="googleLoading || telegramLoading">
        <!-- Google Таблиця -->
        <div v-if="googleLoading" class="row-skeleton"><div class="skeleton"></div></div>
        <IntegrationRow
          v-else
          id="sheets"
          name="Google Таблиця"
          :meta="sheetsMeta"
          :status="sheetsStatus"
          :open="managing === 'sheets' && google?.account_connected"
          :notice="noticeFor('sheets')"
        >
          <template #icon>
            <svg width="18" height="18" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" fill="#0F9D58"></path><path d="M14 2v6h6" fill="#8ED1A6"></path><rect x="7" y="12" width="10" height="1.6" fill="#ffffff"></rect><rect x="7" y="15.2" width="10" height="1.6" fill="#ffffff"></rect><rect x="7" y="18.4" width="6" height="1.6" fill="#ffffff"></rect></svg>
          </template>

          <template #action>
            <template v-if="google?.account_connected">
              <button
                type="button"
                class="btn"
                :class="hasSpreadsheet ? 'btn-secondary' : 'btn-primary'"
                :aria-expanded="managing === 'sheets'"
                aria-controls="int-panel-sheets"
                @click="toggleManage('sheets')"
              >
                {{ hasSpreadsheet ? 'Налаштування' : 'Підключити' }}
                <svg class="chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><polyline points="6 9 12 15 18 9"></polyline></svg>
              </button>
            </template>
          </template>

          <!-- Таблиця вже є -->
          <template v-if="hasSpreadsheet">
            <div class="fields">
              <div class="field">
                <div class="field-label">Таблиця</div>
                <div class="field-control">
                  <template v-if="google.spreadsheet_title">
                    <span class="field-value">{{ google.spreadsheet_title }}</span>
                    <a
                      v-if="google.spreadsheet_url"
                      :href="google.spreadsheet_url"
                      target="_blank"
                      rel="noopener"
                      class="field-link"
                    >Відкрити в Google Таблицях ↗</a>
                    <p class="field-hint">Звіт за день додається окремою вкладкою, таски розносяться по місячному аркушу.</p>
                  </template>
                  <p v-else class="field-error">
                    Таблицю не вдалося прочитати: її видалили або забрали доступ.
                    Відвʼяжіть її і створіть чи підключіть іншу.
                  </p>
                </div>
              </div>
            </div>

            <div class="danger">
              <template v-if="confirming !== 'sheets'">
                <button type="button" class="btn btn-danger-ghost" @click="confirming = 'sheets'">Відвʼязати таблицю</button>
              </template>
              <template v-else>
                <span class="danger-text">Файл залишиться, але нові звіти не вивантажуватимуться.</span>
                <div class="danger-actions">
                  <button type="button" class="btn btn-secondary" :disabled="googleDetaching" @click="confirming = null">Скасувати</button>
                  <button type="button" class="btn btn-danger" :disabled="googleDetaching" @click="detachSpreadsheet">
                    {{ googleDetaching ? 'Відвʼязуємо…' : 'Так, відвʼязати' }}
                  </button>
                </div>
              </template>
            </div>
          </template>

          <!-- Таблиці ще немає: створити нову або підключити наявну -->
          <template v-else>
            <div class="segmented is-small" role="tablist" aria-label="Спосіб підключення таблиці">
              <button
                type="button"
                role="tab"
                class="segmented-opt"
                :aria-selected="sheetMode === 'create'"
                @click="sheetMode = 'create'"
              >Створити нову</button>
              <button
                type="button"
                role="tab"
                class="segmented-opt"
                :aria-selected="sheetMode === 'link'"
                @click="sheetMode = 'link'"
              >Підключити наявну</button>
            </div>

            <form v-if="sheetMode === 'create'" class="fields" @submit.prevent="createSpreadsheet">
              <div class="field">
                <label class="field-label" for="sheet-name">Назва</label>
                <div class="field-control">
                  <input id="sheet-name" v-model="sheetName" type="text" class="input" maxlength="255" />
                </div>
              </div>
              <div class="field">
                <label class="field-label" for="sheet-email">Email редактора</label>
                <div class="field-control">
                  <input id="sheet-email" v-model="sheetEmail" type="email" class="input" placeholder="email@example.com" maxlength="255" />
                  <p class="field-hint">Цей email отримає доступ редактора і посилання на таблицю.</p>
                </div>
              </div>
              <div class="field">
                <div></div>
                <div class="field-control">
                  <button type="submit" class="btn btn-primary" :disabled="googleSaving">
                    {{ googleSaving ? 'Створюємо…' : 'Створити таблицю' }}
                  </button>
                </div>
              </div>
            </form>

            <form v-else class="fields" @submit.prevent="linkSpreadsheet">
              <div class="field">
                <label class="field-label" for="sheet-link">Посилання</label>
                <div class="field-control">
                  <input
                    id="sheet-link"
                    v-model="sheetLink"
                    type="text"
                    class="input"
                    placeholder="https://docs.google.com/spreadsheets/d/…"
                    maxlength="2048"
                  />
                  <p class="field-hint">
                    Спершу в таблиці натисніть «Поділитися» і дайте доступ <strong>редактора</strong><template v-if="google.account_email">
                    акаунту <strong>{{ google.account_email }}</strong></template>.
                    Наявні аркуші не зміняться, звіти додаватимуться окремими вкладками.
                  </p>
                </div>
              </div>
              <div class="field">
                <div></div>
                <div class="field-control">
                  <button type="submit" class="btn btn-primary" :disabled="googleLinking || !sheetLink.trim()">
                    {{ googleLinking ? 'Перевіряємо доступ…' : 'Підключити таблицю' }}
                  </button>
                </div>
              </div>
            </form>
          </template>
        </IntegrationRow>

        <!-- Telegram -->
        <div v-if="telegramLoading" class="row-skeleton"><div class="skeleton"></div></div>
        <IntegrationRow
          v-else
          id="telegram"
          name="Telegram"
          :meta="telegramMeta"
          :status="telegramStatus"
          :open="managing === 'telegram' && telegramConnected"
          :notice="noticeFor('telegram')"
        >
          <template #icon>
            <svg width="18" height="18" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" fill="#229ED9"></circle><path d="M6.2 11.6l9.8-3.9c.5-.2.9.1.7.9l-1.6 7.8c-.1.6-.5.7-1 .4l-2.5-1.9-1.2 1.2c-.2.2-.4.3-.7.3l.2-2.6 4.8-4.4c.2-.2 0-.3-.3-.1l-6 3.8-2.5-.8c-.6-.2-.6-.6.3-.7z" fill="#ffffff"></path></svg>
          </template>

          <template #action>
            <button
              v-if="telegramConnected"
              type="button"
              class="btn btn-secondary"
              :aria-expanded="managing === 'telegram'"
              aria-controls="int-panel-telegram"
              @click="toggleManage('telegram')"
            >
              Налаштування
              <svg class="chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><polyline points="6 9 12 15 18 9"></polyline></svg>
            </button>
            <button
              v-else-if="telegram?.configured"
              type="button"
              class="btn btn-primary"
              :disabled="telegramLinking"
              @click="connectTelegram"
            >
              <svg v-if="telegramLinking" class="spinner" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" aria-hidden="true"><path d="M21 12a9 9 0 1 1-6.2-8.56"></path></svg>
              {{ telegramLinking ? 'Чекаємо…' : 'Підключити' }}
            </button>
          </template>

          <div class="fields">
            <div class="field">
              <div class="field-label">Що надходить</div>
              <div class="field-control">
                <ul class="field-list">
                  <li>Готовий звіт з посиланням на вкладку Google Таблиці</li>
                  <li>Нагадування заповнити таски, якщо за день їх немає</li>
                  <li>Попередження про помилки генерації</li>
                </ul>
              </div>
            </div>
          </div>

          <div class="danger">
            <template v-if="confirming !== 'telegram'">
              <button type="button" class="btn btn-danger-ghost" @click="confirming = 'telegram'">Відключити Telegram</button>
            </template>
            <template v-else>
              <span class="danger-text">Сповіщення перестануть надходити.</span>
              <div class="danger-actions">
                <button type="button" class="btn btn-secondary" :disabled="telegramUnlinking" @click="confirming = null">Скасувати</button>
                <button type="button" class="btn btn-danger" :disabled="telegramUnlinking" @click="disconnectTelegram">
                  {{ telegramUnlinking ? 'Відключаємо…' : 'Так, відключити' }}
                </button>
              </div>
            </template>
          </div>
        </IntegrationRow>
      </div>
    </section>
  </div>
</template>

<style scoped>
/* Кнопки, поля, секції й небезпечна дія — у styles/integrations-ui.css. */
.integrations {
  display: flex;
  flex-direction: column;
  gap: 22px;
  margin-top: 16px;
  animation: fadeUp 0.35s ease both;
}

.source {
  display: flex;
  align-items: center;
  gap: 10px;
}

.source-label {
  font-size: 12px;
  font-weight: 600;
  color: var(--text-dim);
}

/* ── Сегментований перемикач (джерело тасків, спосіб підключення таблиці) ── */

.segmented {
  display: inline-flex;
  border: 1px solid var(--control-line);
  background: var(--surface);
}

.segmented-opt {
  font-family: inherit;
  font-size: 12.5px;
  font-weight: 600;
  color: var(--text-dim);
  background: none;
  border: none;
  padding: 7px 14px;
  cursor: pointer;
  transition: background 0.15s ease, color 0.15s ease;
}

.segmented-opt + .segmented-opt {
  border-left: 1px solid var(--control-line);
}

.segmented-opt:hover:not(:disabled):not([aria-checked='true']):not([aria-selected='true']) {
  background: var(--line);
  color: #2b2f33;
}

.segmented-opt[aria-checked='true'],
.segmented-opt[aria-selected='true'] {
  background: var(--accent);
  color: #fff;
  cursor: default;
}

.segmented-opt:disabled:not([aria-checked='true']) {
  color: var(--muted-2);
  cursor: not-allowed;
}

.segmented-opt:focus-visible {
  outline: 2px solid var(--accent);
  outline-offset: 2px;
  position: relative;
}

.segmented.is-small {
  margin-bottom: 16px;
}

.segmented.is-small .segmented-opt {
  padding: 6px 12px;
  font-size: 12px;
}

@media (max-width: 760px) {
  .source {
    width: 100%;
    justify-content: space-between;
  }
}
</style>
