<script setup>
import { computed, onMounted, onUnmounted, ref, watchEffect } from 'vue';
import Select from 'primevue/select';
import client from '../api/client';
import { useAuthStore } from '../stores/auth';

const emit = defineEmits(['trello-changed', 'status']);

const auth = useAuthStore();

// null | 'trello' | 'sheets' — яка інлайн-панель керування розгорнута
const managing = ref(null);

const loading = ref(true);
const status = ref(null);
const errorMessage = ref('');
const actionMessage = ref('');

const boards = ref([]);
const boardsLoading = ref(false);
const selectedBoardId = ref(null);
const newBoardName = ref('');
const saving = ref(false);
const disconnecting = ref(false);

const connected = computed(() => Boolean(status.value?.connected));

const googleLoading = ref(true);
const google = ref(null);
const sheetName = ref('');
const sheetEmail = ref('');
const sheetLink = ref('');
const googleSaving = ref(false);
const googleLinking = ref(false);
const googleDetaching = ref(false);

const hasSpreadsheet = computed(() => Boolean(google.value?.spreadsheet_id));
const sheetsActive = computed(() => Boolean(google.value?.account_connected) && hasSpreadsheet.value);

const telegramLoading = ref(true);
const telegram = ref(null);
const telegramLinking = ref(false);
const telegramUnlinking = ref(false);
let telegramPollTimer = null;

const telegramConnected = computed(() => Boolean(telegram.value?.connected));

const telegramBadgeLabel = computed(() => {
  if (telegramConnected.value) return 'Активно';
  return telegram.value?.configured ? 'Не підключено' : 'Не налаштовано';
});

const telegramSubtitle = computed(() => {
  if (telegramLinking.value) return 'Відкрийте Telegram і натисніть Start — чекаємо на підтвердження…';
  if (telegramConnected.value) return 'Сповіщення про звіти й таски приходять у ваш Telegram';
  if (!telegram.value?.configured) return 'Бот сповіщень ще не налаштований адміністратором';
  return 'Сповіщення про готові звіти і незаповнені таски Trello';
});

const trelloSubtitle = computed(() => {
  if (!connected.value) return 'Таски з дошки підтягуються у звіт і в колонку «Завдання» Excel';
  const user = `@${status.value.username}`;
  if (status.value.board_id) return `${user} · дошка «${status.value.board_name || status.value.board_id}»`;
  return `${user} · дошку ще не вибрано`;
});

const sheetsBadgeLabel = computed(() => {
  if (sheetsActive.value) return 'Активно';
  return google.value?.account_connected ? 'Не налаштовано' : 'Не підключено';
});

const sheetsSubtitle = computed(() => {
  if (!google.value) return '';
  if (!google.value.account_connected) return 'Google-акаунт ще не авторизовано адміністратором';
  if (hasSpreadsheet.value) return google.value.spreadsheet_title || 'Персональна таблиця';
  return 'Таблицю ще не створено — звіти не вивантажуються';
});

function toggleManage(which) {
  managing.value = managing.value === which ? null : which;
}

async function loadStatus() {
  errorMessage.value = '';
  try {
    const { data } = await client.get('/trello/status');
    status.value = data;
    if (data.connected) loadBoards();
  } catch (error) {
    errorMessage.value = error.response?.data?.message || 'Не вдалося отримати стан інтеграції.';
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
    errorMessage.value = error.response?.data?.message || 'Не вдалося отримати список дощок.';
  } finally {
    boardsLoading.value = false;
  }
}

