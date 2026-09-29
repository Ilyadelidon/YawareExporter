<script setup>
// «Плани»: задачі проекту по розділах і таймлайн місяця, як у старій Google
// Таблиці плану. Клітинка дня — «працював над задачею» з коментарем;
// «Зараз» — задача, над якою людина працює в цю хвилину (одна на людину).
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import DatePicker from 'primevue/datepicker';
import Message from 'primevue/message';
import { defaultProject, rememberedProjectId, usePlan } from '../composables/usePlan';
import { usePlanExport } from '../composables/usePlanExport';
import { useAuthStore } from '../stores/auth';
import { useHintsStore } from '../stores/hints';
import { parseMonthParam, toMonthParam } from '../utils/dates';
import { filterTasks, groupTasks, monthDays } from '../utils/plans';
import PlanDayPopover from '../components/plans/PlanDayPopover.vue';
import PlanExportButton from '../components/plans/PlanExportButton.vue';
import PlanFilters from '../components/plans/PlanFilters.vue';
import PlanGoogleDialog from '../components/plans/PlanGoogleDialog.vue';
import PlanProjectDialog from '../components/plans/PlanProjectDialog.vue';
import PlanProjectTabs from '../components/plans/PlanProjectTabs.vue';
import PlanSectionDialog from '../components/plans/PlanSectionDialog.vue';
import PlanSheet from '../components/plans/PlanSheet.vue';
import PlanTaskDialog from '../components/plans/PlanTaskDialog.vue';
import UiIcon from '../components/UiIcon.vue';
import '../styles/plans-ui.css';

const auth = useAuthStore();
// Відмітка дня прибирає підказку «не вказано задачі» в меню без перезаходу.
const hints = useHintsStore();
const route = useRoute();
const router = useRouter();

const {
  projects, plan, loadingProjects, loadingPlan, error: errorMessage,
  loadProjects, loadPlan, reload, removeTask, changeStatus, toggleCurrent,
} = usePlan();

const showError = (message) => { errorMessage.value = message; };

const {
  google, exporting, exportDone, exportSummary, exportStuck, loadGoogle, startExport, resume,
} = usePlanExport({ onError: showError });

// Підказка в меню може вести на день минулого місяця: ?month=Y-m.
const selectedMonth = ref(parseMonthParam(route.query.month) || new Date());

const personFilter = ref('all');
const hideClosed = ref(true);

const taskDialog = ref({ visible: false, task: null, sectionId: null });
const projectDialog = ref({ visible: false, project: null });
const sectionDialog = ref({ visible: false, section: null, tasksCount: 0 });
const googleDialogVisible = ref(false);

const sheet = ref(null);
const dayPopover = ref(null);

const projectId = computed(() => Number(route.query.project) || null);
const activeProject = computed(() => projects.value.find((project) => project.id === projectId.value) || null);
const canManage = computed(() => Boolean(plan.value?.can_manage));
const myEmployeeId = computed(() => plan.value?.my_employee_id ?? null);
const canAddTasks = computed(() => canManage.value || Boolean(myEmployeeId.value));
const hasSubtasks = computed(() => Boolean(plan.value?.tasks.some((task) => task.subtasks?.length)));

const people = computed(() => (plan.value ? [...plan.value.members, ...plan.value.former_members] : []));
const peopleById = computed(() => Object.fromEntries(people.value.map((person) => [person.id, person])));

const days = computed(() => (plan.value ? monthDays(plan.value.month, plan.value.days_in_month, plan.value.today) : []));

const visibleTasks = computed(() => (plan.value
  ? filterTasks(plan.value.tasks, { person: personFilter.value, hideClosed: hideClosed.value })
  : []));

const groups = computed(() => (plan.value
  ? groupTasks(visibleTasks.value, plan.value.sections, personFilter.value === 'all' && canAddTasks.value)
  : []));

async function showPlan({ scroll = false } = {}) {
  const applied = await loadPlan(projectId.value, selectedMonth.value);
  if (applied && scroll) {
    await nextTick();
    sheet.value?.scrollToToday();
  }
}

function pickDefaultProject() {
  if (projectId.value && projects.value.some((project) => project.id === projectId.value)) return;

  const fallback = defaultProject(projects.value, rememberedProjectId());
  if (fallback) {
    router.replace({ query: { ...route.query, project: fallback.id } });
  }
}

