<script setup>
// Рядок задачі в таблиці плану: «працюю зараз», назва з приміткою і
// трекером, статус, клітинки днів — і розгорнуті підзадачі з трекера.
import { computed } from 'vue';
import { PLAN_TRACKERS } from '../../constants/trackers';
import { isClosedStatus } from '../../constants/plans';
import { noteLink, noteText } from '../../utils/plans';
import UiIcon from '../UiIcon.vue';
import '../../styles/plan-sheet.css';

const props = defineProps({
  task: { type: Object, required: true },
  days: { type: Array, required: true },
  statuses: { type: Object, required: true },
  personName: { type: String, required: true },
  editable: { type: Boolean, default: false },
  current: { type: Boolean, default: false },
  expanded: { type: Boolean, default: false },
  // Місце під стрілку підзадач — лише якщо вони взагалі є в плані, інакше
  // назви задач змістились би дарма.
  reserveSubtasks: { type: Boolean, default: false },
});

const emit = defineEmits(['toggle-current', 'toggle-subtasks', 'open', 'change-status', 'day-click']);

const closed = computed(() => isClosedStatus(props.task.status));
const tracker = computed(() => PLAN_TRACKERS[props.task.tracker] ?? null);

function isWorked(day) {
  return Object.hasOwn(props.task.days, day.iso);
}

function dayTitle(day) {
  if (!isWorked(day)) {
    return props.editable && !day.future ? 'Відмітити день' : '';
  }
  return props.task.days[day.iso] || 'Працював над задачею';
}

function onDayClick(event, day) {
  if (props.editable && !day.future) emit('day-click', event, day);
}
</script>

<template>
  <tr class="task-row" :class="{ 'is-closed': closed, 'is-current': current }">
    <td class="col-task">
      <div class="task-cell">
        <button
          v-if="(editable && !closed) || current"
          type="button"
          class="now-btn"
          :class="{ 'is-on': current }"
          :disabled="!editable"
          :title="current ? 'Зараз працює над цією задачею. Натисніть, щоб зняти' : 'Працюю над цим зараз'"
          @click="emit('toggle-current')"
        >
          <span v-if="current" class="now-dot"></span>
          <UiIcon v-else name="play" :size="10" />
        </button>
        <span v-else class="now-spacer"></span>
        <button
          v-if="task.subtasks?.length"
          type="button"
          class="subtasks-toggle"
          :class="{ 'is-open': expanded }"
          :title="expanded ? 'Згорнути підзадачі' : `Підзадачі в ${tracker?.label ?? 'трекері'}`"
          :aria-expanded="expanded"
          @click="emit('toggle-subtasks')"
        >
          <UiIcon name="chevron-right" :size="10" :stroke-width="3" />
          {{ task.subtasks.length }}
        </button>
        <span v-else-if="reserveSubtasks" class="subtasks-spacer"></span>
        <div class="task-text">
          <button
            v-if="editable"
            type="button"
            class="task-title is-editable"
            :title="task.title"
            @click="emit('open')"
          >{{ task.title }}</button>
          <span v-else class="task-title" :title="task.title">{{ task.title }}</span>
          <span v-if="task.note" class="task-note" :title="task.note">
            <a v-if="noteLink(task.note)" :href="noteLink(task.note)" target="_blank" rel="noopener" class="note-link">посилання</a>
            {{ noteText(task.note) }}
          </span>
          <span v-if="tracker && task.tracker_state === 'unlinked'" class="task-note" :title="tracker.unlinkedHint">
            {{ tracker.unlinked }}
          </span>
          <a v-else-if="tracker && task.tracker_url" :href="task.tracker_url" target="_blank" rel="noopener" class="task-note note-link" :title="tracker.linkHint">{{ tracker.label }}</a>
        </div>
      </div>
    </td>
    <td class="col-person" :title="personName">{{ personName }}</td>
    <td class="col-status">
      <select
        v-if="editable"
        class="status-select"
        :class="`is-${task.status}`"
        :value="task.status"
        @change="emit('change-status', $event.target.value)"
      >
        <option v-for="(label, key) in statuses" :key="key" :value="key">{{ label }}</option>
      </select>
      <span v-else class="status-text" :class="`is-${task.status}`">{{ statuses[task.status] || task.status }}</span>
    </td>
    <td
      v-for="d in days"
      :key="d.iso"
      class="col-day day-cell"
      :class="{
        'is-weekend': d.weekend,
        'is-today': d.today,
        'is-worked': isWorked(d),
        'is-now': d.today && current,
        'is-editable': editable && !d.future,
      }"
      :title="dayTitle(d)"
      @click="onDayClick($event, d)"
    ></td>
  </tr>
  <template v-if="expanded">
    <tr v-for="subtask in task.subtasks" :key="`${task.id}-${subtask.id}`" class="subtask-row">
      <td class="col-task">
        <div class="subtask-cell">
          <a v-if="subtask.url" :href="subtask.url" target="_blank" rel="noopener" class="subtask-title" :title="subtask.title">{{ subtask.title }}</a>
          <span v-else class="subtask-title" :title="subtask.title">{{ subtask.title }}</span>
        </div>
      </td>
      <td class="col-person"></td>
      <td class="col-status"></td>
      <td :colspan="days.length"></td>
    </tr>
  </template>
</template>
