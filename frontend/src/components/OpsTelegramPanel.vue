<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import client from '../api/client';
import IntegrationRow from './IntegrationRow.vue';
import '../styles/integrations-ui.css';

// Технічний Telegram адміністратора: підсумок ранкового прогону і тривоги
// про збої. Чати окремі від особистих сповіщень про звіти (сторінка
// «Інтеграції»), кожен адміністратор підключає свої сам, і чатів може бути
// кілька — особистий, спільна група підтримки, черговий канал.
const loading = ref(true);
const configured = ref(false);
const chats = ref([]);
const maxChats = ref(5);
const open = ref(false);
const linking = ref(false);
// Команда для підключення групи: у групі deep-link не працює, туди треба
// покликати бота й написати /start з кодом. Тримаємо розібраним з посилання.
const linkCommand = ref(null);
// id чату, з яким зараз щось роблять: щоб крутилась лише його кнопка
const testingId = ref(null);
const removingId = ref(null);
const confirmingRemove = ref(null);
// { tone: 'ok' | 'error', text } — показується під рядком
const notice = ref(null);
let pollTimer = null;

const connected = computed(() => chats.value.length > 0);
const full = computed(() => chats.value.length >= maxChats.value);
const busy = computed(() => testingId.value !== null || removingId.value !== null);

