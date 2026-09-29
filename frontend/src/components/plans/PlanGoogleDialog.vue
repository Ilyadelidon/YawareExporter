<script setup>
// Спільна Google Таблиця планів: адміністратор вставляє посилання на наявну
// таблицю, куди експорт вивантажує всі проекти окремими аркушами.
import { ref, watch } from 'vue';
import Dialog from 'primevue/dialog';
import client from '../api/client';
import '../styles/plans-ui.css';

const props = defineProps({
  visible: { type: Boolean, default: false },
  // Відповідь GET /plans/google.
  google: { type: Object, default: null },
});

const emit = defineEmits(['update:visible', 'saved']);

const link = ref('');
const linkInvalid = ref(false);
const saving = ref(false);
const errorMessage = ref('');

watch(() => props.visible, (visible) => {
  if (!visible) return;
  link.value = props.google?.spreadsheet_url || '';
  linkInvalid.value = false;
  errorMessage.value = '';
});

function close() {
  emit('update:visible', false);
}

async function save() {
  if (!link.value.trim()) {
    linkInvalid.value = true;
    return;
  }

  saving.value = true;
  errorMessage.value = '';
  try {
    await client.put('/plans/google', { spreadsheet: link.value.trim() });
    emit('saved');
    close();
  } catch (error) {
    errorMessage.value = error.response?.data?.message || 'Не вдалося підключити таблицю.';
  } finally {
    saving.value = false;
  }
}

async function unlink() {
  saving.value = true;
  errorMessage.value = '';
  try {
    await client.delete('/plans/google');
    emit('saved');
    close();
  } catch (error) {
    errorMessage.value = error.response?.data?.message || 'Не вдалося відвʼязати таблицю.';
  } finally {
    saving.value = false;
  }
}
</script>

<template>
  <Dialog
    :visible="visible"
    modal
    :draggable="false"
    class="task-dialog"
    :style="{ width: '520px', maxWidth: 'calc(100vw - 32px)' }"
    @update:visible="emit('update:visible', $event)"
  >
    <template #container="{ closeCallback }">
      <form class="task-dialog-body" @submit.prevent="save">
        <header class="td-head">
          <div>
            <div class="td-title">Таблиця планів</div>
            <div class="td-subtitle">Плани вивантажуються в неї щодня автоматично, кожен проект — окремим аркушем «План — назва»</div>
          </div>
          <button type="button" class="td-close" aria-label="Закрити" @click="closeCallback">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
          </button>
        </header>

        <div class="td-content">
          <p v-if="!google?.account_connected" class="td-error">
            Google-акаунт не підключено — спершу підключіть його в налаштуваннях.
          </p>

          <label class="td-field">
            <span class="td-label">Посилання на Google Таблицю</span>
            <input
              v-model="link"
              class="td-input"
              :class="{ 'is-invalid': linkInvalid }"
              type="text"
              maxlength="2048"
              placeholder="https://docs.google.com/spreadsheets/d/…"
              autofocus
              @input="linkInvalid = false"
            >
            <span v-if="linkInvalid" class="td-field-error">Вставте посилання на таблицю</span>
          </label>

          <p v-if="google?.account_email" class="td-hint">
            У таблиці надайте доступ редактора акаунту <strong>{{ google.account_email }}</strong>.
            Інші аркуші таблиці експорт не чіпає.
          </p>

          <p class="td-hint">
            Таблиця — дзеркало сервісу: кожне вивантаження перезаписує аркуші проектів,
            тож правки, зроблені просто в таблиці, не зберігаються.
          </p>

          <p v-if="errorMessage" class="td-error">{{ errorMessage }}</p>
        </div>

        <footer class="td-foot">
          <button v-if="google?.spreadsheet_url" type="button" class="td-delete" :disabled="saving" @click="unlink">Відвʼязати</button>
          <span class="td-foot-gap"></span>
          <button type="button" class="plan-btn" :disabled="saving" @click="closeCallback">Скасувати</button>
          <button type="submit" class="plan-btn is-accent" :disabled="saving || !google?.account_connected">
            {{ saving ? 'Перевірка…' : 'Підключити' }}
          </button>
        </footer>
      </form>
    </template>
  </Dialog>
</template>
