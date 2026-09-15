<script setup>
import { computed, onMounted, ref } from 'vue';
import Message from 'primevue/message';
import client from '../api/client';

// Робоча область Бітрікс24 одна на команду, тому підключає її адміністратор:
// портал і реквізити локального застосунку (OAuth 2.0). Доступ до тасок дає не
// вона, а особистий токен кожного працівника — його видає сам Бітрікс.
const loading = ref(true);
const status = ref(null);
const portalUrl = ref('');
const clientId = ref('');
const clientSecret = ref('');
const saving = ref(false);
const disconnecting = ref(false);
const errorMessage = ref('');
const actionMessage = ref('');

const connected = computed(() => Boolean(status.value?.workspace_connected));

// Той самий redirect_uri треба вказати в налаштуваннях застосунку на порталі —
// Бітрікс звіряє його побайтово, тож адресу дає бекенд, а не фронтенд.
const redirectUri = computed(() => status.value?.redirect_uri || '');

const canSubmit = computed(() => Boolean(
  portalUrl.value.trim() && clientId.value.trim() && clientSecret.value.trim(),
));

async function loadStatus() {
  try {
    const { data } = await client.get('/bitrix/status');
    status.value = data;
  } catch (error) {
    errorMessage.value = error.response?.data?.message || 'Не вдалося отримати стан Бітрікс24.';
  } finally {
    loading.value = false;
  }
}

async function connect() {
  if (!canSubmit.value) return;
  saving.value = true;
  errorMessage.value = '';
  actionMessage.value = '';
  try {
    const { data } = await client.post('/bitrix/workspace', {
      portal_url: portalUrl.value.trim(),
      client_id: clientId.value.trim(),
      client_secret: clientSecret.value.trim(),
    });
    actionMessage.value = data.message;
    clientSecret.value = '';
    await loadStatus();
  } catch (error) {
    errorMessage.value = error.response?.data?.message
      || Object.values(error.response?.data?.errors || {})[0]?.[0]
      || 'Не вдалося підключити портал.';
  } finally {
    saving.value = false;
  }
}

async function disconnect() {
  if (!window.confirm('Відключити портал Бітрікс24? Токени всіх працівників буде видалено, і їхні звіти перестануть отримувати таски з Бітрікса.')) return;
  disconnecting.value = true;
  errorMessage.value = '';
  actionMessage.value = '';
  try {
    const { data } = await client.delete('/bitrix/workspace');
    actionMessage.value = data.message;
    await loadStatus();
  } catch (error) {
    errorMessage.value = error.response?.data?.message || 'Не вдалося відключити портал.';
  } finally {
    disconnecting.value = false;
  }
}

onMounted(loadStatus);
</script>

<template>
  <div class="panel workspace-panel">
    <div class="workspace-head">
      <div class="workspace-icon">
        <svg width="18" height="18" viewBox="0 0 24 24"><rect x="2" y="2" width="20" height="20" fill="#1B65A6"></rect><circle cx="12" cy="12" r="6.2" fill="none" stroke="#ffffff" stroke-width="2.4"></circle><circle cx="12" cy="12" r="2" fill="#ffffff"></circle></svg>
      </div>
      <div class="workspace-info">
        <div class="workspace-title-row">
          <span class="workspace-name">Робоча область Бітрікс24</span>
          <span class="workspace-badge" :class="{ 'is-off': !connected }">
            <span class="workspace-badge-dot"></span>{{ connected ? 'Підключено' : 'Не підключено' }}
          </span>
        </div>
        <div class="workspace-subtitle">
          Один портал на всю команду. Працівники, які вибрали Бітрікс, входять на нього
          під собою — сервіс читає таски від імені кожного, а не спільним ключем.
        </div>
      </div>
    </div>

    <div v-if="loading" class="skeleton workspace-skeleton"></div>

    <template v-else-if="connected">
      <div class="workspace-row">
        <div>
          <div class="field-label">Портал</div>
          <a :href="status.portal_url" target="_blank" rel="noopener" class="workspace-link">
            {{ status.portal_url }} ↗
          </a>
        </div>
        <div>
          <div class="field-label">Доступ</div>
          <div class="workspace-value">особистий у кожного працівника (OAuth)</div>
        </div>
        <div v-if="status.connected_by">
          <div class="field-label">Підключив</div>
          <div class="workspace-value">{{ status.connected_by }}</div>
        </div>
      </div>
      <div class="workspace-foot">
        <span class="workspace-note">
          Щоб замінити реквізити застосунку, підключіть портал заново — працівникам
          доведеться авторизуватись ще раз.
        </span>
        <button class="workspace-unlink" type="button" :disabled="disconnecting" @click="disconnect">
          {{ disconnecting ? 'Відключаємо…' : 'Відключити портал' }}
        </button>
      </div>
    </template>

    <template v-else>
      <div class="field-label">Адреса порталу</div>
      <input
        v-model="portalUrl"
        type="text"
        class="workspace-input is-block"
        placeholder="https://ваш-портал.bitrix24.ua"
        maxlength="255"
      >

      <div class="field-label">ID застосунку (client_id)</div>
      <input
        v-model="clientId"
        type="text"
        class="workspace-input is-block"
        placeholder="local.6xxxxxxxxxxxxx.xxxxxxxx"
        maxlength="255"
      >

      <div class="field-label">Ключ застосунку (client_secret)</div>
      <div class="workspace-form">
        <input
          v-model="clientSecret"
          type="password"
          class="workspace-input"
          placeholder="Секретний ключ зі сторінки застосунку"
          maxlength="255"
          autocomplete="off"
          @keyup.enter="connect"
        >
        <button class="workspace-btn" type="button" :disabled="saving || !canSubmit" @click="connect">
          {{ saving ? 'Зберігаємо…' : 'Підключити' }}
        </button>
      </div>

      <p class="workspace-note">
        У Бітріксі: <strong>Розробникам → Інші → Локальний застосунок</strong> →
        тип <strong>«Серверний»</strong>. Права: <strong>task</strong> (Завдання) і
        <strong>user</strong> (Користувачі — з обмеженим <code class="workspace-code">user_brief</code>
        портал не віддає пошту, і працівник не побачить, яким акаунтом підключився).
        Шлях повернення (redirect URI):
        <code class="workspace-code">{{ redirectUri }}</code> — скопіюйте його в застосунок
        без змін. Ключ застосунку зберігається зашифрованим; самі таски сервіс читає
        не ним, а особистим токеном працівника.
      </p>
    </template>

    <Message v-if="errorMessage" severity="error" :closable="false" class="workspace-message">{{ errorMessage }}</Message>
    <Message v-else-if="actionMessage" severity="success" :closable="false" class="workspace-message">{{ actionMessage }}</Message>
  </div>