function connect() {
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

// Popup після успішного збереження токена шле postMessage — оновлюємо статус без перезавантаження.
function onAuthMessage(event) {
  if (event.origin !== window.location.origin || event.data?.type !== 'trello-connected') return;
  actionMessage.value = `Trello підключено як @${event.data.username}.`;
  loading.value = true;
  loadStatus();
  emit('trello-changed');
}

async function disconnect() {
  if (!window.confirm('Відключити Trello? Токен буде відкликано, вибрана дошка — скинута.')) return;
  disconnecting.value = true;
  actionMessage.value = '';
  try {
    await client.delete('/trello/token');
    boards.value = [];
    selectedBoardId.value = null;
    managing.value = null;
    actionMessage.value = 'Trello відключено.';
    await loadStatus();
    emit('trello-changed');
  } catch (error) {
    errorMessage.value = error.response?.data?.message || 'Не вдалося відключити Trello.';
  } finally {
    disconnecting.value = false;
  }
}

async function selectBoard() {
  if (!selectedBoardId.value) return;
  saving.value = true;
  actionMessage.value = '';
  errorMessage.value = '';
  try {
    const { data } = await client.put('/trello/board', { board_id: selectedBoardId.value });
    actionMessage.value = `Активна дошка — «${data.board.name}».`;
    await loadStatus();
    emit('trello-changed');
  } catch (error) {
    errorMessage.value = error.response?.data?.message || 'Не вдалося вибрати дошку.';
  } finally {
    saving.value = false;
  }
}

async function createBoard() {
  if (!newBoardName.value.trim()) return;
  saving.value = true;
  actionMessage.value = '';
  errorMessage.value = '';
  try {
    const { data } = await client.post('/trello/boards', { name: newBoardName.value.trim() });
    actionMessage.value = `Дошку «${data.board.name}» створено і зроблено активною.`;
    newBoardName.value = '';
    await loadStatus();
    emit('trello-changed');
  } catch (error) {
    errorMessage.value = error.response?.data?.message || 'Не вдалося створити дошку.';
  } finally {
    saving.value = false;
  }
}

async function loadTelegramStatus() {
  try {
    const { data } = await client.get('/telegram/status');
    telegram.value = data;
  } catch {
    // блок Telegram не критичний — без статусу просто показуємо «Не налаштовано»
  } finally {
    telegramLoading.value = false;
  }
}

async function connectTelegram() {
  telegramLinking.value = true;
  actionMessage.value = '';
  errorMessage.value = '';
  try {
    const { data } = await client.post('/telegram/link');
    window.open(data.url, '_blank', 'noopener');
    pollTelegram(Date.now() + 120000);
  } catch (error) {
    telegramLinking.value = false;
    errorMessage.value = error.response?.data?.message || 'Не вдалося створити посилання на бота.';
  }
}

// Полимо статус, поки користувач тисне Start у Telegram (до 2 хв).
function pollTelegram(deadline) {
  clearTimeout(telegramPollTimer);
  telegramPollTimer = setTimeout(async () => {
    await loadTelegramStatus();
    if (telegramConnected.value) {
      telegramLinking.value = false;
      actionMessage.value = 'Telegram підключено — сповіщення активні.';
      return;
    }
    if (Date.now() < deadline) {
      pollTelegram(deadline);
    } else {
      telegramLinking.value = false;
    }
  }, 3000);
}

async function disconnectTelegram() {
  if (!window.confirm('Відключити Telegram-сповіщення?')) return;
  telegramUnlinking.value = true;
  actionMessage.value = '';
  errorMessage.value = '';
  try {
    const { data } = await client.delete('/telegram/link');
    managing.value = null;
    actionMessage.value = data.message;
    await loadTelegramStatus();
  } catch (error) {
    errorMessage.value = error.response?.data?.message || 'Не вдалося відключити Telegram.';
  } finally {
    telegramUnlinking.value = false;
  }
}

async function loadGoogleStatus() {
  try {
    const { data } = await client.get('/google/status');
    google.value = data;
    if (!sheetName.value) sheetName.value = `Звіти — ${auth.user?.name || ''}`.trim();
    if (!sheetEmail.value) sheetEmail.value = auth.user?.email || '';
  } catch (error) {
    errorMessage.value = error.response?.data?.message || 'Не вдалося отримати стан Google-інтеграції.';
  } finally {
    googleLoading.value = false;
  }
}

async function createSpreadsheet() {
  googleSaving.value = true;
  actionMessage.value = '';
  errorMessage.value = '';
  try {
    const { data } = await client.post('/google/spreadsheet', {
      name: sheetName.value.trim() || null,
      email: sheetEmail.value.trim() || null,
    });
    actionMessage.value = data.message;
    await loadGoogleStatus();
  } catch (error) {
    errorMessage.value = error.response?.data?.message || 'Не вдалося створити таблицю.';
  } finally {
    googleSaving.value = false;
  }
}

async function linkSpreadsheet() {
  if (!sheetLink.value.trim()) return;
  googleLinking.value = true;
  actionMessage.value = '';
  errorMessage.value = '';
  try {
    const { data } = await client.post('/google/spreadsheet/link', {
      spreadsheet: sheetLink.value.trim(),
    });
    actionMessage.value = data.message;
    sheetLink.value = '';
    await loadGoogleStatus();
  } catch (error) {
    errorMessage.value = error.response?.data?.message || 'Не вдалося підключити таблицю.';
  } finally {
    googleLinking.value = false;
  }
}

async function detachSpreadsheet() {
  if (!window.confirm('Відв\'язати таблицю? Сам файл залишиться, але нові звіти не вивантажуватимуться, поки не створите або не підключите іншу.')) return;
  googleDetaching.value = true;
  actionMessage.value = '';
  errorMessage.value = '';
  try {
    const { data } = await client.delete('/google/spreadsheet');
    actionMessage.value = data.message;
    await loadGoogleStatus();
  } catch (error) {
    errorMessage.value = error.response?.data?.message || 'Не вдалося відв\'язати таблицю.';
  } finally {
    googleDetaching.value = false;
  }
}

// Батьківська сторінка блокує формування звіту, поки обидві інтеграції не активні.
watchEffect(() => {
  emit('status', {
    loaded: !loading.value && !googleLoading.value,
    trello: connected.value,
    sheets: sheetsActive.value,
  });
});

onMounted(() => {
  window.addEventListener('message', onAuthMessage);
  loadStatus();
  loadGoogleStatus();
  loadTelegramStatus();
});

onUnmounted(() => {
  window.removeEventListener('message', onAuthMessage);
  clearTimeout(telegramPollTimer);
});
</script>

<template>
  <div class="integrations-strip-wrap">
    <div class="strip-label">Підключені інтеграції</div>

    <div v-if="loading || googleLoading || telegramLoading" class="skeleton strip-skeleton"></div>

    <template v-else>
      <div class="strip">

        <!-- Google Sheets -->
        <div class="strip-row" :class="{ 'is-managing': managing === 'sheets' }">
          <div class="strip-icon">
            <svg width="18" height="18" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" fill="#0F9D58"></path><path d="M14 2v6h6" fill="#8ED1A6"></path><rect x="7" y="12" width="10" height="1.6" fill="#ffffff"></rect><rect x="7" y="15.2" width="10" height="1.6" fill="#ffffff"></rect><rect x="7" y="18.4" width="6" height="1.6" fill="#ffffff"></rect></svg>
          </div>
          <div class="strip-info">
            <div class="strip-title-row">
              <span class="strip-name">Google Таблиця</span>
              <span class="strip-badge" :class="{ 'is-off': !sheetsActive }">
                <span class="strip-badge-dot"></span>{{ sheetsBadgeLabel }}
              </span>
            </div>
            <div class="strip-subtitle">{{ sheetsSubtitle }}</div>
          </div>
          <button class="strip-manage-btn" type="button" @click="toggleManage('sheets')">
            {{ managing === 'sheets' ? 'Згорнути' : 'Керувати' }}
          </button>
        </div>

        <!-- Trello -->
        <div class="strip-row" :class="{ 'is-managing': managing === 'trello' }">
          <div class="strip-icon">
            <svg width="18" height="18" viewBox="0 0 24 24"><rect x="2" y="2" width="20" height="20" fill="#0079BF"></rect><rect x="4.5" y="4.5" width="6.5" height="10.5" fill="#ffffff"></rect><rect x="13" y="4.5" width="6.5" height="6.5" fill="#ffffff"></rect></svg>
          </div>
          <div class="strip-info">
            <div class="strip-title-row">
              <span class="strip-name">Trello</span>
              <span class="strip-badge" :class="{ 'is-off': !connected }">
                <span class="strip-badge-dot"></span>{{ connected ? 'Активно' : 'Не підключено' }}
              </span>
            </div>
            <div class="strip-subtitle">{{ trelloSubtitle }}</div>
          </div>
          <button v-if="connected" class="strip-manage-btn" type="button" @click="toggleManage('trello')">
            {{ managing === 'trello' ? 'Згорнути' : 'Керувати' }}
          </button>
          <button v-else class="strip-manage-btn is-solid" type="button" @click="connect">Підключити</button>
        </div>

        <!-- Telegram -->
        <div class="strip-row" :class="{ 'is-managing': managing === 'telegram' }">
          <div class="strip-icon">
            <svg width="18" height="18" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" fill="#229ED9"></circle><path d="M6.2 11.6l9.8-3.9c.5-.2.9.1.7.9l-1.6 7.8c-.1.6-.5.7-1 .4l-2.5-1.9-1.2 1.2c-.2.2-.4.3-.7.3l.2-2.6 4.8-4.4c.2-.2 0-.3-.3-.1l-6 3.8-2.5-.8c-.6-.2-.6-.6.3-.7z" fill="#ffffff"></path></svg>
          </div>
          <div class="strip-info">
            <div class="strip-title-row">
              <span class="strip-name">Telegram</span>
              <span class="strip-badge" :class="{ 'is-off': !telegramConnected }">
                <span class="strip-badge-dot"></span>{{ telegramBadgeLabel }}
              </span>
            </div>
            <div class="strip-subtitle">{{ telegramSubtitle }}</div>
          </div>
          <button v-if="telegramConnected" class="strip-manage-btn" type="button" @click="toggleManage('telegram')">
            {{ managing === 'telegram' ? 'Згорнути' : 'Керувати' }}
          </button>
          <button
            v-else-if="telegram?.configured"
            class="strip-manage-btn is-solid"
            type="button"
            :disabled="telegramLinking"
            @click="connectTelegram"
          >
            {{ telegramLinking ? 'Чекаємо…' : 'Підключити' }}
          </button>
        </div>

      </div>

      <!-- Trello inline management panel -->
      <div v-if="managing === 'trello' && connected" class="manage-panel">
        <div class="panel-head">
          <div class="panel-head-note">Trello · підключено як <strong>@{{ status.username }}</strong></div>
          <button class="panel-close" type="button" @click="managing = null">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
          </button>
        </div>
        <div class="panel-grid">
          <div>
            <div class="field-label">Активна дошка</div>
            <div class="field-row">
              <Select
                v-model="selectedBoardId"
                :options="boards"
                option-label="name"
                option-value="id"
                :loading="boardsLoading"
                placeholder="Виберіть дошку зі свого Trello"
                class="panel-select"
              />
              <button
                class="panel-btn"
                type="button"
                :disabled="saving || !selectedBoardId || selectedBoardId === status.board_id"
                @click="selectBoard"
              >
                Обрати
              </button>
            </div>
          </div>
          <div>
            <div class="field-label">Створити нову дошку</div>
            <div class="field-row">
              <input
                v-model="newBoardName"
                type="text"
                class="panel-input"
                placeholder="Назва дошки"
                maxlength="255"
                @keyup.enter="createBoard"
              />
              <button class="panel-btn is-dim" type="button" :disabled="saving || !newBoardName.trim()" @click="createBoard">
                {{ saving ? 'Зачекайте…' : 'Створити' }}
              </button>
            </div>
          </div>
        </div>
        <div class="panel-foot">
          <a
            v-if="status.board_url"
            :href="status.board_url"
            target="_blank"
            rel="noopener"
            class="panel-open-link"
          >Відкрити дошку «{{ status.board_name || status.board_id }}» ↗</a>
          <span v-else class="panel-note">Нова дошка створюється зі списками й мітками з шаблону.</span>
          <button class="panel-link" type="button" :disabled="disconnecting" @click="disconnect">
            {{ disconnecting ? 'Відключаємо…' : 'Відключити Trello' }}
          </button>
        </div>
      </div>

      <!-- Google Sheets inline management panel -->
      <div v-if="managing === 'sheets'" class="manage-panel">
        <div class="panel-head">
          <div class="panel-head-note">Google Таблиця</div>
          <button class="panel-close" type="button" @click="managing = null">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
          </button>
        </div>

        <template v-if="!google?.account_connected">
          <p class="panel-note">
            Google-акаунт ще не підключено — адміністратор має один раз авторизуватися
            на сторінці <code>/google/auth</code> бекенда. Після цього тут можна буде
            створити персональну таблицю.
          </p>
        </template>

        <template v-else-if="hasSpreadsheet">
          <p v-if="google.spreadsheet_title" class="panel-note">
            Готовий звіт вивантажується денною вкладкою у
            «<strong>{{ google.spreadsheet_title }}</strong>», таски розносяться по місячному аркушу.
          </p>
          <p v-else class="panel-note">
            Не вдалося прочитати таблицю — можливо, її видалили або забрали доступ.
            Відв'яжіть її і створіть чи підключіть іншу.
          </p>
          <div class="panel-foot">
            <a
              v-if="google.spreadsheet_url"
              :href="google.spreadsheet_url"
              target="_blank"
              rel="noopener"
              class="panel-open-link"
            >Відкрити таблицю ↗</a>
            <span v-else></span>
            <button class="panel-link" type="button" :disabled="googleDetaching" @click="detachSpreadsheet">
              {{ googleDetaching ? 'Відв\'язуємо…' : 'Відв\'язати таблицю' }}
            </button>
          </div>
        </template>

        <template v-else>
          <div class="panel-grid">
            <div>
              <div class="field-label">Назва таблиці</div>
              <input v-model="sheetName" type="text" class="panel-input is-wide" placeholder="Назва таблиці" maxlength="255" />
            </div>
            <div>
              <div class="field-label">Email редактора</div>
              <input v-model="sheetEmail" type="email" class="panel-input is-wide" placeholder="email@example.com" maxlength="255" />
            </div>
          </div>
          <div class="panel-foot">
            <span class="panel-note">На вказаний email буде надано доступ редактора і надіслано посилання.</span>
            <button class="panel-btn" type="button" :disabled="googleSaving || googleLinking" @click="createSpreadsheet">
              {{ googleSaving ? 'Створюємо…' : 'Створити таблицю' }}
            </button>
          </div>
          <div class="link-existing">
            <div class="field-label">Або підключіть наявну таблицю</div>
            <div class="field-row">
              <input
                v-model="sheetLink"
                type="text"
                class="panel-input"
                placeholder="Посилання на Google Таблицю"
                maxlength="2048"
                @keyup.enter="linkSpreadsheet"
              />
              <button
                class="panel-btn is-dim"
                type="button"
                :disabled="googleLinking || googleSaving || !sheetLink.trim()"
                @click="linkSpreadsheet"
              >
                {{ googleLinking ? 'Перевіряємо…' : 'Підключити' }}
              </button>
            </div>
            <p class="panel-note link-existing-note">
              Спершу в самій таблиці натисніть «Поділитися» і надайте доступ
              <strong>редактора</strong><template v-if="google.account_email"> акаунту
              <strong>{{ google.account_email }}</strong></template>.
              Вміст таблиці не зміниться — звіти додаватимуться окремими вкладками.
            </p>
          </div>
        </template>
      </div>

      <!-- Telegram inline management panel -->
      <div v-if="managing === 'telegram' && telegramConnected" class="manage-panel">
        <div class="panel-head">
          <div class="panel-head-note">Telegram · сповіщення активні</div>
          <button class="panel-close" type="button" @click="managing = null">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
          </button>
        </div>
        <p class="panel-note">
          Бот повідомляє про згенерований звіт (з посиланням на вкладку Google Таблиці),
          нагадує заповнити таски Trello, якщо за день їх немає, і попереджає про помилки генерації.
        </p>
        <div class="panel-foot">
          <span></span>
          <button class="panel-link" type="button" :disabled="telegramUnlinking" @click="disconnectTelegram">
            {{ telegramUnlinking ? 'Відключаємо…' : 'Відключити Telegram' }}
          </button>
        </div>
      </div>

      <div v-if="errorMessage" class="strip-msg is-error">{{ errorMessage }}</div>
      <div v-if="actionMessage" class="strip-msg is-ok">{{ actionMessage }}</div>
    </template>
  </div>
</template>

<style scoped>
@keyframes slideDown {
  from { opacity: 0; transform: translateY(-6px); }
  to { opacity: 1; transform: translateY(0); }
}

.integrations-strip-wrap {
  margin-top: 14px;
  flex-shrink: 0;
}

.strip-label {
  font-size: 11.5px;
  font-weight: 700;
  color: var(--muted-2);
  text-transform: uppercase;
  letter-spacing: 0.06em;
  margin-bottom: 8px;
}

.strip-skeleton {
  height: 61px;
}

.strip {
  display: flex;
  align-items: stretch;
  gap: 1px;
  background: var(--line);
  border: 1px solid var(--line);
}

.strip-row {
  flex: 1;
  background: var(--surface);
  padding: 12px 16px;
  display: flex;
  align-items: center;
  gap: 12px;
  min-width: 0;
  transition: background 0.12s ease;
}

.strip-row:hover {
  background: #f7fafa;
}

.strip-row.is-managing {
  background: #edf6f5;
}

.strip-icon {
  width: 34px;
  height: 34px;
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  border: 1px solid var(--line);
  background: var(--surface);
}

.strip-info {
  min-width: 0;
  flex: 1;
}

/* Назва + бейдж мають переноситись: на вузькому екрані вони інакше
   вилазять із своєї колонки і наїжджають на кнопку «Керувати». */
.strip-title-row {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 2px 7px;
}

.strip-name {
  font-size: 13.5px;
  font-weight: 700;
  color: var(--ink);
  min-width: 0;
  overflow-wrap: anywhere;
}

.strip-badge {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  font-size: 10.5px;
  font-weight: 700;
  color: var(--accent);
  background: var(--line);
  padding: 2px 7px;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  flex-shrink: 0;
}

.strip-badge.is-off {
  color: var(--muted);
}

.strip-badge-dot {
  width: 5px;
  height: 5px;
  /* виняток із глобального border-radius: 0 — крапка лишається круглою */
  border-radius: 50% !important;
  background: currentColor;
}

.strip-subtitle {
  font-size: 12px;
  color: var(--muted);
  margin-top: 2px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.strip-manage-btn {
  font-size: 12px;
  font-weight: 600;
  color: var(--accent);
  background: none;
  flex-shrink: 0;
  padding: 6px 10px;
  border: 1px solid var(--accent);
  cursor: pointer;
  font-family: inherit;
  transition: background 0.12s ease, color 0.12s ease;
}

.strip-manage-btn:hover {
  background: var(--accent);
  color: #fff;
}

.strip-manage-btn.is-solid {
  background: var(--accent);
  color: #fff;
}

.strip-manage-btn.is-solid:hover {
  background: #118779;
}

.manage-panel {
  background: var(--surface);
  border: 1px solid var(--line);
  border-top: none;
  padding: 16px;
  animation: slideDown 0.18s ease both;
}

.panel-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 12px;
}

.panel-head-note {
  font-size: 12px;
  color: var(--muted);
}

.panel-head-note strong {
  color: var(--ink);
}

.panel-close {
  background: none;
  border: none;
  cursor: pointer;
  color: var(--muted-2);
  padding: 2px;
  display: flex;
}

.panel-close:hover {
  color: var(--text-dim);
}

.panel-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 20px;
}