function selectProject(id) {
  if (id === projectId.value) return;
  personFilter.value = 'all';
  router.replace({ query: { ...route.query, project: id } });
}

// --- Задачі й розділи ---------------------------------------------------

function openNewTask(sectionId = null) {
  taskDialog.value = { visible: true, task: null, sectionId };
}

function openTask(task) {
  taskDialog.value = { visible: true, task, sectionId: task.section_id };
}

function openNewSection() {
  sectionDialog.value = { visible: true, section: null, tasksCount: 0 };
}

function openSection(group) {
  sectionDialog.value = { visible: true, section: group.section, tasksCount: group.tasks.length };
}

async function onToggleCurrent(task, isCurrent) {
  if (await toggleCurrent(task, isCurrent)) hints.load();
}

// --- Проекти ------------------------------------------------------------

function openNewProject() {
  projectDialog.value = { visible: true, project: null };
}

function openProjectSettings() {
  projectDialog.value = { visible: true, project: activeProject.value };
}

async function onProjectSaved(project) {
  await loadProjects();
  if (project.id !== projectId.value) {
    selectProject(project.id);
  } else {
    await reload();
  }
}

async function onProjectDeleted() {
  await loadProjects();
  router.replace({ query: {} });
  pickDefaultProject();
}

// --- Google Таблиця -----------------------------------------------------

async function exportToGoogle() {
  if (!google.value?.spreadsheet_url) {
    googleDialogVisible.value = true;
    return;
  }

  errorMessage.value = '';
  await startExport();
}

watch(projectId, () => showPlan({ scroll: true }));
watch(() => route.query.month, () => {
  const month = parseMonthParam(route.query.month);
  if (month && toMonthParam(month) !== toMonthParam(selectedMonth.value)) selectedMonth.value = month;
});
watch(selectedMonth, (value) => {
  if (value) showPlan({ scroll: true });
});

onMounted(async () => {
  if (auth.isAdmin) resume();
  await loadProjects();
  if (projectId.value) {
    await showPlan({ scroll: true });
  } else {
    pickDefaultProject();
  }
});
</script>

