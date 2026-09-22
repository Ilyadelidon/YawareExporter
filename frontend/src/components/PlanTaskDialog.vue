<script setup>
// Створення й редагування задачі плану. Виконавця вибирає лише
// адміністратор — працівник завжди ставить задачу собі.
import { computed, ref, watch } from 'vue';
import Dialog from 'primevue/dialog';
import Select from 'primevue/select';
import client from '../api/client';
import PlanConfirmDialog from './PlanConfirmDialog.vue';
import '../styles/plans-ui.css';

const props = defineProps({
  visible: { type: Boolean, default: false },
  projectId: { type: Number, required: true },
  projectName: { type: String, default: '' },
  // null — нова задача.
  task: { type: Object, default: null },
  defaultSectionId: { type: Number, default: null },
  sections: { type: Array, default: () => [] },
  people: { type: Array, default: () => [] },
  statuses: { type: Object, default: () => ({}) },
  canManage: { type: Boolean, default: false },
});

const emit = defineEmits(['update:visible', 'saved', 'deleted']);

const form = ref({});
const saving = ref(false);
const errorMessage = ref('');
const titleInvalid = ref(false);
const confirmVisible = ref(false);
const confirmError = ref('');

const isNew = computed(() => !props.task);
const members = computed(() => props.people.filter((person) => person.is_member));
// Select у PrimeVue вважає null «нічого не вибрано» і не показує підпис,
// тож «Без розділу» — це 0, а в запит іде null.
const NO_SECTION = 0;
const sectionOptions = computed(() => [{ id: NO_SECTION, name: 'Без розділу' }, ...props.sections]);
const statusOptions = computed(() => Object.entries(props.statuses).map(([value, label]) => ({ value, label })));

watch(() => props.visible, (visible) => {
  if (!visible) return;
  errorMessage.value = '';
  titleInvalid.value = false;
  form.value = props.task
    ? {
      title: props.task.title,
      note: props.task.note || '',
      section_id: props.task.section_id ?? NO_SECTION,
      status: props.task.status,
      employee_id: props.task.employee_id,
    }
    : {
      title: '',
      note: '',
      section_id: props.defaultSectionId ?? NO_SECTION,
      status: 'pending',
      employee_id: members.value.length === 1 ? members.value[0].id : null,
    };
});

function close() {
  emit('update:visible', false);
}

async function save() {
  if (!form.value.title.trim()) {
    titleInvalid.value = true;
    return;
  }
  if (props.canManage && !form.value.employee_id) {
    errorMessage.value = 'Виберіть виконавця.';
    return;
  }

  saving.value = true;
  errorMessage.value = '';

  const payload = {
    title: form.value.title.trim(),
    note: form.value.note.trim() || null,
    section_id: form.value.section_id || null,
    status: form.value.status,
  };
  if (props.canManage) {
    payload.employee_id = form.value.employee_id;
  }

  try {
    const { data } = isNew.value
      ? await client.post(`/plans/projects/${props.projectId}/tasks`, payload)
      : await client.patch(`/plans/tasks/${props.task.id}`, payload);
    emit('saved', data.data);
    close();
  } catch (error) {
    errorMessage.value = error.response?.data?.message || 'Не вдалося зберегти задачу.';
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
    await client.delete(`/plans/tasks/${props.task.id}`);
    confirmVisible.value = false;
    emit('deleted', props.task.id);
    close();
  } catch (error) {
    confirmError.value = error.response?.data?.message || 'Не вдалося видалити задачу.';
  } finally {
    saving.value = false;
  }
}

const daysMarked = computed(() => Object.keys(props.task?.days || {}).length);
</script>

<template>
  <Dialog
    :visible="visible"
    modal
    :draggable="false"
    class="task-dialog"
    :style="{ width: '560px', maxWidth: 'calc(100vw - 32px)' }"
    @update:visible="emit('update:visible', $event)"
  >
    <template #container="{ closeCallback }">
      <form class="task-dialog-body" @submit.prevent="save" @keydown.enter.ctrl.prevent="save">
        <header class="td-head">
          <div>
            <div class="td-title">{{ isNew ? 'Нова задача' : 'Редагування задачі' }}</div>
            <div v-if="projectName" class="td-subtitle">Проект {{ projectName }}</div>
          </div>
          <button type="button" class="td-close" aria-label="Закрити" @click="closeCallback">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
          </button>
        </header>

        <div class="td-content">
          <label class="td-field">
            <span class="td-label">Назва задачі</span>
            <textarea
              v-model="form.title"
              class="td-input td-title-input"
              :class="{ 'is-invalid': titleInvalid }"
              rows="2"
              maxlength="1000"
              placeholder="Що потрібно зробити"
              autofocus
              @input="titleInvalid = false"
            ></textarea>
            <span v-if="titleInvalid" class="td-field-error">Вкажіть назву задачі</span>
          </label>

          <label class="td-field">
            <span class="td-label">Посилання або примітка <em>необовʼязково</em></span>
            <textarea
              v-model="form.note"
              class="td-input"
              rows="2"
              maxlength="5000"
              placeholder="Посилання на Бітрікс, документ або коротке уточнення"
            ></textarea>
          </label>

          <div class="td-field">
            <span class="td-label">Статус</span>
            <div class="td-statuses" role="radiogroup" aria-label="Статус">
              <button
                v-for="option in statusOptions"
                :key="option.value"
                type="button"
                role="radio"
                class="td-status"
                :class="[`is-${option.value}`, { 'is-selected': form.status === option.value }]"
                :aria-checked="form.status === option.value"
                @click="form.status = option.value"
              >
                <span class="td-status-dot"></span>
                {{ option.label }}
              </button>
            </div>
          </div>

          <div class="td-grid" :class="{ 'is-single': !canManage }">
            <div class="td-field">
              <span class="td-label">Розділ</span>
              <Select
                v-model="form.section_id"
                :options="sectionOptions"
                option-label="name"
                option-value="id"
                class="td-select"
              />
            </div>

            <div v-if="canManage" class="td-field">
              <span class="td-label">Виконавець</span>
              <Select
                v-model="form.employee_id"
                :options="members"
                option-label="name"
                option-value="id"
                placeholder="Виберіть учасника"
                class="td-select"
                :empty-message="'У проекті немає учасників'"
              />
            </div>
          </div>

          <p v-if="errorMessage" class="td-error">{{ errorMessage }}</p>
        </div>

        <footer class="td-foot">
          <button v-if="!isNew" type="button" class="td-delete" :disabled="saving" @click="askDelete">Видалити задачу</button>
          <span class="td-foot-gap"></span>
          <button type="button" class="plan-btn" :disabled="saving" @click="closeCallback">Скасувати</button>
          <button type="submit" class="plan-btn is-accent" :disabled="saving">
            {{ saving ? 'Збереження…' : (isNew ? 'Додати задачу' : 'Зберегти') }}
          </button>
        </footer>
      </form>
    </template>
  </Dialog>

  <PlanConfirmDialog
    v-if="task"
    v-model:visible="confirmVisible"
    title="Видалити задачу?"
    :message="daysMarked
      ? `«${task.title}» зникне разом з відмітками днів за цей місяць (${daysMarked}). Це не можна скасувати.`
      : `«${task.title}» зникне разом з усіма відмітками днів. Це не можна скасувати.`"
    confirm-label="Видалити задачу"
    :busy="saving"
    :error="confirmError"
    @confirm="remove"
  />
</template>
