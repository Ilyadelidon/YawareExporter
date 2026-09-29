<script setup>
// Проект «Планів» (лише адміністратор): назва, учасники, архів, видалення.
import { computed, ref, watch } from 'vue';
import Dialog from 'primevue/dialog';
import client from '../api/client';
import PlanConfirmDialog from './PlanConfirmDialog.vue';
import '../styles/plans-ui.css';

const props = defineProps({
  visible: { type: Boolean, default: false },
  // null — новий проект.
  project: { type: Object, default: null },
  memberIds: { type: Array, default: () => [] },
});

const emit = defineEmits(['update:visible', 'saved', 'deleted']);

const employees = ref([]);
const name = ref('');
const selected = ref([]);
const nameInvalid = ref(false);
const saving = ref(false);
const errorMessage = ref('');
const confirmVisible = ref(false);
const confirmError = ref('');

const isNew = computed(() => !props.project);
// Звільнених не пропонуємо, але тих, хто вже в проекті, не губимо.
const choices = computed(() => employees.value.filter(
  (employee) => !employee.dismissed_at || selected.value.includes(employee.id),
));

watch(() => props.visible, async (visible) => {
  if (!visible) return;
  errorMessage.value = '';
  nameInvalid.value = false;
  name.value = props.project?.name || '';
  selected.value = [...props.memberIds];

  if (!employees.value.length) {
    try {
      const { data } = await client.get('/employees');
      employees.value = data.data;
    } catch {
      errorMessage.value = 'Не вдалося завантажити працівників.';
    }
  }
});

function toggle(id) {
  selected.value = selected.value.includes(id)
    ? selected.value.filter((value) => value !== id)
    : [...selected.value, id];
}

async function save() {
  if (!name.value.trim()) {
    nameInvalid.value = true;
    return;
  }

  saving.value = true;
  errorMessage.value = '';
  try {
    let project;
    if (isNew.value) {
      ({ data: { data: project } } = await client.post('/plans/projects', {
        name: name.value.trim(),
        employee_ids: selected.value,
      }));
    } else {
      await client.patch(`/plans/projects/${props.project.id}`, { name: name.value.trim() });
      ({ data: { data: project } } = await client.put(`/plans/projects/${props.project.id}/members`, {
        employee_ids: selected.value,
      }));
    }
    emit('saved', project);
    emit('update:visible', false);
  } catch (error) {
    errorMessage.value = error.response?.data?.message || 'Не вдалося зберегти проект.';
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
    await client.delete(`/plans/projects/${props.project.id}`);
    confirmVisible.value = false;
    emit('deleted', props.project.id);
    emit('update:visible', false);
  } catch (error) {
    confirmError.value = error.response?.data?.message || 'Не вдалося видалити проект.';
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
    :style="{ width: '500px', maxWidth: 'calc(100vw - 32px)' }"
    @update:visible="emit('update:visible', $event)"
  >
    <template #container="{ closeCallback }">
      <form class="task-dialog-body" @submit.prevent="save">
        <header class="td-head">
          <div>
            <div class="td-title">{{ isNew ? 'Новий проект' : 'Налаштування проекту' }}</div>
            <div class="td-subtitle">
              {{ isNew ? 'Учасники бачитимуть проект і вестимуть у ньому свої задачі' : `Задач у проекті: ${project.tasks_count}` }}
            </div>
          </div>
          <button type="button" class="td-close" aria-label="Закрити" @click="closeCallback">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
          </button>
        </header>

        <div class="td-content">
          <label class="td-field">
            <span class="td-label">Назва проекту</span>
            <input
              v-model="name"
              class="td-input td-title-input"
              :class="{ 'is-invalid': nameInvalid }"
              type="text"
              maxlength="255"
              placeholder="Наприклад, TumTum або Brok"
              autofocus
              @input="nameInvalid = false"
            >
            <span v-if="nameInvalid" class="td-field-error">Вкажіть назву проекту</span>
          </label>

          <div class="td-field">
            <span class="td-label">
              Учасники
              <em>{{ selected.length ? `вибрано ${selected.length}` : 'ще нікого' }}</em>
            </span>
            <div class="member-list">
              <label v-for="employee in choices" :key="employee.id" class="member-option">
                <input type="checkbox" :checked="selected.includes(employee.id)" @change="toggle(employee.id)">
                <span class="member-name">{{ employee.name }}</span>
                <span v-if="employee.position" class="member-position">{{ employee.position }}</span>
              </label>
              <div v-if="!choices.length" class="member-empty">Працівників ще немає.</div>
            </div>
          </div>

          <p v-if="errorMessage" class="td-error">{{ errorMessage }}</p>
        </div>

        <footer class="td-foot">
          <button v-if="!isNew" type="button" class="td-delete" :disabled="saving" @click="askDelete">Видалити проект</button>
          <span class="td-foot-gap"></span>
          <button type="button" class="plan-btn" :disabled="saving" @click="closeCallback">Скасувати</button>
          <button type="submit" class="plan-btn is-accent" :disabled="saving">
            {{ saving ? 'Збереження…' : (isNew ? 'Створити проект' : 'Зберегти') }}
          </button>
        </footer>
      </form>
    </template>
  </Dialog>

  <PlanConfirmDialog
    v-if="project"
    v-model:visible="confirmVisible"
    :title="`Видалити проект «${project.name}»?`"
    :message="`Разом із ним зникнуть усі його задачі (${project.tasks_count}), розділи й відмітки днів. Це не можна скасувати — якщо проект просто завершився, краще перенести його в архів.`"
    confirm-label="Видалити проект"
    :busy="saving"
    :error="confirmError"
    @confirm="remove"
  />
</template>

<style scoped>
.member-list {
  max-height: 240px;
  overflow-y: auto;
  border: 1px solid #dfe5ea;
}

.member-option {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 9px 11px;
  border-bottom: 1px solid var(--line);
  cursor: pointer;
}

.member-option:last-child {
  border-bottom: none;
}

.member-option:hover {
  background: #f7f9fa;
}

.member-option input {
  width: 15px;
  height: 15px;
  accent-color: var(--accent);
  cursor: pointer;
}

.member-name {
  font-size: 13.5px;
  font-weight: 600;
  color: #2f3437;
}

.member-position {
  margin-left: auto;
  padding-left: 10px;
  font-size: 12px;
  color: var(--muted);
  text-align: right;
}

.member-empty {
  padding: 12px;
  font-size: 13px;
  color: var(--muted);
}
</style>
