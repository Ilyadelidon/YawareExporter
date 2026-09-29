<script setup>
// Таблиця плану: задачі по розділах і таймлайн місяця праворуч.
import { ref } from 'vue';
import { noteLink } from '../../utils/plans';
import PlanTaskRow from './PlanTaskRow.vue';
import UiIcon from '../UiIcon.vue';
import '../../styles/plan-sheet.css';

const props = defineProps({
  groups: { type: Array, required: true },
  days: { type: Array, required: true },
  statuses: { type: Object, required: true },
  peopleById: { type: Object, required: true },
  myEmployeeId: { type: Number, default: null },
  canManage: { type: Boolean, default: false },
  canAddTasks: { type: Boolean, default: false },
  reserveSubtasks: { type: Boolean, default: false },
  loading: { type: Boolean, default: false },
});

const emit = defineEmits(['new-task', 'edit-section', 'open-task', 'toggle-current', 'change-status', 'day-click']);

const panel = ref(null);
// Задачі, розгорнуті до підзадач із трекера.
const expanded = ref(new Set());

function canEdit(task) {
  return props.canManage || (props.myEmployeeId !== null && task.employee_id === props.myEmployeeId);
}

function isCurrent(task) {
  return props.peopleById[task.employee_id]?.current_task_id === task.id;
}

function toggleSubtasks(task) {
  const next = new Set(expanded.value);
  if (next.has(task.id)) next.delete(task.id);
  else next.add(task.id);
  expanded.value = next;
}

// Місяць довгий, а цікаве зазвичай — останні дні: одразу показуємо сьогодні.
function scrollToToday() {
  const el = panel.value;
  const todayCell = el?.querySelector('th.is-today');
  if (!el || !todayCell) return;
  const stickyWidth = el.querySelector('th.col-status')?.getBoundingClientRect().right
    - el.getBoundingClientRect().left || 0;
  el.scrollLeft = Math.max(0, todayCell.offsetLeft - stickyWidth - todayCell.offsetWidth * 8);
}

defineExpose({ scrollToToday });
</script>

<template>
  <div ref="panel" class="panel sheet-panel" :class="{ 'is-loading': loading }">
    <table class="plan-sheet">
      <thead>
        <tr>
          <th class="col-task">Задача</th>
          <th class="col-person">Виконавець</th>
          <th class="col-status">Статус</th>
          <th
            v-for="d in days"
            :key="d.iso"
            class="col-day"
            :class="{ 'is-weekend': d.weekend, 'is-today': d.today }"
          >
            <span class="day-num">{{ d.day }}</span>
            <span class="day-week">{{ d.weekday }}</span>
          </th>
        </tr>
      </thead>
      <tbody v-for="group in groups" :key="group.key">
        <tr class="section-row">
          <td class="col-section" colspan="3">
            <div class="section-head">
              <span class="section-name">{{ group.section ? group.section.name : 'Без розділу' }}</span>
              <a
                v-if="group.section && noteLink(group.section.note)"
                class="note-link"
                :href="noteLink(group.section.note)"
                target="_blank"
                rel="noopener"
                title="Відкрити посилання розділу"
              >
                <UiIcon name="external" :size="12" :stroke-width="2.2" />
              </a>
              <span class="section-count">{{ group.tasks.length }}</span>
              <span class="section-actions">
                <button v-if="canAddTasks" type="button" class="link-btn" @click="emit('new-task', group.section?.id ?? null)">+ задача</button>
                <button
                  v-if="canManage && group.section"
                  type="button"
                  class="link-btn"
                  @click="emit('edit-section', group)"
                >змінити</button>
              </span>
            </div>
          </td>
          <td :colspan="days.length" class="section-fill"></td>
        </tr>
        <PlanTaskRow
          v-for="task in group.tasks"
          :key="task.id"
          :task="task"
          :days="days"
          :statuses="statuses"
          :person-name="peopleById[task.employee_id]?.name || '—'"
          :editable="canEdit(task)"
          :current="isCurrent(task)"
          :expanded="expanded.has(task.id)"
          :reserve-subtasks="reserveSubtasks"
          @toggle-current="emit('toggle-current', task, isCurrent(task))"
          @toggle-subtasks="toggleSubtasks(task)"
          @open="emit('open-task', task)"
          @change-status="emit('change-status', task, $event)"
          @day-click="(event, day) => emit('day-click', event, task, day)"
        />
      </tbody>
    </table>
  </div>
</template>

<style scoped>
.sheet-panel {
  margin-top: 12px;
  overflow: auto;
  max-height: calc(100vh - 290px);
  animation: fadeUp 0.35s ease both;
  transition: opacity 0.15s ease;
}

.sheet-panel.is-loading {
  opacity: 0.6;
}

@media (max-width: 760px) {
  .sheet-panel {
    max-height: none;
  }
}
</style>
