<script setup>
// Відмітка дня. Порожній день відмічається одразу кліком — коментар
// необовʼязковий, тож не змушуємо заходити у форму заради самої відмітки;
// повторний клік відкриває коментар і «Зняти відмітку».
import { nextTick, ref } from 'vue';
import Popover from 'primevue/popover';
import { usePlanDays } from '../../composables/usePlanDays';
import { formatDottedDate } from '../../utils/dates';
import '../../styles/plans-ui.css';

const emit = defineEmits(['error', 'changed']);

const popover = ref(null);
const { edit, saving, isMarked, begin, mark, saveComment, unmark } = usePlanDays({
  onError: (message) => emit('error', message),
  onChange: () => emit('changed'),
});

async function open(event, task, day) {
  const target = event.currentTarget;
  const marked = isMarked(task, day.iso);

  popover.value.hide();
  begin(task, day.iso);

  if (!marked && !(await mark(task, day.iso))) return;

  await nextTick();
  // Після await у події вже немає currentTarget, а Popover саме по ньому
  // відрізняє «клік по якорю» від кліку повз — тож передаємо якір явно.
  popover.value.show({ currentTarget: target }, target);
}

async function save() {
  if (await saveComment()) popover.value.hide();
}

async function remove() {
  if (await unmark()) popover.value.hide();
}

defineExpose({ open });
</script>

<template>
  <Popover ref="popover">
    <form v-if="edit" class="day-editor" @submit.prevent="save">
      <div class="day-editor-head">
        <strong>{{ formatDottedDate(edit.iso) }}</strong>
        <span :title="edit.task.title">{{ edit.task.title }}</span>
      </div>
      <textarea
        v-model="edit.comment"
        rows="3"
        maxlength="2000"
        placeholder="Що зроблено (необовʼязково)"
        @keydown.enter.ctrl.prevent="save"
      ></textarea>
      <div class="plan-actions">
        <button type="button" class="plan-btn is-danger" :disabled="saving" @click="remove">Зняти відмітку</button>
        <span class="plan-actions-gap"></span>
        <button type="submit" class="plan-btn is-accent" :disabled="saving">Зберегти</button>
      </div>
    </form>
  </Popover>
</template>

<style scoped>
.day-editor {
  display: flex;
  flex-direction: column;
  gap: 10px;
  width: 300px;
  font-size: 13px;
}

.day-editor-head {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.day-editor-head strong {
  color: var(--ink);
}

.day-editor-head span {
  color: var(--muted);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.day-editor textarea {
  width: 100%;
  padding: 8px 10px;
  border: 1px solid #dfe5ea;
  font: inherit;
  resize: vertical;
}

.day-editor textarea:focus {
  outline: none;
  border-color: var(--accent);
}
</style>
