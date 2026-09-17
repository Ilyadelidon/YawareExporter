<script setup>
import { computed, nextTick, onMounted, ref } from 'vue';
import client from '../api/client';
import IntegrationRow from './IntegrationRow.vue';
import '../styles/integrations-ui.css';

// Пошти, на які керівнику йдуть листи про критичні порушення з AI-розбору дня.
// Список персональний: кожен адміністратор веде свої адреси сам, тому тут немає
// ні чужих адрес, ні спільного перемикача на команду.
const loading = ref(true);
const emails = ref([]);
const othersCount = ref(0);
const maxEmails = ref(10);
const draft = ref('');
const saving = ref(false);
const open = ref(false);
// { tone: 'ok' | 'error', text } — показується під рядком
const notice = ref(null);
// Остання адреса, яку просять прибрати: це вимикає листи, тож питаємо на місці.
const confirmingRemove = ref(null);
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

const rowStatus = computed(() => (active.value
  ? { tone: 'ok', label: 'Увімкнено' }
  : { tone: 'off', label: 'Вимкнено' }));

const rowMeta = computed(() => {
  if (!active.value) return 'Жодної адреси — листи про порушення нікуди не йдуть';
  if (emails.value.length === 1) return emails.value[0];
  return `${emails.value[0]} і ще ${emails.value.length - 1}`;
});

function apply(data) {
  emails.value = data.alert_emails || [];
  // Список міг змінитись під час правки (свій же збережений запит) — рядка,
  // який редагували, могло вже не стати.
  if (editing.value !== null && !emails.value.includes(editing.value)) cancelEdit();
  othersCount.value = data.others_count || 0;
  maxEmails.value = data.max_emails || maxEmails.value;
}

function toggle() {
  confirmingRemove.value = null;
  cancelEdit();
  open.value = !open.value;
}

async function loadStatus() {
  try {
    const { data } = await client.get('/alerts/emails');
    apply(data);
  } catch (error) {
    notice.value = { tone: 'error', text: error.response?.data?.message || 'Не вдалося отримати налаштування сповіщень.' };
  } finally {
    loading.value = false;
  }
}

async function save(next) {
  saving.value = true;
  notice.value = null;
  try {
    const { data } = await client.put('/alerts/emails', { alert_emails: next });
    apply(data);
    notice.value = { tone: 'ok', text: data.message };
    return true;
  } catch (error) {
    const errors = error.response?.data?.errors || {};
    // Помилка приходить на конкретну адресу (alert_emails.1) — показуємо першу.
    const field = Object.keys(errors).find((key) => key.startsWith('alert_emails'));
    notice.value = {
      tone: 'error',
      text: (field && errors[field][0])
        || error.response?.data?.message
        || 'Не вдалося зберегти пошти.',
    };
    return false;
  } finally {
    saving.value = false;
  }
}

async function add() {
  if (!canAdd.value || saving.value) return;
  const saved = await save([...emails.value, normalisedDraft.value]);
  if (saved) draft.value = '';
}

