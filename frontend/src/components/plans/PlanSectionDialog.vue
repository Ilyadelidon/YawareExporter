<script setup>
// Створення й редагування розділу плану. Перейменовує й видаляє розділ лише
// адміністратор, додати новий може будь-який учасник проекту.
import { computed, ref, watch } from 'vue';
import Dialog from 'primevue/dialog';
import client from '../api/client';
import PlanConfirmDialog from './PlanConfirmDialog.vue';
import '../styles/plans-ui.css';

const props = defineProps({
  visible: { type: Boolean, default: false },
  projectId: { type: Number, required: true },
  projectName: { type: String, default: '' },
  // null — новий розділ.
  section: { type: Object, default: null },
  // Скільки задач у розділі — щоб пояснити, що з ними станеться при видаленні.
  tasksCount: { type: Number, default: 0 },
  canDelete: { type: Boolean, default: false },
});

const emit = defineEmits(['update:visible', 'saved', 'deleted']);

const name = ref('');
const note = ref('');
const nameInvalid = ref(false);
const saving = ref(false);
const errorMessage = ref('');
const confirmVisible = ref(false);
const confirmError = ref('');

const isNew = computed(() => !props.section);

const deleteMessage = computed(() => (props.tasksCount
  ? `Задачі з нього (${props.tasksCount}) не зникнуть — вони перейдуть у «Без розділу» разом з усіма відмітками днів.`
  : 'Розділ порожній, задач у ньому немає.'));

watch(() => props.visible, (visible) => {
  if (!visible) return;
  name.value = props.section?.name || '';
  note.value = props.section?.note || '';
  nameInvalid.value = false;
  errorMessage.value = '';
});

function close() {
  emit('update:visible', false);
}

async function save() {
  if (!name.value.trim()) {
    nameInvalid.value = true;
    return;
  }

  saving.value = true;
  errorMessage.value = '';
  const payload = { name: name.value.trim(), note: note.value.trim() || null };

  try {
    const { data } = isNew.value
      ? await client.post(`/plans/projects/${props.projectId}/sections`, payload)
      : await client.patch(`/plans/sections/${props.section.id}`, payload);
    emit('saved', data.data);
    close();
  } catch (error) {
    errorMessage.value = error.response?.data?.message || 'Не вдалося зберегти розділ.';
  } finally {
    saving.value = false;
  }
}

function askDelete() {
  confirmError.value = '';
  confirmVisible.value = true;
}

async function remove() {
  saving.value = true;
  confirmError.value = '';
  try {
    await client.delete(`/plans/sections/${props.section.id}`);
    confirmVisible.value = false;
    emit('deleted', props.section.id);
    close();
  } catch (error) {
    confirmError.value = error.response?.data?.message || 'Не вдалося видалити розділ.';
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
    :style="{ width: '480px', maxWidth: 'calc(100vw - 32px)' }"
    @update:visible="emit('update:visible', $event)"
  >
    <template #container="{ closeCallback }">
      <form class="task-dialog-body" @submit.prevent="save">
        <header class="td-head">
          <div>
            <div class="td-title">{{ isNew ? 'Новий розділ' : 'Редагування розділу' }}</div>
            <div v-if="projectName" class="td-subtitle">Проект {{ projectName }}</div>
          </div>
          <button type="button" class="td-close" aria-label="Закрити" @click="closeCallback">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
          </button>
        </header>

        <div class="td-content">
          <label class="td-field">
            <span class="td-label">Назва розділу</span>
            <input
              v-model="name"
              class="td-input td-title-input"
              :class="{ 'is-invalid': nameInvalid }"
              type="text"
              maxlength="255"
              placeholder="Наприклад, Кабінет або Заявки"
              autofocus
              @input="nameInvalid = false"
            >
            <span v-if="nameInvalid" class="td-field-error">Вкажіть назву розділу</span>
          </label>

          <label class="td-field">
            <span class="td-label">Посилання або примітка <em>необовʼязково</em></span>
            <textarea
              v-model="note"
              class="td-input"
              rows="2"
              maxlength="2000"
              placeholder="Документ зі списком правок або коротке уточнення"
            ></textarea>
          </label>

          <p v-if="errorMessage" class="td-error">{{ errorMessage }}</p>
        </div>

        <footer class="td-foot">
          <button v-if="!isNew && canDelete" type="button" class="td-delete" :disabled="saving" @click="askDelete">Видалити розділ</button>
          <span class="td-foot-gap"></span>
          <button type="button" class="plan-btn" :disabled="saving" @click="closeCallback">Скасувати</button>
          <button type="submit" class="plan-btn is-accent" :disabled="saving">
            {{ saving ? 'Збереження…' : (isNew ? 'Додати розділ' : 'Зберегти') }}
          </button>
        </footer>
      </form>
    </template>
  </Dialog>

  <PlanConfirmDialog
    v-if="section"
    v-model:visible="confirmVisible"
    :title="`Видалити розділ «${section.name}»?`"
    :message="deleteMessage"
    confirm-label="Видалити розділ"
    :busy="saving"
    :error="confirmError"
    @confirm="remove"
  />
</template>