const rowStatus = computed(() => {
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

const rowMeta = computed(() => {
  if (linking.value && !connected.value) return 'Відкрийте бота в Telegram і натисніть Start';
  if (!configured.value) return 'Telegram-бот не налаштований на сервері';
  if (!connected.value) return 'Збої сервісу й підсумок ранкової генерації звітів';
  if (chats.value.length === 1) return chats.value[0].title;
  return `${chats.value[0].title} і ще ${chats.value.length - 1}`;
});

// «група · з 22.09.2026» — тип чату відрізняє однаково названі адресати,
// дата нагадує, коли підписку взагалі вмикали.
function chatMeta(chat) {
  const kind = chat.kind === 'group' ? 'група' : 'особистий чат';

  if (!chat.connected_at) return kind;

  const date = new Date(chat.connected_at).toLocaleDateString('uk-UA', {
    day: '2-digit', month: '2-digit', year: 'numeric',
  });

  return `${kind} · з ${date}`;
}

function apply(data) {
  configured.value = Boolean(data.configured);
  chats.value = data.chats || [];
  maxChats.value = data.max_chats || maxChats.value;
  // Чат могли прибрати з іншої вкладки — підтвердження на ньому вже не про що.
  if (confirmingRemove.value !== null && !chats.value.some((chat) => chat.id === confirmingRemove.value)) {
    confirmingRemove.value = null;
  }
}

async function loadStatus() {
  try {
    const { data } = await client.get('/alerts/telegram');
    apply(data);
  } catch (error) {
    notice.value = { tone: 'error', text: error.response?.data?.message || 'Не вдалося отримати стан технічних сповіщень.' };
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
  const was = chats.value.length;
  try {
    const { data } = await client.post('/alerts/telegram/link');
    linkCommand.value = commandFrom(data.url);
    open.value = true;
    window.open(data.url, '_blank', 'noopener');
    poll(was, Date.now() + 120000);
  } catch (error) {
    linking.value = false;
    notice.value = { tone: 'error', text: error.response?.data?.message || 'Не вдалося створити посилання на бота.' };
  }
}

// З https://t.me/TeamReporter_Bot?start=КОД робимо те, що людина напише в групі.
function commandFrom(url) {
  try {
    return `/start ${new URL(url).searchParams.get('start')}`;
  } catch {
    return null;
  }
}

// Полимо стан, поки адміністратор тисне Start у Telegram (до 2 хв). Чекаємо
// саме нового чату: у списку вже може бути один підключений.
function poll(was, deadline) {
  clearTimeout(pollTimer);
  pollTimer = setTimeout(async () => {
    await loadStatus();
    if (chats.value.length > was) {
      stopLinking();
      notice.value = { tone: 'ok', text: `Чат «${chats.value[chats.value.length - 1].title}» підключено.` };
    } else if (Date.now() < deadline) {
      poll(was, deadline);
    } else {
      stopLinking();
      notice.value = { tone: 'error', text: 'Не дочекались підтвердження. Натисніть «Додати чат» ще раз і тисніть Start у боті.' };
    }
  }, 3000);
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
    notice.value = { tone: 'ok', text: data.message };
  } catch (error) {
    notice.value = {
      tone: 'error',
      text: error.response?.status === 429
        ? 'Забагато пробних повідомлень — зачекайте хвилину.'
        : error.response?.data?.message || 'Не вдалося надіслати пробне повідомлення.',
    };
  } finally {
    testingId.value = null;
  }
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
    const { data } = await client.delete(`/alerts/telegram/${chat.id}`);
    confirmingRemove.value = null;
    notice.value = { tone: 'ok', text: data.message };
    await loadStatus();
    if (!connected.value) open.value = false;
  } catch (error) {
    notice.value = { tone: 'error', text: error.response?.data?.message || 'Не вдалося відключити чат.' };
  } finally {
    removingId.value = null;
  }
}

onMounted(loadStatus);
onUnmounted(() => clearTimeout(pollTimer));
</script>

<template>
  <section class="int-ui" aria-labelledby="sec-ops-telegram">
    <header class="section-head">
      <div>
        <h2 id="sec-ops-telegram" class="section-title">Технічні сповіщення</h2>
        <p class="section-desc">
          Службовий канал адміністратора: чи пройшла ранкова генерація і що зламалось у сервісі.
          Працівникам такі повідомлення не надходять.
        </p>
      </div>
    </header>

    <div class="panel int-list" :aria-busy="loading">
      <div v-if="loading" class="row-skeleton"><div class="skeleton"></div></div>

      <IntegrationRow
        v-else
        id="ops-telegram"
        name="Telegram"
        :meta="rowMeta"
        :status="rowStatus"
        :open="open && (connected || linking)"
        :notice="notice"
      >
        <template #icon>
          <svg width="18" height="18" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" fill="#229ED9"></circle><path d="M6.2 11.6l9.8-3.9c.5-.2.9.1.7.9l-1.6 7.8c-.1.6-.5.7-1 .4l-2.5-1.9-1.2 1.2c-.2.2-.4.3-.7.3l.2-2.6 4.8-4.4c.2-.2 0-.3-.3-.1l-6 3.8-2.5-.8c-.6-.2-.6-.6.3-.7z" fill="#ffffff"></path></svg>
        </template>

        <template #action>
          <button
            v-if="connected"
            type="button"
            class="btn btn-secondary"
            :aria-expanded="open"
            aria-controls="int-panel-ops-telegram"
            @click="toggle"
          >
            Налаштування
            <svg class="chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><polyline points="6 9 12 15 18 9"></polyline></svg>
          </button>
          <button
            v-else-if="configured"
            type="button"
            class="btn btn-primary"
            :disabled="linking"
            @click="connect"
          >
            <svg v-if="linking" class="spinner" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" aria-hidden="true"><path d="M21 12a9 9 0 1 1-6.2-8.56"></path></svg>
            {{ linking ? 'Чекаємо…' : 'Підключити' }}
          </button>
        </template>

        <div class="fields">
          <div class="field">
            <div class="field-label">Чати</div>
            <div class="field-control">
              <ul class="chat-list">
                <li v-for="chat in chats" :key="chat.id" class="chat-item">
                  <template v-if="confirmingRemove === chat.id">
                    <span class="chat-confirm">Прибрати останній чат? Про збої сервісу ви більше не дізнаєтесь у Telegram.</span>
                    <span class="chat-actions">
                      <button type="button" class="btn btn-secondary" :disabled="busy" @click="confirmingRemove = null">Скасувати</button>
                      <button type="button" class="btn btn-danger" :disabled="busy" @click="remove(chat)">
                        {{ removingId === chat.id ? 'Прибираємо…' : 'Так, прибрати' }}
                      </button>
                    </span>
                  </template>

                  <template v-else>
                    <span class="chat-kind" aria-hidden="true">
                      <svg v-if="chat.kind === 'group'" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                      <svg v-else width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                    </span>
                    <span class="chat-text">
                      <span class="chat-name">{{ chat.title }}</span>
                      <span class="chat-meta">{{ chatMeta(chat) }}</span>
                    </span>
                    <span class="chat-actions">
                      <button type="button" class="btn btn-link" :disabled="busy" @click="sendTest(chat)">
                        {{ testingId === chat.id ? 'Надсилаємо…' : 'Перевірити' }}
                      </button>
                      <button type="button" class="btn btn-link is-danger" :disabled="busy" @click="remove(chat)">
                        {{ removingId === chat.id ? 'Прибираємо…' : 'Прибрати' }}
                      </button>
                    </span>
                  </template>
                </li>

                <!-- Інструкція показується рівно тоді, коли за нею йдуть: поки
                     чекаємо на Start. Далі рядок зникає сам. -->
                <li v-if="linking" class="chat-item is-waiting" role="status">
                  <span class="chat-kind" aria-hidden="true">
                    <svg class="spinner" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6"><path d="M21 12a9 9 0 1 1-6.2-8.56"></path></svg>
                  </span>
                  <span class="chat-text">
                    <span class="chat-name">Чекаємо на Start у Telegram</span>
                    <span class="chat-meta">
                      Натисніть Start у боті, що відкрився.
                      <template v-if="linkCommand">Для групи: додайте туди бота й надішліть <code>{{ linkCommand }}</code></template>
                    </span>
                  </span>
                </li>

                <li v-else class="chat-item is-add">
                  <button
                    v-if="configured && !full"
                    type="button"
                    class="chat-add"
                    :disabled="busy"
                    @click="connect"
                  >
                    <span class="chat-add-glyph" aria-hidden="true">+</span>
                    <span>Додати чат</span>
                  </button>
                  <span v-else class="chat-add-off">
                    {{ configured ? 'Більше чатів додати не можна' : 'Telegram-бот не налаштований на сервері' }}
                  </span>
                  <span v-if="configured" class="chat-counter">{{ chats.length }} з {{ maxChats }}</span>
                </li>
              </ul>
            </div>
          </div>

          <div class="field">
            <div class="field-label">Що надходить</div>
            <div class="field-control">
              <ul class="what-list">
                <li><b>Ранкова генерація</b> — скільки звітів у черзі, кого пропущено</li>
                <li><b>Тривоги монітора</b> — невдалі звіти й AI-розбори, зависла черга, місце на диску, застарілий бекап</li>
                <li><b>Падіння планувальника</b> — ранковий прогін не стартував</li>
              </ul>
            </div>
          </div>
        </div>
      </IntegrationRow>
    </div>
  </section>
</template>

<style scoped>
/* Список адресатів: рядок — тип чату, назва з підписом, дії праворуч.
   Остання строка списку — сама дія «додати», щоб список лишався одним
   обʼєктом, а не парою «список + кнопка десь поруч». */
.chat-list {
  list-style: none;
  margin: 0;
  padding: 0;
  border: 1px solid var(--control-line);
  background: var(--surface);
}

.chat-item {
  display: grid;
  grid-template-columns: 22px minmax(0, 1fr) auto;
  align-items: center;
  gap: 4px 10px;
  padding: 9px 8px 9px 11px;
}

.chat-item + .chat-item {
  border-top: 1px solid var(--line);
}

.chat-kind {
  display: flex;
  color: var(--muted-2);
}

.chat-text {
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 1px;
}

.chat-name {
  font-size: 13px;
  font-weight: 600;
  color: #2b2f33;
  overflow-wrap: anywhere;
}

.chat-meta {
  font-size: 11.5px;
  line-height: 1.5;
  color: var(--muted);
  font-variant-numeric: tabular-nums;
}

.chat-meta code {
  font-family: 'IBM Plex Mono', ui-monospace, monospace;
  font-size: 11px;
  font-weight: 600;
  color: var(--text-dim);
  background: var(--line);
  padding: 1px 4px;
}

.chat-actions {
  display: flex;
  align-items: center;
  gap: 4px;
}

.chat-confirm {
  grid-column: 1 / 3;
  font-size: 12.5px;
  color: var(--text-dim);
}

.chat-item.is-waiting .chat-kind {
  color: var(--accent);
}

.chat-item.is-waiting .chat-name {
  color: var(--text-dim);
}

/* Рядок-дія без власної межі: висота як у чатів, підсвічування — на ховер. */
.chat-item.is-add {
  padding: 0 11px 0 0;
  min-height: 38px;
}

.chat-add {
  grid-column: 1 / 3;
  display: flex;
  align-items: center;
  gap: 10px;
  min-height: 38px;
  padding: 0 0 0 11px;
  border: 0;
  background: none;
  font-family: inherit;
  font-size: 12.5px;
  font-weight: 600;
  color: var(--accent);
  cursor: pointer;
  transition: background 0.15s ease;
}

.chat-add:hover:not(:disabled) {
  background: #f4faf9;
}

.chat-add:focus-visible {
  outline: 2px solid var(--accent);
  outline-offset: -2px;
}

.chat-add:disabled {
  cursor: not-allowed;
  opacity: 0.55;
}

/* Ширина = колонка іконок у рядках чатів: підпис «Додати чат» стає рівно під
   назвами чатів, а не з власним відступом. */
.chat-add-glyph {
  width: 22px;
  display: flex;
  justify-content: center;
  font-size: 15px;
  font-weight: 500;
}

.chat-add-off {
  grid-column: 1 / 3;
  padding-left: 11px;
  font-size: 12.5px;
  color: var(--muted);
}

.chat-counter {
  font-size: 11.5px;
  color: var(--muted-2);
  font-variant-numeric: tabular-nums;
}

/* Що надходить: підмет жирним, решта — пояснення в один потік тексту. */
.what-list {
  list-style: none;
  margin: 0;
  padding: 3px 0 0;
  display: flex;
  flex-direction: column;
  gap: 6px;
  max-width: 62ch;
}

.what-list li {
  font-size: 12.5px;
  line-height: 1.55;
  color: var(--muted);
}

.what-list b {
  font-weight: 600;
  color: var(--text-dim);
}

@media (max-width: 760px) {
  .chat-item {
    grid-template-columns: 22px minmax(0, 1fr);
    padding: 9px 11px;
  }

  .chat-actions {
    grid-column: 2 / 3;
    margin-left: -6px;
  }

  .chat-item.is-add {
    grid-template-columns: minmax(0, 1fr) auto;
    padding: 0 11px 0 0;
  }

  .chat-add,
  .chat-add-off {
    grid-column: 1 / 2;
  }
}
</style>
