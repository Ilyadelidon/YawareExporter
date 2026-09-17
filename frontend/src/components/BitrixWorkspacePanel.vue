<script setup>
import { computed, onMounted, ref } from 'vue';
import client from '../api/client';
import IntegrationRow from './IntegrationRow.vue';
import '../styles/integrations-ui.css';

// Робоча область Бітрікс24 одна на команду, тому підключає її адміністратор:
// портал і реквізити локального застосунку (OAuth 2.0). Доступ до тасок дає не
// вона, а особистий токен кожного працівника — його видає сам Бітрікс.
const loading = ref(true);
const status = ref(null);
const open = ref(false);
const confirming = ref(false);
const portalUrl = ref('');
const clientId = ref('');
const clientSecret = ref('');
const saving = ref(false);
const disconnecting = ref(false);
const copied = ref(false);
// { tone: 'ok' | 'error', text } — показується під рядком порталу
const notice = ref(null);

const connected = computed(() => Boolean(status.value?.workspace_connected));

// Той самий redirect_uri треба вказати в налаштуваннях застосунку на порталі —
// Бітрікс звіряє його побайтово, тож адресу дає бекенд, а не фронтенд.
const redirectUri = computed(() => status.value?.redirect_uri || '');

const portalLabel = computed(
  () => status.value?.portal_url?.replace(/^https:\/\//, '').replace(/\/$/, '') || '',
);

const rowStatus = computed(() => (connected.value
  ? { tone: 'ok', label: 'Підключено' }
  : { tone: 'off', label: 'Не підключено' }));

const rowMeta = computed(() => {
  if (!connected.value) return 'Один портал на всю команду — без нього працівники не зможуть обрати Бітрікс';
  return status.value.connected_by
    ? `${portalLabel.value} · підключив ${status.value.connected_by}`
    : portalLabel.value;
});

const canSubmit = computed(() => Boolean(
  portalUrl.value.trim() && clientId.value.trim() && clientSecret.value.trim(),
));

function toggle() {
  confirming.value = false;
  open.value = !open.value;
}

async function loadStatus() {
  try {
    const { data } = await client.get('/bitrix/status');
    status.value = data;
  } catch (error) {
    notice.value = { tone: 'error', text: error.response?.data?.message || 'Не вдалося отримати стан Бітрікс24.' };
  } finally {
    loading.value = false;
  }
}

async function connect() {
  if (!canSubmit.value || saving.value) return;
  saving.value = true;
  notice.value = null;
  try {
    const { data } = await client.post('/bitrix/workspace', {
      portal_url: portalUrl.value.trim(),
      client_id: clientId.value.trim(),
      client_secret: clientSecret.value.trim(),
    });
    clientSecret.value = '';
    open.value = false;
    notice.value = { tone: 'ok', text: data.message };
    await loadStatus();
  } catch (error) {
    notice.value = {
      tone: 'error',
      text: error.response?.data?.message
        || Object.values(error.response?.data?.errors || {})[0]?.[0]
        || 'Не вдалося підключити портал.',
    };
  } finally {
    saving.value = false;
  }
}

async function disconnect() {
  disconnecting.value = true;
  notice.value = null;
  try {
    const { data } = await client.delete('/bitrix/workspace');
    open.value = false;
    confirming.value = false;
    notice.value = { tone: 'ok', text: data.message };
    await loadStatus();
  } catch (error) {
    notice.value = { tone: 'error', text: error.response?.data?.message || 'Не вдалося відключити портал.' };
  } finally {
    disconnecting.value = false;
  }
}

async function copyRedirect() {
  try {
    await navigator.clipboard.writeText(redirectUri.value);
    copied.value = true;
    setTimeout(() => { copied.value = false; }, 2000);
  } catch {
    // без доступу до буфера адресу видно в полі — її можна виділити вручну
  }
}

onMounted(loadStatus);
</script>

<template>
  <section class="int-ui" aria-labelledby="sec-team-tracker">
    <header class="section-head">
      <div>
        <h2 id="sec-team-tracker" class="section-title">Таск-трекер команди</h2>
        <p class="section-desc">
          Портал, на який входять працівники, що обрали Бітрікс24. Таски читаються
          особистим токеном кожного, а не спільним ключем.
        </p>
      </div>
    </header>

    <div class="panel int-list" :aria-busy="loading">
      <div v-if="loading" class="row-skeleton"><div class="skeleton"></div></div>

      <IntegrationRow
        v-else
        id="bitrix-workspace"
        name="Портал Бітрікс24"
        :meta="rowMeta"
        :status="rowStatus"
        :open="open"
        :notice="notice"
      >
        <template #icon>
          <svg width="18" height="18" viewBox="0 0 24 24"><rect x="2" y="2" width="20" height="20" fill="#1B65A6"></rect><circle cx="12" cy="12" r="6.2" fill="none" stroke="#ffffff" stroke-width="2.4"></circle><circle cx="12" cy="12" r="2" fill="#ffffff"></circle></svg>
        </template>

        <template #action>
          <button
            type="button"
            class="btn"
            :class="connected ? 'btn-secondary' : 'btn-primary'"
            :aria-expanded="open"
            aria-controls="int-panel-bitrix-workspace"
            @click="toggle"
          >
            {{ connected ? 'Налаштування' : 'Підключити' }}
            <svg class="chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><polyline points="6 9 12 15 18 9"></polyline></svg>
          </button>
        </template>

        <!-- Портал підключено -->
        <template v-if="connected">
          <div class="fields">
            <div class="field">
              <div class="field-label">Портал</div>
              <div class="field-control">
                <a :href="status.portal_url" target="_blank" rel="noopener" class="field-link is-inline">{{ portalLabel }} ↗</a>
              </div>
            </div>

            <div class="field">
              <div class="field-label">Доступ до тасків</div>
              <div class="field-control">
                <span class="field-value">Особистий у кожного працівника (OAuth)</span>
              </div>
            </div>

            <div v-if="status.connected_by" class="field">
              <div class="field-label">Підключив</div>
              <div class="field-control">
                <span class="field-value">{{ status.connected_by }}</span>
              </div>
            </div>
          </div>

          <div class="danger">
            <template v-if="!confirming">
              <button type="button" class="btn btn-danger-ghost" @click="confirming = true">Відключити портал</button>
              <span class="danger-text">Щоб замінити реквізити застосунку, відключіть портал і підключіть заново.</span>
            </template>
            <template v-else>
              <span class="danger-text">
                Токени всіх працівників буде видалено, і їхні звіти перестануть отримувати таски з Бітрікса.
              </span>
              <div class="danger-actions">
                <button type="button" class="btn btn-secondary" :disabled="disconnecting" @click="confirming = false">Скасувати</button>
                <button type="button" class="btn btn-danger" :disabled="disconnecting" @click="disconnect">
                  {{ disconnecting ? 'Відключаємо…' : 'Так, відключити' }}
                </button>
              </div>
            </template>
          </div>
        </template>

        <!-- Портал ще не підключено: спершу застосунок на порталі, потім реквізити -->
        <template v-else>
          <ol class="steps">
            <li>
              У Бітріксі відкрийте <strong>Розробникам → Інші → Локальний застосунок</strong>,
              тип <strong>«Серверний»</strong>.
            </li>
            <li>
              Права: <strong>task</strong> (Завдання) і <strong>user</strong> (Користувачі).
              З обмеженим <code class="code">user_brief</code> портал не віддає пошту, і працівник
              не побачить, яким акаунтом підключився.
            </li>
            <li>
              Шлях повернення (redirect URI) — скопіюйте без змін:
              <span class="copy-row">
                <code class="code">{{ redirectUri }}</code>
                <button type="button" class="btn btn-link" :disabled="!redirectUri" @click="copyRedirect">
                  {{ copied ? 'Скопійовано' : 'Копіювати' }}
                </button>
              </span>
            </li>
            <li>Збережіть застосунок і перенесіть його реквізити у форму нижче.</li>
          </ol>

          <form class="fields" @submit.prevent="connect">
            <div class="field">
              <label class="field-label" for="bitrix-portal">Адреса порталу</label>
              <div class="field-control">
                <input
                  id="bitrix-portal"
                  v-model="portalUrl"
                  type="text"
                  class="input"
                  placeholder="https://ваш-портал.bitrix24.ua"
                  maxlength="255"
                />
              </div>
            </div>

            <div class="field">
              <label class="field-label" for="bitrix-client-id">ID застосунку</label>
              <div class="field-control">
                <input
                  id="bitrix-client-id"
                  v-model="clientId"
                  type="text"
                  class="input"
                  placeholder="local.6xxxxxxxxxxxxx.xxxxxxxx"
                  maxlength="255"
                />
                <p class="field-hint">Поле <strong>client_id</strong> на сторінці застосунку.</p>
              </div>
            </div>

            <div class="field">
              <label class="field-label" for="bitrix-client-secret">Ключ застосунку</label>
              <div class="field-control">
                <input
                  id="bitrix-client-secret"
                  v-model="clientSecret"
                  type="password"
                  class="input"
                  placeholder="client_secret"
                  maxlength="255"
                  autocomplete="off"
                />
                <p class="field-hint">
                  Зберігається зашифрованим. Самі таски сервіс читає не ним, а особистим токеном працівника.
                </p>
              </div>
            </div>

            <div class="field">
              <div></div>
              <div class="field-control">
                <button type="submit" class="btn btn-primary" :disabled="saving || !canSubmit">
                  {{ saving ? 'Зберігаємо…' : 'Підключити портал' }}
                </button>
              </div>
            </div>
          </form>
        </template>
      </IntegrationRow>
    </div>
  </section>
</template>

<style scoped>
/* Покрокова інструкція: нумерація відділяє «що зробити на порталі» від форми. */
.steps {
  margin: 0 0 18px;
  padding: 0 0 16px 18px;
  border-bottom: 1px solid #e8edf0;
  font-size: 12.5px;
  line-height: 1.55;
  color: var(--text-dim);
  max-width: 76ch;
}

.steps li + li {
  margin-top: 6px;
}

.steps li::marker {
  font-weight: 700;
  color: var(--muted);
}

.steps strong {
  color: #2b2f33;
}

.code {
  font-family: ui-monospace, SFMono-Regular, Consolas, monospace;
  font-size: 12px;
  color: #2b2f33;
  background: var(--line);
  padding: 1px 5px;
  word-break: break-all;
}

.copy-row {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 4px 8px;
  margin-top: 4px;
}
</style>