async function startEdit(email) {
  confirmingRemove.value = null;
  editing.value = email;
  editDraft.value = email;
  notice.value = null;
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

async function remove(email) {
  // Прибрати останню адресу — це вимкнути листи зовсім, і про це варто спитати.
  if (emails.value.length === 1 && confirmingRemove.value !== email) {
    cancelEdit();
    confirmingRemove.value = email;
    return;
  }
  const saved = await save(emails.value.filter((item) => item !== email));
  if (saved) confirmingRemove.value = null;
}

onMounted(loadStatus);
</script>

<template>
  <section class="int-ui" aria-labelledby="sec-alerts">
    <header class="section-head">
      <div>
        <h2 id="sec-alerts" class="section-title">Сповіщення керівнику</h2>
        <p class="section-desc">
          Листи про критичні порушення з AI-розбору дня: факт, підстава і готове питання до працівника.
        </p>
      </div>
    </header>

    <div class="panel int-list" :aria-busy="loading">
      <div v-if="loading" class="row-skeleton"><div class="skeleton"></div></div>

      <IntegrationRow
        v-else
        id="alert-emails"
        name="Пошта про порушення"
        :meta="rowMeta"
        :status="rowStatus"
        :open="open"
        :notice="notice"
      >
        <template #icon>
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#b33c3c" stroke-width="2"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
        </template>

        <template #action>
          <button
            type="button"
            class="btn"
            :class="active ? 'btn-secondary' : 'btn-primary'"
            :aria-expanded="open"
            aria-controls="int-panel-alert-emails"
            @click="toggle"
          >
            {{ active ? 'Налаштування' : 'Додати пошту' }}
            <svg class="chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><polyline points="6 9 12 15 18 9"></polyline></svg>
          </button>
        </template>

        <div class="fields">
          <div v-if="active" class="field">
            <div class="field-label">Ваші адреси</div>
            <div class="field-control">
              <ul class="mail-list">
                <li v-for="email in emails" :key="email" class="mail-item">
                  <template v-if="editing === email">
                    <input
                      ref="editInput"
                      v-model="editDraft"
                      type="email"
                      class="input control-grow"
                      maxlength="255"
                      autocomplete="email"
                      aria-label="Нова адреса"
                      @keyup.enter="saveEdit"
                      @keyup.esc="cancelEdit"
                    />
                    <div class="mail-actions">
                      <button type="button" class="btn btn-secondary" :disabled="saving" @click="cancelEdit">Скасувати</button>
                      <button type="button" class="btn btn-primary" :disabled="saving || !canSaveEdit" @click="saveEdit">
                        {{ saving ? 'Зберігаємо…' : 'Зберегти' }}
                      </button>
                    </div>
                  </template>

                  <template v-else-if="confirmingRemove === email">
                    <span class="mail-confirm">Прибрати останню адресу? Листи про порушення більше не приходитимуть.</span>
                    <div class="mail-actions">
                      <button type="button" class="btn btn-secondary" :disabled="saving" @click="confirmingRemove = null">Скасувати</button>
                      <button type="button" class="btn btn-danger" :disabled="saving" @click="remove(email)">
                        {{ saving ? 'Прибираємо…' : 'Так, прибрати' }}
                      </button>
                    </div>
                  </template>

                  <template v-else>
                    <span class="mail-address">{{ email }}</span>
                    <div class="mail-actions">
                      <button type="button" class="btn btn-link" :disabled="saving" @click="startEdit(email)">Змінити</button>
                      <button type="button" class="btn btn-link is-danger" :disabled="saving" @click="remove(email)">Прибрати</button>
                    </div>
                  </template>
                </li>
              </ul>
            </div>
          </div>

          <form class="field" @submit.prevent="add">
            <label class="field-label" for="alert-new-email">{{ active ? 'Ще одна адреса' : 'Адреса' }}</label>
            <div class="field-control">
              <div class="control-row">
                <input
                  id="alert-new-email"
                  v-model="draft"
                  type="email"
                  class="input control-grow"
                  :placeholder="full ? 'Більше адрес додати не можна' : 'boss@company.com'"
                  maxlength="255"
                  autocomplete="email"
                  :disabled="full"
                />
                <button type="submit" class="btn btn-secondary" :disabled="saving || !canAdd">
                  {{ saving ? 'Зберігаємо…' : 'Додати' }}
                </button>
              </div>
              <p class="field-hint">
                Не більше {{ maxEmails }} адрес. Лист іде раз на день і працівника — повторний
                розбір того самого дня його не дублює.
              </p>
            </div>
          </form>

          <div v-if="othersCount" class="field">
            <div class="field-label">Інші адміністратори</div>
            <div class="field-control">
              <span class="field-value is-plain">
                Такі листи отримують ще {{ othersCount }} адрес(и) — кожен веде свій список сам.
              </span>
            </div>
          </div>
        </div>
      </IntegrationRow>
    </div>
  </section>
</template>

<style scoped>
/* Список адрес — таблиця в один стовпчик: адреса ліворуч, дії праворуч. */
.mail-list {
  list-style: none;
  margin: 0;
  padding: 0;
  border: 1px solid var(--control-line);
  background: var(--surface);
}

.mail-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px 12px;
  min-height: 40px;
  padding: 4px 6px 4px 10px;
}

.mail-item + .mail-item {
  border-top: 1px solid var(--line);
}

.mail-address {
  font-size: 13px;
  color: #2b2f33;
  overflow-wrap: anywhere;
}

.mail-confirm {
  font-size: 12.5px;
  color: var(--text-dim);
}

.mail-actions {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-shrink: 0;
}

.field-value.is-plain {
  font-weight: 400;
  color: var(--text-dim);
}

@media (max-width: 760px) {
  .mail-item {
    flex-wrap: wrap;
    padding: 8px 10px;
  }
}
</style>
