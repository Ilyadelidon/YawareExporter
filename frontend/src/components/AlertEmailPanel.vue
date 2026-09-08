<script setup>
import { computed, nextTick, onMounted, ref } from 'vue';
import Message from 'primevue/message';
import client from '../api/client';

// Пошти, на які керівнику йдуть листи про критичні порушення з AI-розбору дня.
// Список персональний: кожен адміністратор веде свої адреси сам, тому тут немає
// ні чужих адрес, ні спільного перемикача на команду.
const loading = ref(true);
const emails = ref([]);
const othersCount = ref(0);
const maxEmails = ref(10);
const draft = ref('');
const saving = ref(false);
const errorMessage = ref('');
const actionMessage = ref('');
// Адреса, яку зараз правлять просто в списку: у пошті легко помилитись на одну
// літеру, і перепечатувати її заново через «прибрати — додати» безглуздо.
const editing = ref(null);
const editDraft = ref('');
const editInput = ref(null);

const active = computed(() => emails.value.length > 0);
const full = computed(() => emails.value.length >= maxEmails.value);
// Адресу зводимо до того ж вигляду, що й сервер, — інакше та сама пошта,
// написана з великої літери, виглядала б як нова.
const normalisedDraft = computed(() => draft.value.trim().toLowerCase());
const canAdd = computed(
  () => normalisedDraft.value !== '' && !full.value && !emails.value.includes(normalisedDraft.value),
);
const normalisedEdit = computed(() => editDraft.value.trim().toLowerCase());
// Правка на ту саму адресу дозволена (просто закриє поле), на чужу зі списку — ні.
const canSaveEdit = computed(
  () => normalisedEdit.value !== ''
    && (normalisedEdit.value === editing.value || !emails.value.includes(normalisedEdit.value)),
);

function apply(data) {
  emails.value = data.alert_emails || [];
  // Список міг змінитись під час правки (свій же збережений запит) — рядка,
  // який редагували, могло вже не стати.
  if (editing.value !== null && !emails.value.includes(editing.value)) cancelEdit();
  othersCount.value = data.others_count || 0;
  maxEmails.value = data.max_emails || maxEmails.value;
}

async function loadStatus() {
  try {
    const { data } = await client.get('/alerts/emails');
    apply(data);
  } catch (error) {
    errorMessage.value = error.response?.data?.message || 'Не вдалося отримати налаштування сповіщень.';
  } finally {
    loading.value = false;
  }
}

async function save(next) {
  saving.value = true;
  errorMessage.value = '';
  actionMessage.value = '';
  try {
    const { data } = await client.put('/alerts/emails', { alert_emails: next });
    apply(data);
    actionMessage.value = data.message;
    return true;
  } catch (error) {
    const errors = error.response?.data?.errors || {};
    // Помилка приходить на конкретну адресу (alert_emails.1) — показуємо першу.
    const field = Object.keys(errors).find((key) => key.startsWith('alert_emails'));
    errorMessage.value = (field && errors[field][0])
      || error.response?.data?.message
      || 'Не вдалося зберегти пошти.';
    return false;
  } finally {
    saving.value = false;
  }
}

async function add() {
  if (!canAdd.value) return;
  const saved = await save([...emails.value, normalisedDraft.value]);
  if (saved) draft.value = '';
}

async function startEdit(email) {
  editing.value = email;
  editDraft.value = email;
  errorMessage.value = '';
  actionMessage.value = '';
  await nextTick();
  // Усередині v-for Vue складає ref-и в масив — у режимі правки там один рядок.
  const input = Array.isArray(editInput.value) ? editInput.value[0] : editInput.value;
  input?.focus();
  input?.select();
}

function cancelEdit() {
  editing.value = null;
  editDraft.value = '';
}

async function saveEdit() {
  if (!canSaveEdit.value) return;

  const was = editing.value;
  const now = normalisedEdit.value;

  // Нічого не змінилось — не смикаємо сервер зайвим запитом.
  if (was === now) {
    cancelEdit();
    return;
  }

  const saved = await save(emails.value.map((item) => (item === was ? now : item)));
  if (saved) cancelEdit();
}

function remove(email) {
  // Прибрати останню адресу — це вимкнути листи зовсім, і про це варто спитати.
  if (emails.value.length === 1
    && !window.confirm('Прибрати останню пошту? Розбори днів залишаться, але листи про порушення до вас не приходитимуть.')) {
    return;
  }
  save(emails.value.filter((item) => item !== email));
}

onMounted(loadStatus);
</script>

