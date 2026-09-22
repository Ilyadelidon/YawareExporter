<script setup>
// «Плани»: задачі проекту по розділах і таймлайн місяця, як у старій Google
// Таблиці плану. Клітинка дня — «працював над задачею» з коментарем;
// «Зараз» — задача, над якою людина працює в цю хвилину (одна на людину).
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import DatePicker from 'primevue/datepicker';
import Message from 'primevue/message';
import Popover from 'primevue/popover';
import client from '../api/client';
import { useAuthStore } from '../stores/auth';
import PlanGoogleDialog from '../components/PlanGoogleDialog.vue';
import PlanProjectDialog from '../components/PlanProjectDialog.vue';
import PlanSectionDialog from '../components/PlanSectionDialog.vue';
import PlanTaskDialog from '../components/PlanTaskDialog.vue';

const CLOSED_STATUSES = ['done', 'not_relevant'];
const WEEKDAYS = ['Нд', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб'];
const LAST_PROJECT_KEY = 'plans.lastProject';

const auth = useAuthStore();
const route = useRoute();
const router = useRouter();

const projects = ref([]);
const plan = ref(null);
const loadingProjects = ref(true);
const loadingPlan = ref(false);
const errorMessage = ref('');
const selectedMonth = ref(new Date());

const personFilter = ref('all');
const hideClosed = ref(true);

const taskDialog = ref({ visible: false, task: null, sectionId: null });
const projectDialog = ref({ visible: false, project: null });
const sectionDialog = ref({ visible: false, section: null, tasksCount: 0 });
const exporting = ref(false);
// Спільна таблиця планів (лише для адміністратора).
const google = ref(null);
const googleDialogVisible = ref(false);
const exportDone = ref(false);
// Що прогін підтягнув із таблиці (і чого не зміг) — текстом із бекенду.
const exportSummary = ref('');
// Задача стоїть у черзі надто довго — найчастіше не запущений обробник черги.
const exportStuck = ref(false);

const googleMenu = ref(null);
const dayPopover = ref(null);
const dayEdit = ref(null);
const daySaving = ref(false);
const sheetPanel = ref(null);

const projectId = computed(() => Number(route.query.project) || null);
const canManage = computed(() => Boolean(plan.value?.can_manage));

// Підпис кнопки каже, що станеться по кліку: без привʼязаної таблиці це не
// експорт, а вікно підключення.
const exportLabel = computed(() => {
  if (exporting.value) {
    return google.value?.export?.status === 'queued' ? 'У черзі…' : 'Вивантаження…';
  }

  return google.value?.spreadsheet_url ? 'Вивантажити в Google' : 'Підключити таблицю';
});

const exportHint = computed(() => {
  if (!google.value?.spreadsheet_url) return 'Обрати Google Таблицю для планів';

  return `Оновити плани в таблиці «${google.value.spreadsheet_title || 'Google Таблиця'}» просто зараз`;
});

// Щоранку плани вивантажує планувальник, тож кнопка потрібна лише для
// оновлення серед дня. Без цього рядка автоматичний прогін ніяк не видно —
// і незрозуміло, чи таблиця взагалі свіжа.
const lastExportLabel = computed(() => {
  const exported = google.value?.export;
  if (exported?.status !== 'done' || !exported.at) return 'Оновлюється щодня автоматично о 06:40';

  const stamp = new Date(exported.at).toLocaleString('uk-UA', {
    day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit',
  });

  return `Оновлюється щодня о 06:40. Востаннє — ${stamp}`;
});
const myEmployeeId = computed(() => plan.value?.my_employee_id ?? null);

const people = computed(() => (plan.value ? [...plan.value.members, ...plan.value.former_members] : []));
const peopleById = computed(() => Object.fromEntries(people.value.map((person) => [person.id, person])));

const days = computed(() => {
  if (!plan.value) return [];
  const [year, month] = plan.value.month.split('-').map(Number);
  return Array.from({ length: plan.value.days_in_month }, (_, i) => {
    const iso = `${plan.value.month}-${String(i + 1).padStart(2, '0')}`;
    const weekday = new Date(year, month - 1, i + 1).getDay();
    return {
      day: i + 1,
      iso,
      weekday: WEEKDAYS[weekday],
      weekend: weekday === 0 || weekday === 6,
      today: iso === plan.value.today,
      future: iso > plan.value.today,
    };
  });
});

const visibleTasks = computed(() => {
  if (!plan.value) return [];
  return plan.value.tasks.filter((task) => {
    if (personFilter.value !== 'all' && task.employee_id !== personFilter.value) return false;
    // Закриту задачу лишаємо, якщо над нею працювали цього місяця — інакше
    // таймлайн місяця втратив би частину відміток.
    if (hideClosed.value && CLOSED_STATUSES.includes(task.status) && !Object.keys(task.days).length) return false;
    return true;
  });
});

const groups = computed(() => {
  if (!plan.value) return [];
  const bySection = new Map();
  for (const task of visibleTasks.value) {
    const key = task.section_id ?? 0;
    if (!bySection.has(key)) bySection.set(key, []);
    bySection.get(key).push(task);
  }

  const result = [];
  if (bySection.has(0)) {
    result.push({ key: 'none', section: null, tasks: bySection.get(0) });
  }
  for (const section of plan.value.sections) {
    const tasks = bySection.get(section.id) || [];
    // Порожній розділ показуємо лише без фільтрів — щоб у нього можна було
    // додати першу задачу, а не щоб засмічував відфільтрований вигляд.
    if (tasks.length || (personFilter.value === 'all' && canAddTasks.value)) {
      result.push({ key: section.id, section, tasks });
    }
  }
  return result;
});

const canAddTasks = computed(() => canManage.value || Boolean(myEmployeeId.value));
const activeProject = computed(() => projects.value.find((project) => project.id === projectId.value) || null);

function monthParam(date) {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`;
}

function canEdit(task) {
  return canManage.value || (myEmployeeId.value !== null && task.employee_id === myEmployeeId.value);
}

function isCurrent(task) {
  return peopleById.value[task.employee_id]?.current_task_id === task.id;
}

function shortName(employeeId) {
  return peopleById.value[employeeId]?.name || '—';
}

function statusLabel(status) {
  return plan.value?.statuses[status] || status;
}

const URL_PATTERN = /https?:\/\/\S+/;

function noteLink(note) {
  return note?.match(URL_PATTERN)?.[0] || null;
}

function noteText(note) {
  return (note || '').replace(URL_PATTERN, '').replace(/\s+/g, ' ').trim();
}

async function loadProjects() {
  loadingProjects.value = true;
  try {
    const { data } = await client.get('/plans/projects');
    projects.value = data.data;
  } catch (error) {
    errorMessage.value = error.response?.data?.message || 'Не вдалося завантажити проекти.';
  } finally {
    loadingProjects.value = false;
  }
}

function pickDefaultProject() {
  if (projectId.value && projects.value.some((project) => project.id === projectId.value)) return;

  let remembered = null;
  try {
    remembered = Number(localStorage.getItem(LAST_PROJECT_KEY)) || null;
  } catch {
    // Сховище може бути недоступне — тоді просто відкриваємо перший проект.
  }
  const fallback = projects.value.find((project) => project.id === remembered)
    || projects.value.find((project) => !project.archived)
    || projects.value[0];

  if (fallback) {
    router.replace({ query: { ...route.query, project: fallback.id } });
  }
}

async function loadPlan({ scroll = false } = {}) {
  if (!projectId.value) {
    plan.value = null;
    return;
  }

  loadingPlan.value = true;
  errorMessage.value = '';
  try {
    const { data } = await client.get(`/plans/projects/${projectId.value}`, {
      params: { month: monthParam(selectedMonth.value) },
    });
    plan.value = data;
    try {
      localStorage.setItem(LAST_PROJECT_KEY, String(projectId.value));
    } catch {
      // Не критично: наступного разу відкриється перший проект.
    }
  } catch (error) {
    plan.value = null;
    errorMessage.value = error.response?.data?.message || 'Не вдалося завантажити план.';
  } finally {
    loadingPlan.value = false;
  }

  if (scroll) {
    await nextTick();
    scrollToToday();
  }
}

// Місяць довгий, а цікаве зазвичай — останні дні: одразу показуємо сьогодні.
function scrollToToday() {
  const panel = sheetPanel.value;
  const todayCell = panel?.querySelector('th.is-today');
  if (!panel || !todayCell) return;
  const stickyWidth = panel.querySelector('th.col-status')?.getBoundingClientRect().right
    - panel.getBoundingClientRect().left || 0;
  panel.scrollLeft = Math.max(0, todayCell.offsetLeft - stickyWidth - todayCell.offsetWidth * 8);
}

function selectProject(id) {
  if (id === projectId.value) return;
  personFilter.value = 'all';
  router.replace({ query: { ...route.query, project: id } });
}

// --- Задачі -------------------------------------------------------------

function openNewTask(sectionId = null) {
  taskDialog.value = { visible: true, task: null, sectionId };
}

function openTask(task) {
  taskDialog.value = { visible: true, task, sectionId: task.section_id };
}

async function onTaskSaved() {
  await loadPlan();
}

function onTaskDeleted(id) {
  plan.value.tasks = plan.value.tasks.filter((task) => task.id !== id);
}

async function changeStatus(task, status) {
  const previous = task.status;
  task.status = status;
  try {
    await client.patch(`/plans/tasks/${task.id}`, { status });
    // Закрита задача могла перестати бути поточною — підтягуємо стан.
    if (CLOSED_STATUSES.includes(status) || status === 'paused') await loadPlan();
  } catch (error) {
    task.status = previous;
    errorMessage.value = error.response?.data?.message || 'Не вдалося змінити статус.';
  }
}

async function toggleCurrent(task) {
  try {
    if (isCurrent(task)) {
      await client.delete(`/plans/tasks/${task.id}/current`);
    } else {
      await client.put(`/plans/tasks/${task.id}/current`);
    }
    await loadPlan();
  } catch (error) {
    errorMessage.value = error.response?.data?.message || 'Не вдалося змінити поточну задачу.';
  }
}

// --- Розділи ------------------------------------------------------------

function openNewSection() {
  sectionDialog.value = { visible: true, section: null, tasksCount: 0 };
}

function openSection(group) {
  sectionDialog.value = { visible: true, section: group.section, tasksCount: group.tasks.length };
}

// --- Дні ----------------------------------------------------------------

async function onDayClick(event, task, day) {
  const target = event.currentTarget;
  const marked = Object.hasOwn(task.days, day.iso);

  if (!canEdit(task) || day.future) {
    return;
  }

  dayPopover.value.hide();
  dayEdit.value = { task, iso: day.iso, comment: marked ? task.days[day.iso] : '', marked };

  // Порожній день відмічається одразу кліком — коментар необовʼязковий,
  // тож не змушуємо заходити у форму заради самої відмітки.
  if (!marked) {
    task.days = { ...task.days, [day.iso]: '' };
    try {
      await client.put(`/plans/tasks/${task.id}/days/${day.iso}`);
      dayEdit.value.marked = true;
    } catch (error) {
      const { [day.iso]: _, ...rest } = task.days;
      task.days = rest;
      errorMessage.value = error.response?.data?.message || 'Не вдалося відмітити день.';
      return;
    }
  }

  await nextTick();
  // Після await у події вже немає currentTarget, а Popover саме по ньому
  // відрізняє «клік по якорю» від кліку повз — тож передаємо якір явно.
  dayPopover.value.show({ currentTarget: target }, target);
}

async function saveDayComment() {
  const edit = dayEdit.value;
  daySaving.value = true;
  try {
    const { data } = await client.put(`/plans/tasks/${edit.task.id}/days/${edit.iso}`, { comment: edit.comment });
    edit.task.days = { ...edit.task.days, [edit.iso]: data.data.comment };
    edit.task.last_worked_on = edit.task.last_worked_on > edit.iso ? edit.task.last_worked_on : edit.iso;
    dayPopover.value.hide();
  } catch (error) {
    errorMessage.value = error.response?.data?.message || 'Не вдалося зберегти коментар.';
  } finally {
    daySaving.value = false;
  }
}

async function unmarkDay() {
  const edit = dayEdit.value;
  daySaving.value = true;
  try {
    await client.delete(`/plans/tasks/${edit.task.id}/days/${edit.iso}`);
    const { [edit.iso]: _, ...rest } = edit.task.days;
    edit.task.days = rest;
    dayPopover.value.hide();
  } catch (error) {
    errorMessage.value = error.response?.data?.message || 'Не вдалося зняти відмітку.';
  } finally {
    daySaving.value = false;
  }
}

function dayTitle(task, day) {
  if (!Object.hasOwn(task.days, day.iso)) {
    return canEdit(task) && !day.future ? 'Відмітити день' : '';
  }
  return task.days[day.iso] || 'Працював над задачею';
}

function formatDay(iso) {
  const [year, month, day] = iso.split('-');
  return `${day}.${month}.${year}`;
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
    await loadPlan();
  }
}

async function onProjectDeleted() {
  await loadProjects();
  router.replace({ query: {} });
  pickDefaultProject();
}

function openGoogleDialog(event) {
  googleMenu.value?.hide(event);
  googleDialogVisible.value = true;
}

async function loadGoogle() {
  if (!auth.isAdmin) return;
  try {
    const { data } = await client.get('/plans/google');
    google.value = data.data;
  } catch {
    google.value = null;
  }
}

function sleep(ms) {
  return new Promise((resolve) => { setTimeout(resolve, ms); });
}

// Експорт іде у фоні: велику таблицю Google «прокидає» хвилинами, тож
// опитуємо стан, доки фонова задача не завершиться. Якщо вона хвилину не
// зрушила з черги, кажемо про це прямо — інакше сторінка нескінченно
// показує «Вивантаження…» при непрацюючому обробнику черги.
async function waitForExport() {
  exporting.value = true;
  exportStuck.value = false;
  const queuedSince = Date.now();
  try {
    while (['queued', 'running'].includes(google.value?.export?.status)) {
      await sleep(3000);
      await loadGoogle();
      exportStuck.value = google.value?.export?.status === 'queued' && Date.now() - queuedSince > 60000;
    }
    const result = google.value?.export;
    if (result?.status === 'done') {
      exportDone.value = true;
      exportSummary.value = result.message || '';
    } else if (result?.status === 'failed') {
      errorMessage.value = `Не вдалося вивантажити плани в Google Таблицю: ${result.message || 'невідома помилка'}`;
    }
  } finally {
    exporting.value = false;
    exportStuck.value = false;
  }
}

async function exportToGoogle() {
  if (!google.value?.spreadsheet_url) {
    googleDialogVisible.value = true;
    return;
  }

  errorMessage.value = '';
  exportDone.value = false;
  exportSummary.value = '';
  try {
    const { data } = await client.post('/plans/export');
    google.value = { ...google.value, export: data.data.export };
  } catch (error) {
    errorMessage.value = error.response?.data?.message || 'Не вдалося вивантажити плани в Google Таблицю.';
    return;
  }
  await waitForExport();
}

watch(projectId, () => loadPlan({ scroll: true }));
watch(selectedMonth, (value) => {
  if (value) loadPlan({ scroll: true });
});

onMounted(async () => {
  // Експорт міг лишитись у процесі з минулого відкриття сторінки.
  loadGoogle().then(() => {
    if (['queued', 'running'].includes(google.value?.export?.status)) waitForExport();
  });
  await loadProjects();
  if (projectId.value) {
    await loadPlan({ scroll: true });
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
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#149d8d" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"></line><line x1="8" y1="12" x2="21" y2="12"></line><line x1="8" y1="18" x2="21" y2="18"></line><polyline points="3 6 4 7 6 5"></polyline><polyline points="3 12 4 13 6 11"></polyline><line x1="3" y1="18" x2="6" y2="18"></line></svg>
        </div>
        <div>
          <div class="page-head-title">Плани</div>
          <div class="page-head-subtitle">Задачі проектів і хто над чим працював по днях</div>
        </div>
      </div>
      <div class="page-head-actions">
        <div class="field-pill">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#149d8d" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
          <DatePicker v-model="selectedMonth" view="month" date-format="mm.yy" :manual-input="false" />
        </div>
        <div v-if="auth.isAdmin" class="plan-split">
          <button
            type="button"
            class="plan-btn"
            :disabled="exporting"
            :title="exportHint"
            @click="exportToGoogle"
          >
            <svg v-if="exporting" class="btn-icon is-spinning" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M21 12a9 9 0 1 1-6.219-8.56"></path></svg>
            <svg v-else class="btn-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 15V3"></path><polyline points="7 8 12 3 17 8"></polyline><path d="M4 15v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3"></path></svg>
            {{ exportLabel }}
          </button>
          <button
            v-if="google?.spreadsheet_url"
            type="button"
            class="plan-btn is-caret"
            title="Інші дії з Google Таблицею"
            aria-label="Інші дії з Google Таблицею"
            @click="googleMenu.toggle($event)"
          >
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><polyline points="6 9 12 15 18 9"></polyline></svg>
          </button>
        </div>
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
          <svg class="btn-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
          Створити проект
        </button>
      </template>
      <template v-else>Ви ще не учасник жодного проекту. Коли адміністратор додасть вас, проект зʼявиться тут.</template>
    </div>

    <template v-else>
      <div class="project-tabs">
        <div class="project-tabs-list" role="tablist">
          <button
            v-for="project in projects"
            :key="project.id"
            type="button"
            role="tab"
            class="project-tab"
            :class="{ 'is-active': project.id === projectId, 'is-archived': project.archived }"
            :aria-selected="project.id === projectId"
            @click="selectProject(project.id)"
          >
            {{ project.name }}
            <span class="project-tab-count">{{ project.tasks_count }}</span>
          </button>
        </div>
        <button
          v-if="auth.isAdmin"
          type="button"
          class="project-tab is-new"
          title="Створити проект"
          @click="openNewProject"
        >
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
          Проект
        </button>
      </div>

      <div v-if="loadingPlan && !plan" class="skeleton plans-skeleton"></div>

      <template v-else-if="plan">
        <div class="toolbar">
          <label class="toolbar-field" title="Виконавець">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-label="Виконавець"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
            <select v-model="personFilter">
              <option value="all">Усі</option>
              <option v-for="person in people" :key="person.id" :value="person.id">
                {{ person.name }}{{ person.id === myEmployeeId ? ' (я)' : '' }}
              </option>
            </select>
          </label>
          <label class="toolbar-check">
            <input v-model="hideClosed" type="checkbox">
            Активні задачі
          </label>
          <span class="toolbar-count">{{ visibleTasks.length }} з {{ plan.tasks.length }}</span>

          <span class="toolbar-gap"></span>

          <button v-if="canAddTasks" type="button" class="plan-btn" @click="openNewSection">
            <svg class="btn-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
            Розділ
          </button>
          <button v-if="canAddTasks" type="button" class="plan-btn is-accent" @click="openNewTask(null)">
            <svg class="btn-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
            Задача
          </button>
          <template v-if="canManage">
            <button
              type="button"
              class="plan-btn is-icon"
              title="Налаштування проекту"
              aria-label="Налаштування проекту"
              @click="openProjectSettings"
            >
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
            </button>
          </template>
        </div>

        <div v-if="!plan.members.length && canManage" class="panel empty-panel">
          У проекті ще немає учасників. Додайте їх у «Проект» — лише учасникам можна ставити задачі.
        </div>

        <div v-else-if="!groups.length" class="panel empty-panel">
          {{ plan.tasks.length ? 'Під фільтр не потрапила жодна задача.' : 'Задач поки немає — додайте першу.' }}
        </div>

        <div v-else ref="sheetPanel" class="panel sheet-panel" :class="{ 'is-loading': loadingPlan }">
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
                      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path><polyline points="15 3 21 3 21 9"></polyline><line x1="10" y1="14" x2="21" y2="3"></line></svg>
                    </a>
                    <span class="section-count">{{ group.tasks.length }}</span>
                    <span class="section-actions">
                      <button v-if="canAddTasks" type="button" class="link-btn" @click="openNewTask(group.section?.id ?? null)">+ задача</button>
                      <button
                        v-if="canManage && group.section"
                        type="button"
                        class="link-btn"
                        @click="openSection(group)"
                      >змінити</button>
                    </span>
                  </div>
                </td>
                <td :colspan="days.length" class="section-fill"></td>
              </tr>
              <tr
                v-for="task in group.tasks"
                :key="task.id"
                class="task-row"
                :class="{ 'is-closed': task.status === 'done' || task.status === 'not_relevant', 'is-current': isCurrent(task) }"
              >
                <td class="col-task">
                  <div class="task-cell">
                    <button
                      v-if="canEdit(task) && !['done', 'not_relevant'].includes(task.status) || isCurrent(task)"
                      type="button"
                      class="now-btn"
                      :class="{ 'is-on': isCurrent(task) }"
                      :disabled="!canEdit(task)"
                      :title="isCurrent(task) ? 'Зараз працює над цією задачею. Натисніть, щоб зняти' : 'Працюю над цим зараз'"
                      @click="toggleCurrent(task)"
                    >
                      <span v-if="isCurrent(task)" class="now-dot"></span>
                      <svg v-else width="10" height="10" viewBox="0 0 24 24" fill="currentColor"><polygon points="6 4 20 12 6 20 6 4"></polygon></svg>
                    </button>
                    <span v-else class="now-spacer"></span>
                    <div class="task-text">
                      <button
                        v-if="canEdit(task)"
                        type="button"
                        class="task-title is-editable"
                        :title="task.title"
                        @click="openTask(task)"
                      >{{ task.title }}</button>
                      <span v-else class="task-title" :title="task.title">{{ task.title }}</span>
                      <span v-if="task.note" class="task-note" :title="task.note">
                        <a v-if="noteLink(task.note)" :href="noteLink(task.note)" target="_blank" rel="noopener" class="note-link">посилання</a>
                        {{ noteText(task.note) }}
                      </span>
                    </div>
                  </div>
                </td>
                <td class="col-person" :title="shortName(task.employee_id)">{{ shortName(task.employee_id) }}</td>
                <td class="col-status">
                  <select
                    v-if="canEdit(task)"
                    class="status-select"
                    :class="`is-${task.status}`"
                    :value="task.status"
                    @change="changeStatus(task, $event.target.value)"
                  >
                    <option v-for="(label, key) in plan.statuses" :key="key" :value="key">{{ label }}</option>
                  </select>
                  <span v-else class="status-text" :class="`is-${task.status}`">{{ statusLabel(task.status) }}</span>
                </td>
                <td
                  v-for="d in days"
                  :key="d.iso"
                  class="col-day day-cell"
                  :class="{
                    'is-weekend': d.weekend,
                    'is-today': d.today,
                    'is-worked': Object.hasOwn(task.days, d.iso),
                    'is-now': d.today && isCurrent(task),
                    'is-editable': canEdit(task) && !d.future,
                  }"
                  :title="dayTitle(task, d)"
                  @click="onDayClick($event, task, d)"
                ></td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="legend">
          <span><i class="legend-swatch is-worked"></i>Працював над задачею</span>
          <span><i class="legend-swatch is-now"></i>Працює зараз</span>
          <span v-if="canAddTasks">Клік по дню відмічає його, повторний — коментар або зняти відмітку</span>
        </div>
      </template>
    </template>

    <Popover ref="googleMenu">
      <div class="plan-menu">
        <a
          v-if="google?.spreadsheet_url"
          class="plan-menu-item"
          :href="google.spreadsheet_url"
          target="_blank"
          rel="noopener"
          @click="googleMenu.hide($event)"
        >
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18"></rect><line x1="3" y1="9" x2="21" y2="9"></line><line x1="9" y1="9" x2="9" y2="21"></line></svg>
          <span>
            Відкрити таблицю
            <small v-if="google.spreadsheet_title">{{ google.spreadsheet_title }}</small>
          </span>
        </a>
        <button type="button" class="plan-menu-item" @click="openGoogleDialog">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path></svg>
          <span>Підключити іншу таблицю</span>
        </button>
        <p class="plan-menu-note">{{ lastExportLabel }}</p>
      </div>
    </Popover>

    <Popover ref="dayPopover">
      <form v-if="dayEdit" class="day-editor" @submit.prevent="saveDayComment">
        <div class="day-editor-head">
          <strong>{{ formatDay(dayEdit.iso) }}</strong>
          <span :title="dayEdit.task.title">{{ dayEdit.task.title }}</span>
        </div>
        <textarea
          v-model="dayEdit.comment"
          rows="3"
          maxlength="2000"
          placeholder="Що зроблено (необовʼязково)"
          @keydown.enter.ctrl.prevent="saveDayComment"
        ></textarea>
        <div class="plan-actions">
          <button type="button" class="plan-btn is-danger" :disabled="daySaving" @click="unmarkDay">Зняти відмітку</button>
          <span class="plan-actions-gap"></span>
          <button type="submit" class="plan-btn is-accent" :disabled="daySaving">Зберегти</button>
        </div>
      </form>
    </Popover>

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
      @saved="onTaskSaved"
      @deleted="onTaskDeleted"
    />

    <PlanSectionDialog
      v-if="plan"
      v-model:visible="sectionDialog.visible"
      :project-id="plan.project.id"
      :project-name="plan.project.name"
      :section="sectionDialog.section"
      :tasks-count="sectionDialog.tasksCount"
      :can-delete="canManage"
      @saved="loadPlan()"
      @deleted="loadPlan()"
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

/* --- Вкладки проектів ------------------------------------------------- */

.project-tabs {
  display: flex;
  align-items: stretch;
  gap: 12px;
  margin-top: 16px;
  border-bottom: 1px solid #dfe5ea;
}

.project-tabs-list {
  display: flex;
  gap: 2px;
  flex: 1;
  min-width: 0;
  overflow-x: auto;
  overflow-y: hidden;
}

.project-tab {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 16px;
  border: none;
  border-bottom: 2px solid transparent;
  margin-bottom: -1px;
  background: transparent;
  font: inherit;
  font-size: 13.5px;
  font-weight: 600;
  color: var(--text-dim);
  cursor: pointer;
  white-space: nowrap;
}

.project-tab:hover {
  color: var(--ink);
}

.project-tab.is-active {
  color: var(--accent);
  border-bottom-color: var(--accent);
}

.project-tab.is-archived {
  color: var(--muted-2);
}

/* Створення проекту — там, де проекти, а не серед дій усієї сторінки. */
.project-tab.is-new {
  flex-shrink: 0;
  gap: 6px;
  color: var(--muted);
}

.project-tab.is-new:hover {
  color: var(--accent);
}

.project-tab-count {
  font-size: 11.5px;
  font-weight: 600;
  padding: 1px 6px;
  background: var(--line);
  color: var(--muted);
  font-variant-numeric: tabular-nums;
}

/* --- Панель фільтрів -------------------------------------------------- */

.toolbar {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 10px 14px;
  margin-top: 14px;
  font-size: 13px;
}

.toolbar-field {
  display: flex;
  align-items: center;
  gap: 8px;
}

.toolbar-field svg {
  flex-shrink: 0;
  color: var(--muted-2);
}

.toolbar-field select {
  padding: 7px 8px;
  border: 1px solid #dfe5ea;
  background: var(--surface);
  font: inherit;
  color: var(--ink);
  max-width: 220px;
}

.toolbar-check {
  display: flex;
  align-items: center;
  gap: 6px;
  color: var(--text-dim);
  cursor: pointer;
}

.toolbar-check input {
  accent-color: var(--accent);
}

.toolbar-count {
  color: var(--muted);
  font-variant-numeric: tabular-nums;
}

.toolbar-gap {
  flex: 1;
}

.toolbar a.plan-btn {
  text-decoration: none;
}

/* --- Таблиця плану ---------------------------------------------------- */

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

.plan-sheet {
  border-collapse: separate;
  border-spacing: 0;
  font-size: 12.5px;
}

.plan-sheet th {
  position: sticky;
  top: 0;
  z-index: 2;
  background: var(--surface);
  font-size: 11px;
  font-weight: 600;
  color: var(--muted);
  border-bottom: 1px solid #dfe5ea;
  padding: 6px 4px;
  text-align: left;
  white-space: nowrap;
}

.plan-sheet td {
  border-bottom: 1px solid var(--line);
  padding: 0 4px;
  height: 40px;
  vertical-align: middle;
}

.col-task,
.col-person,
.col-status {
  position: sticky;
  background: var(--surface);
  z-index: 1;
}

.plan-sheet th.col-task,
.plan-sheet th.col-person,
.plan-sheet th.col-status {
  z-index: 3;
}

.col-task {
  left: 0;
  width: 340px;
  min-width: 340px;
  max-width: 340px;
  padding-left: 8px !important;
}

.col-person {
  left: 340px;
  width: 120px;
  min-width: 120px;
  max-width: 120px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  color: var(--text-dim);
}

.col-status {
  left: 460px;
  width: 156px;
  min-width: 156px;
  border-right: 1px solid #dfe5ea;
}

.plan-sheet th.col-day {
  text-align: center;
  padding: 4px 0;
  line-height: 1.15;
}

.day-num {
  display: block;
  font-size: 12px;
  color: var(--text-dim);
  font-variant-numeric: tabular-nums;
}

.day-week {
  display: block;
  font-size: 10px;
  font-weight: 500;
  color: var(--muted-2);
}

.plan-sheet th.is-today .day-num,
.plan-sheet th.is-today .day-week {
  color: var(--accent);
  font-weight: 700;
}

.col-day {
  width: 30px;
  min-width: 30px;
  max-width: 30px;
}

.col-day.is-weekend {
  background: #f6f7f8;
}

.col-day.is-today {
  box-shadow: inset 1px 0 0 #b9e0db, inset -1px 0 0 #b9e0db;
}

/* --- Рядок розділу ---------------------------------------------------- */

.section-row td {
  height: 34px;
  background: #f4f6f7;
  border-bottom: 1px solid #dfe5ea;
}

.col-section {
  position: sticky;
  left: 0;
  z-index: 1;
  padding-left: 12px !important;
}

.section-head {
  display: flex;
  align-items: center;
  gap: 8px;
  width: 604px;
}

.section-name {
  font-size: 12px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--ink);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.section-count {
  font-size: 11.5px;
  color: var(--muted);
  font-variant-numeric: tabular-nums;
}

.section-actions {
  display: flex;
  gap: 10px;
  margin-left: auto;
  padding-right: 10px;
}

.link-btn {
  padding: 0;
  border: none;
  background: none;
  font: inherit;
  font-size: 12px;
  font-weight: 600;
  color: var(--accent);
  cursor: pointer;
  white-space: nowrap;
}

.link-btn:hover {
  text-decoration: underline;
}

.link-btn.is-danger {
  color: var(--muted);
}

.link-btn.is-danger:hover {
  color: #c2402f;
}

/* --- Рядок задачі ----------------------------------------------------- */

.task-row:hover td {
  background-color: #fafbfb;
}

.task-row:hover td.is-weekend {
  background-color: #f1f3f4;
}

.task-cell {
  display: flex;
  align-items: center;
  gap: 8px;
  min-width: 0;
}

.now-btn,
.now-spacer {
  flex-shrink: 0;
  width: 22px;
  height: 22px;
}

.now-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 0;
  border: 1px solid #dfe5ea;
  background: var(--surface);
  color: var(--muted-2);
  cursor: pointer;
  transition: all 0.15s ease;
}

.now-btn:hover:not(:disabled) {
  border-color: var(--accent);
  color: var(--accent);
}

.now-btn.is-on {
  border-color: var(--accent);
  background: #d5f2ee;
}

.now-btn:disabled {
  cursor: default;
}

.now-dot {
  width: 8px;
  height: 8px;
  border-radius: 50% !important;
  background: var(--accent);
  animation: pulseDot 1.4s ease-in-out infinite;
}

.task-text {
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.task-title {
  padding: 0;
  border: none;
  background: none;
  font: inherit;
  font-size: 13px;
  font-weight: 600;
  color: #2f3437;
  text-align: left;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.task-title.is-editable {
  cursor: pointer;
}

.task-title.is-editable:hover {
  color: var(--accent);
}

.task-row.is-closed .task-title {
  color: var(--muted);
  font-weight: 500;
}

.task-note {
  font-size: 11.5px;
  color: var(--muted);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.note-link {
  color: var(--accent);
  font-weight: 600;
  text-decoration: none;
}

.note-link:hover {
  text-decoration: underline;
}

.status-select,
.status-text {
  font-size: 12px;
  font-weight: 600;
}

.status-select {
  width: 100%;
  padding: 4px 2px;
  border: 1px solid transparent;
  background: transparent;
  font-family: inherit;
  cursor: pointer;
}

.status-select:hover,
.status-select:focus {
  border-color: #dfe5ea;
  outline: none;
}

.is-pending { color: var(--muted); }
.is-in_progress { color: var(--accent); }
.is-review { color: #9a6a12; }
.is-paused { color: #8a8f98; }
.is-recurring { color: #0e7d70; }
.is-done { color: #6b6b6b; }
.is-not_relevant { color: var(--muted-2); }

/* --- Клітинка дня ----------------------------------------------------- */

.day-cell {
  padding: 0 !important;
  position: relative;
}

.day-cell.is-editable {
  cursor: pointer;
}

.day-cell.is-editable:not(.is-worked):hover::after {
  content: '';
  position: absolute;
  inset: 9px 6px;
  border: 1px dashed #9fd3cc;
}

.day-cell.is-worked::after {
  content: '';
  position: absolute;
  inset: 9px 4px;
  background: #8fd0c7;
}

.day-cell.is-now::after {
  background: var(--accent);
  box-shadow: 0 0 0 2px #d5f2ee, 0 0 0 3px var(--accent);
}

/* --- Легенда ---------------------------------------------------------- */

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

/* --- Редактор дня ----------------------------------------------------- */

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

/* На телефоні три липкі колонки зʼїли б увесь екран: липкою лишається
   лише вузька колонка задачі. */
@media (max-width: 760px) {
  .col-task {
    width: 200px;
    min-width: 200px;
    max-width: 200px;
  }

  .col-person,
  .col-status {
    position: static;
  }

  .section-head {
    width: 200px;
  }

  .section-actions {
    display: none;
  }

  .sheet-panel {
    max-height: none;
  }
}
</style>