/* Колонка грида за замовчуванням не вужча за min-content вмісту — без цього
   ряд «поле + кнопка» розпирає панель за межі екрана на мобільному. */
.panel-grid > * {
  min-width: 0;
}

.field-label {
  font-size: 11.5px;
  font-weight: 600;
  color: var(--text-dim);
  margin-bottom: 6px;
}

.field-row {
  display: flex;
  gap: 6px;
  min-width: 0;
}

.panel-select {
  flex: 1;
  min-width: 0;
}

.panel-input {
  flex: 1;
  min-width: 0;
  border: 1px solid var(--line);
  padding: 8px 10px;
  font-size: 13px;
  font-family: inherit;
  color: var(--text-dim);
  outline: none;
  background: var(--surface);
}

.panel-input.is-wide {
  width: 100%;
}

.panel-input:focus {
  border-color: var(--accent);
}

.panel-btn {
  background: var(--accent);
  color: #fff;
  border: none;
  padding: 8px 14px;
  font-size: 12.5px;
  font-weight: 600;
  cursor: pointer;
  white-space: nowrap;
  font-family: inherit;
  transition: background 0.12s ease;
}

.panel-btn:hover:not(:disabled) {
  background: #118779;
}

.panel-btn:disabled {
  cursor: not-allowed;
  opacity: 0.7;
}

