<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import client from '../api/client';
import IntegrationRow from './IntegrationRow.vue';
import '../styles/integrations-ui.css';

// Технічний Telegram адміністратора: підсумок ранкового прогону і тривоги
// про збої. Чат окремий від особистих сповіщень про звіти (сторінка
// «Інтеграції»), і кожен адміністратор підключає свій сам.
const loading = ref(true);
const status = ref(null);
const open = ref(false);
const linking = ref(false);
const unlinking = ref(false);
const testing = ref(false);
const confirming = ref(false);
// { tone: 'ok' | 'error', text } — показується під рядком
const notice = ref(null);
let pollTimer = null;

const configured = computed(() => Boolean(status.value?.configured));
const connected = computed(() => Boolean(status.value?.connected));

const rowStatus = computed(() => {
  if (connected.value) return { tone: 'ok', label: 'Підключено' };
  if (!configured.value) return { tone: 'off', label: 'Недоступно' };
  if (linking.value) return { tone: 'action', label: 'Чекаємо на Start' };
  return { tone: 'off', label: 'Не підключено' };
});

const rowMeta = computed(() => {
  if (linking.value) return 'Відкрийте бота в Telegram і натисніть Start';
  if (connected.value) return 'Збої й підсумок ранкової генерації приходять у ваш Telegram';
  if (!configured.value) return 'Telegram-бот не налаштований на сервері';
  return 'Збої сервісу й підсумок ранкової генерації звітів';
});

async function loadStatus() {
  try {
    const { data } = await client.get('/alerts/telegram');
    status.value = data;
  } catch (error) {
    notice.value = { tone: 'error', text: error.response?.data?.message || 'Не вдалося отримати стан технічних сповіщень.' };
  } finally {
    loading.value = false;
  }
}

function toggle() {
  confirming.value = false;
  open.value = !open.value;
}

async function connect() {
  linking.value = true;
  notice.value = null;
  try {
    const { data } = await client.post('/alerts/telegram/link');
    window.open(data.url, '_blank', 'noopener');
    poll(Date.now() + 120000);
  } catch (error) {
    linking.value = false;
    notice.value = { tone: 'error', text: error.response?.data?.message || 'Не вдалося створити посилання на бота.' };
  }
}

// Полимо стан, поки адміністратор тисне Start у Telegram (до 2 хв).
function poll(deadline) {
  clearTimeout(pollTimer);
  pollTimer = setTimeout(async () => {
    await loadStatus();
    if (connected.value) {
      linking.value = false;
      notice.value = { tone: 'ok', text: 'Технічні сповіщення підключено.' };
    } else if (Date.now() < deadline) {
      poll(deadline);
    } else {
      linking.value = false;
      notice.value = { tone: 'error', text: 'Не дочекались підтвердження. Натисніть «Підключити» ще раз і тисніть Start у боті.' };
    }
  }, 3000);
}

async function sendTest() {
  testing.value = true;
  notice.value = null;
  try {
    const { data } = await client.post('/alerts/telegram/test');
    notice.value = { tone: 'ok', text: data.message };
  } catch (error) {
    notice.value = {
      tone: 'error',
      text: error.response?.status === 429
        ? 'Забагато пробних повідомлень — зачекайте хвилину.'
        : error.response?.data?.message || 'Не вдалося надіслати пробне повідомлення.',
    };
  } finally {
    testing.value = false;
  }
}

async function disconnect() {
  unlinking.value = true;
  notice.value = null;
  try {
    const { data } = await client.delete('/alerts/telegram');
    open.value = false;
    confirming.value = false;
    notice.value = { tone: 'ok', text: data.message };
    await loadStatus();
  } catch (error) {
    notice.value = { tone: 'error', text: error.response?.data?.message || 'Не вдалося відключити Telegram.' };
  } finally {
    unlinking.value = false;
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
        :open="open && connected"
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
            <div class="field-label">Що надходить</div>
            <div class="field-control">
              <ul class="field-list">
                <li>Підсумок ранкової генерації: скільки звітів поставлено в чергу і кого пропущено</li>
                <li>Тривоги монітора: невдалі звіти й AI-розбори, зависла черга, мало місця на диску, застарілий бекап</li>
                <li>Падіння планувальника</li>
              </ul>
            </div>
          </div>

          <div class="field">
            <div class="field-label">Перевірка</div>
            <div class="field-control">
              <div class="control-row">
                <button type="button" class="btn btn-secondary" :disabled="testing" @click="sendTest">
                  {{ testing ? 'Надсилаємо…' : 'Надіслати пробне повідомлення' }}
                </button>
              </div>
            </div>
          </div>
        </div>

        <div class="danger">
          <template v-if="!confirming">
            <button type="button" class="btn btn-danger-ghost" @click="confirming = true">Відключити</button>
          </template>
          <template v-else>
            <span class="danger-text">Про збої сервісу ви більше не дізнаєтесь у Telegram.</span>
            <div class="danger-actions">
              <button type="button" class="btn btn-secondary" :disabled="unlinking" @click="confirming = false">Скасувати</button>
              <button type="button" class="btn btn-danger" :disabled="unlinking" @click="disconnect">
                {{ unlinking ? 'Відключаємо…' : 'Так, відключити' }}
              </button>
            </div>
          </template>
        </div>
      </IntegrationRow>
    </div>
  </section>
</template>