<template>
  <div class="panel alerts-panel">
    <div class="alerts-head">
      <div class="alerts-icon">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#b33c3c" stroke-width="2"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
      </div>
      <div class="alerts-info">
        <div class="alerts-title-row">
          <span class="alerts-name">Сповіщення про порушення</span>
          <span class="alerts-badge" :class="{ 'is-off': !active }">
            <span class="alerts-badge-dot"></span>{{ active ? 'Увімкнено' : 'Вимкнено' }}
          </span>
        </div>
        <div class="alerts-subtitle">
          Якщо AI-розбір дня знаходить критичне порушення, на ці пошти приходить лист
          із фактом, підставою і готовим питанням до працівника.
        </div>
      </div>
    </div>

    <div v-if="loading" class="skeleton alerts-skeleton"></div>

    <template v-else>
      <div class="field-label">Пошти для листів</div>

      <ul v-if="active" class="alerts-list">
        <li v-for="email in emails" :key="email" class="alerts-item">
          <template v-if="editing === email">
            <input
              ref="editInput"
              v-model="editDraft"
              type="email"
              class="alerts-input alerts-item-input"
              maxlength="255"
              autocomplete="email"
              @keyup.enter="saveEdit"
              @keyup.esc="cancelEdit"
            >
            <div class="alerts-item-actions">
              <button class="alerts-item-save" type="button" :disabled="saving || !canSaveEdit" @click="saveEdit">
                {{ saving ? 'Зберігаємо…' : 'Зберегти' }}
              </button>
              <button class="alerts-item-link" type="button" :disabled="saving" @click="cancelEdit">
                Скасувати
              </button>
            </div>
          </template>
          <template v-else>
            <button class="alerts-item-mail" type="button" :disabled="saving" @click="startEdit(email)">
              {{ email }}
            </button>
            <div class="alerts-item-actions">
              <button class="alerts-item-link" type="button" :disabled="saving" @click="startEdit(email)">
                Змінити
              </button>
              <button class="alerts-item-link is-danger" type="button" :disabled="saving" @click="remove(email)">
                Прибрати
              </button>
            </div>
          </template>
        </li>
      </ul>
      <p v-else class="alerts-empty">Жодної адреси — листи про порушення нікуди не йдуть.</p>

      <div class="alerts-form">
        <input
          v-model="draft"
          type="email"
          class="alerts-input"
          :placeholder="full ? 'Більше адрес додати не можна' : 'boss@company.com'"
          maxlength="255"
          autocomplete="email"
          :disabled="full"
          @keyup.enter="add"
        >
        <button
          class="alerts-btn"
          type="button"
          :disabled="saving || !canAdd"
          @click="add"
        >
          {{ saving ? 'Зберігаємо…' : 'Додати' }}
        </button>
      </div>

      <p class="alerts-note">
        Лист іде раз на день і працівника — повторний розбір того самого дня його не дублює.
        <template v-if="othersCount">
          Крім ваших, такі листи отримують ще {{ othersCount }} адрес(и) інших адміністраторів.
        </template>
      </p>
    </template>

    <Message v-if="errorMessage" severity="error" :closable="false" class="alerts-message">{{ errorMessage }}</Message>
    <Message v-else-if="actionMessage" severity="success" :closable="false" class="alerts-message">{{ actionMessage }}</Message>
  </div>
</template>

<style scoped>
.alerts-panel {
  padding: 16px;
  margin-bottom: 16px;
}

.alerts-head {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  margin-bottom: 14px;
}

.alerts-icon {
  display: flex;
  flex-shrink: 0;
  margin-top: 1px;
}

.alerts-info {
  min-width: 0;
}

.alerts-title-row {
  display: flex;
  align-items: center;
  gap: 10px;
}

.alerts-name {
  font-size: 13.5px;
  font-weight: 700;
  color: var(--ink);
}

.alerts-badge {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  font-size: 11px;
  font-weight: 600;
  color: var(--accent);
}

.alerts-badge.is-off {
  color: var(--muted-2);
}

.alerts-badge-dot {
  width: 6px;
  height: 6px;
  background: currentColor;
  /* виняток із глобального border-radius: 0 — індикатор лишається круглим */
  border-radius: 50% !important;
}

.alerts-subtitle {
  font-size: 12.5px;
  color: var(--muted);
  margin-top: 3px;
  max-width: 640px;
}

.alerts-skeleton {
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

.alerts-list {
  list-style: none;
  margin: 0 0 10px;
  padding: 0;
  border: 1px solid var(--line);
}

.alerts-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 7px 11px;
  border-bottom: 1px solid var(--line);
}

.alerts-item:last-child {
  border-bottom: none;
}

/* Сама адреса — теж кнопка: клік по ній відкриває правку на місці. */
.alerts-item-mail {
  font-size: 13px;
  color: var(--ink);
  overflow-wrap: anywhere;
  text-align: left;
  background: none;
  border: none;
  padding: 0;
  font-family: inherit;
  cursor: text;
}

.alerts-item-mail:hover:not(:disabled) {
  color: var(--accent);
}

.alerts-item-input {
  flex: 1;
  padding: 5px 8px;
  font-size: 13px;
}

.alerts-item-actions {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-shrink: 0;
}

.alerts-item-link {
  font-size: 12px;
  color: var(--muted-2);
  text-decoration: underline;
  background: none;
  border: none;
  cursor: pointer;
  font-family: inherit;
  padding: 0;
}

.alerts-item-link:hover:not(:disabled) {
  color: var(--accent);
}

.alerts-item-link.is-danger:hover:not(:disabled) {
  color: #c0392b;
}

.alerts-item-save {
  font-size: 12px;
  font-weight: 600;
  color: var(--accent);
  background: none;
  border: none;
  cursor: pointer;
  font-family: inherit;
  padding: 0;
}

.alerts-item-link:disabled,
.alerts-item-save:disabled,
.alerts-item-mail:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}

.alerts-empty {
  font-size: 12.5px;
  color: var(--muted);
  margin: 0 0 10px;
}

.alerts-form {
  display: flex;
  gap: 8px;
  margin-bottom: 10px;
}

.alerts-input {
  flex: 1;
  min-width: 0;
  padding: 8px 11px;
  border: 1px solid var(--line);
  background: var(--surface);
  font-family: inherit;
  font-size: 13px;
  color: var(--ink);
}

.alerts-input:disabled {
  cursor: not-allowed;
  opacity: 0.6;
}

.alerts-input:focus {
  outline: none;
  border-color: var(--accent);
}

.alerts-btn {
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

.alerts-btn:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}

.alerts-note {
  font-size: 12.5px;
  color: var(--muted);
  margin: 0;
}

.alerts-message {
  margin-top: 12px;
}

@media (max-width: 760px) {
  .alerts-form {
    flex-direction: column;
  }
}
</style>