</template>

<style scoped>
.workspace-panel {
  padding: 16px;
  margin-bottom: 16px;
}

.workspace-head {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  margin-bottom: 14px;
}

.workspace-icon {
  display: flex;
  flex-shrink: 0;
  margin-top: 1px;
}

.workspace-info {
  min-width: 0;
}

.workspace-title-row {
  display: flex;
  align-items: center;
  gap: 10px;
}

.workspace-name {
  font-size: 13.5px;
  font-weight: 700;
  color: var(--ink);
}

.workspace-badge {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  font-size: 11px;
  font-weight: 600;
  color: var(--accent);
}

.workspace-badge.is-off {
  color: var(--muted-2);
}

.workspace-badge-dot {
  width: 6px;
  height: 6px;
  background: currentColor;
  /* виняток із глобального border-radius: 0 — індикатор лишається круглим */
  border-radius: 50% !important;
}

.workspace-subtitle {
  font-size: 12.5px;
  color: var(--muted);
  margin-top: 3px;
  max-width: 640px;
}

.workspace-skeleton {
  height: 56px;
}

.field-label {
  font-size: 11px;
  font-weight: 700;
  color: var(--muted-2);
  text-transform: uppercase;
  letter-spacing: 0.05em;
  margin-bottom: 5px;
}

.workspace-row {
  display: flex;
  flex-wrap: wrap;
  gap: 24px;
  margin-bottom: 14px;
}

.workspace-value {
  font-size: 13px;
  color: var(--ink);
}

.workspace-link {
  font-size: 13px;
  font-weight: 600;
  color: var(--accent);
  text-decoration: none;
}

.workspace-link:hover {
  text-decoration: underline;
}

.workspace-form {
  display: flex;
  gap: 8px;
  margin-bottom: 10px;
}

.workspace-input {
  flex: 1;
  min-width: 0;
  padding: 8px 11px;
  border: 1px solid var(--line);
  background: var(--surface);
  font-family: inherit;
  font-size: 13px;
  color: var(--ink);
}

.workspace-input:focus {
  outline: none;
  border-color: var(--accent);
}

/* Поля реквізитів застосунку йдуть одне під одним, кнопка — біля останнього. */
.workspace-input.is-block {
  width: 100%;
  margin-bottom: 12px;
}

.workspace-code {
  font-family: inherit;
  font-size: 12px;
  color: var(--text-dim);
  background: var(--surface-2, rgba(0, 0, 0, 0.04));
  padding: 1px 5px;
  word-break: break-all;
}

.workspace-btn {
  padding: 8px 18px;
  border: 1px solid var(--accent);
  background: var(--accent);
  color: #ffffff;
  font-family: inherit;
  font-size: 12.5px;
  font-weight: 600;
  cursor: pointer;
  flex-shrink: 0;
}

.workspace-btn:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}

.workspace-foot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
}

.workspace-note {
  font-size: 12.5px;
  color: var(--muted);
  margin: 0;
}

.workspace-note strong {
  color: var(--text-dim);
}

.workspace-unlink {
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

.workspace-unlink:hover:not(:disabled) {
  color: #c0392b;
}

.workspace-message {
  margin-top: 12px;
}

@media (max-width: 760px) {
  .workspace-form {
    flex-direction: column;
  }
}
</style>