<template>
  <div class="plans-page">
    <div class="page-head">
      <div class="page-head-info">
        <div class="page-head-icon">
          <UiIcon name="checklist" :size="20" color="#149d8d" />
        </div>
        <div>
          <div class="page-head-title">Плани</div>
          <div class="page-head-subtitle">Задачі проектів і хто над чим працював по днях</div>
        </div>
      </div>
      <div class="page-head-actions">
        <div class="field-pill">
          <UiIcon name="calendar" :size="15" color="#149d8d" />
          <DatePicker v-model="selectedMonth" view="month" date-format="mm.yy" :manual-input="false" />
        </div>
        <PlanExportButton
          v-if="auth.isAdmin"
          :google="google"
          :exporting="exporting"
          @export="exportToGoogle"
          @connect="googleDialogVisible = true"
        />
      </div>
    </div>

    <Message v-if="errorMessage" severity="error" :closable="true" class="page-message" @close="errorMessage = ''">
      {{ errorMessage }}
    </Message>

    <Message v-if="exportStuck" severity="warn" :closable="false" class="page-message">
      Експорт уже хвилину чекає в черзі — схоже, не працює обробник черги.
    </Message>

    <Message v-if="exportDone" severity="success" :closable="true" class="page-message" @close="exportDone = false">
      <div>
        Плани вивантажено —
        <a :href="google?.spreadsheet_url" target="_blank" rel="noopener">відкрити таблицю</a>
      </div>
      <div v-if="exportSummary" class="export-summary">{{ exportSummary }}</div>
    </Message>

    <div v-if="loadingProjects" class="skeleton plans-skeleton"></div>

    <div v-else-if="!projects.length" class="panel empty-panel">
      <template v-if="auth.isAdmin">
        <p class="empty-text">Проектів поки немає.</p>
        <button type="button" class="plan-btn is-accent" @click="openNewProject">
          <UiIcon name="plus" :size="14" :stroke-width="2.4" class="btn-icon" />
          Створити проект
        </button>
      </template>
      <template v-else>Ви ще не учасник жодного проекту. Коли адміністратор додасть вас, проект зʼявиться тут.</template>
    </div>

    <template v-else>
      <PlanProjectTabs
        :projects="projects"
        :active-id="projectId"
        :can-create="auth.isAdmin"
        @select="selectProject"
        @create="openNewProject"
      />

      <div v-if="loadingPlan && !plan" class="skeleton plans-skeleton"></div>

      <template v-else-if="plan">
        <PlanFilters
          v-model:person="personFilter"
          v-model:hide-closed="hideClosed"
          :people="people"
          :my-employee-id="myEmployeeId"
          :visible-count="visibleTasks.length"
          :total-count="plan.tasks.length"
          :can-add-tasks="canAddTasks"
          :can-manage="canManage"
          @new-section="openNewSection"
          @new-task="openNewTask(null)"
          @settings="openProjectSettings"
        />

        <div v-if="!plan.members.length && canManage" class="panel empty-panel">
          У проекті ще немає учасників. Додайте їх у «Проект» — лише учасникам можна ставити задачі.
        </div>

        <div v-else-if="!groups.length" class="panel empty-panel">
          {{ plan.tasks.length ? 'Під фільтр не потрапила жодна задача.' : 'Задач поки немає — додайте першу.' }}
        </div>

        <PlanSheet
          v-else
          ref="sheet"
          :groups="groups"
          :days="days"
          :statuses="plan.statuses"
          :people-by-id="peopleById"
          :my-employee-id="myEmployeeId"
          :can-manage="canManage"
          :can-add-tasks="canAddTasks"
          :reserve-subtasks="hasSubtasks"
          :loading="loadingPlan"
          @new-task="openNewTask"
          @edit-section="openSection"
          @open-task="openTask"
          @toggle-current="onToggleCurrent"
          @change-status="changeStatus"
          @day-click="(event, task, day) => dayPopover.open(event, task, day)"
        />

        <div class="legend">
          <span><i class="legend-swatch is-worked"></i>Працював над задачею</span>
          <span><i class="legend-swatch is-now"></i>Працює зараз</span>
          <span v-if="canAddTasks">Клік по дню відмічає його, повторний — коментар або зняти відмітку</span>
        </div>
      </template>
    </template>

    <PlanDayPopover ref="dayPopover" @error="showError" @changed="hints.load()" />

    <PlanTaskDialog
      v-if="plan"
      v-model:visible="taskDialog.visible"
      :project-id="plan.project.id"
      :project-name="plan.project.name"
      :task="taskDialog.task"
      :default-section-id="taskDialog.sectionId"
      :sections="plan.sections"
      :people="people"
      :statuses="plan.statuses"
      :can-manage="canManage"
      @saved="reload()"
      @deleted="removeTask"
    />

    <PlanSectionDialog
      v-if="plan"
      v-model:visible="sectionDialog.visible"
      :project-id="plan.project.id"
      :project-name="plan.project.name"
      :section="sectionDialog.section"
      :tasks-count="sectionDialog.tasksCount"
      :can-delete="canManage"
      @saved="reload()"
      @deleted="reload()"
    />

    <PlanProjectDialog
      v-model:visible="projectDialog.visible"
      :project="projectDialog.project"
      :member-ids="projectDialog.project && plan ? plan.members.map((person) => person.id) : []"
      @saved="onProjectSaved"
      @deleted="onProjectDeleted"
    />

    <PlanGoogleDialog
      v-if="auth.isAdmin"
      v-model:visible="googleDialogVisible"
      :google="google"
      @saved="loadGoogle"
    />
  </div>
</template>

<style scoped>
.plans-page {
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.page-message {
  margin-top: 14px;
}

.plans-skeleton {
  height: 240px;
  margin-top: 16px;
}

.empty-panel {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 12px;
  margin-top: 16px;
  padding: 20px 16px;
  font-size: 13.5px;
  color: var(--muted);
  text-align: center;
}

.empty-text {
  margin: 0;
}

.legend {
  display: flex;
  flex-wrap: wrap;
  gap: 8px 18px;
  margin-top: 10px;
  font-size: 12px;
  color: var(--muted);
}

.legend span {
  display: flex;
  align-items: center;
  gap: 6px;
}

.legend-swatch {
  display: inline-block;
  width: 18px;
  height: 12px;
  background: #8fd0c7;
}

.legend-swatch.is-now {
  background: var(--accent);
  box-shadow: 0 0 0 2px #d5f2ee, 0 0 0 3px var(--accent);
}
</style>