.panel-btn.is-dim {
  background: var(--text-dim);
}

.panel-btn.is-dim:hover:not(:disabled) {
  background: #565656;
}

.link-existing {
  border-top: 1px solid var(--line);
  margin-top: 14px;
  padding-top: 12px;
}

.link-existing-note {
  margin-top: 8px;
}

.panel-foot {
  border-top: 1px solid var(--line);
  margin-top: 14px;
  padding-top: 12px;
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  align-items: center;
  gap: 8px 12px;
}

.panel-note {
  font-size: 12.5px;
  color: var(--muted);
  margin: 0;
}

.panel-open-link {
  font-size: 12px;
  font-weight: 600;
  color: var(--accent);
  text-decoration: none;
}

.panel-open-link:hover {
  text-decoration: underline;
}

.panel-link {
  font-size: 12px;
  color: var(--muted-2);
  text-decoration: underline;
  background: none;
  border: none;
  cursor: pointer;
  font-family: inherit;
  padding: 0;
  flex-shrink: 0;
}

.panel-link:hover:not(:disabled) {
  color: #c0392b;
}

.strip-msg {
  margin-top: 8px;
  font-size: 12.5px;
}

.strip-msg.is-error {
  color: #c2402f;
}

.strip-msg.is-ok {
  color: var(--accent);
}

@media (max-width: 760px) {
  .strip {
    flex-direction: column;
  }

  .panel-grid {
    grid-template-columns: 1fr;
  }
}
</style>
