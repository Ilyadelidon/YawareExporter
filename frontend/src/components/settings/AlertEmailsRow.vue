<script setup>
import { nextTick, onMounted, ref } from 'vue';
import { useAlertEmails } from '../../composables/settings/useAlertEmails';
import IntegrationRow from '../integrations/IntegrationRow.vue';
import ManageToggle from '../integrations/ManageToggle.vue';
import UiIcon from '../UiIcon.vue';
import SettingsSection from './SettingsSection.vue';

// Куди керівнику писати про критичні порушення з AI-розбору дня: свій список
// адрес, який можна поповнювати, правити на місці й чистити.
const alerts = useAlertEmails();
const {
  loading, open, notice, emails, othersCount, maxEmails, draft, saving,
  confirmingRemove, editing, editDraft, active, full, canAdd, canSaveEdit, status, meta,
} = alerts;

const editInput = ref(null);

async function startEdit(email) {
  alerts.startEdit(email);
  await nextTick();
  // Усередині v-for Vue складає ref-и в масив — у режимі правки там один рядок.
  const input = Array.isArray(editInput.value) ? editInput.value[0] : editInput.value;
  input?.focus();
  input?.select();
}

onMounted(alerts.load);
</script>

<template>
  <SettingsSection id="sec-alerts" title="Сповіщення керівнику" :loading="loading">
    <template #description>
      Листи про критичні порушення з AI-розбору дня: факт, підстава і готове питання до працівника.
    </template>

    <IntegrationRow
      id="alert-emails"
      name="Пошта про порушення"
      :meta="meta"
      :status="status"
      :open="open"
      :notice="notice"
    >
      <template #icon><UiIcon name="alert" :size="18" color="#b33c3c" /></template>

      <template #action>
        <ManageToggle target="alert-emails" :primary="!active" :expanded="open" @toggle="alerts.toggle">
          {{ active ? 'Налаштування' : 'Додати пошту' }}
        </ManageToggle>
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
                    @keyup.enter="alerts.saveEdit"
                    @keyup.esc="alerts.cancelEdit"
                  />
                  <div class="mail-actions">
                    <button type="button" class="btn btn-secondary" :disabled="saving" @click="alerts.cancelEdit">Скасувати</button>
                    <button type="button" class="btn btn-primary" :disabled="saving || !canSaveEdit" @click="alerts.saveEdit">
                      {{ saving ? 'Зберігаємо…' : 'Зберегти' }}
                    </button>
                  </div>
                </template>

                <template v-else-if="confirmingRemove === email">
                  <span class="mail-confirm">Прибрати останню адресу? Листи про порушення більше не приходитимуть.</span>
                  <div class="mail-actions">
                    <button type="button" class="btn btn-secondary" :disabled="saving" @click="alerts.cancelRemove">Скасувати</button>
                    <button type="button" class="btn btn-danger" :disabled="saving" @click="alerts.remove(email)">
                      {{ saving ? 'Прибираємо…' : 'Так, прибрати' }}
                    </button>
                  </div>
                </template>

                <template v-else>
                  <span class="mail-address">{{ email }}</span>
                  <div class="mail-actions">
                    <button type="button" class="btn btn-link" :disabled="saving" @click="startEdit(email)">Змінити</button>
                    <button type="button" class="btn btn-link is-danger" :disabled="saving" @click="alerts.remove(email)">Прибрати</button>
                  </div>
                </template>
              </li>
            </ul>
          </div>
        </div>

        <form class="field" @submit.prevent="alerts.add">
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
  </SettingsSection>
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
