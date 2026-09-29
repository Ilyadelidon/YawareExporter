import { ref } from 'vue';
import client from '../api/client';
import { RELEASING_STATUSES } from '../constants/plans';
import { toMonthParam } from '../utils/dates';

const LAST_PROJECT_KEY = 'plans.lastProject';

/**
 * Проекти «Планів» і план вибраного проекту за місяць, плюс правки задачі
 * просто з таблиці (статус, «працюю зараз»).
 */
export function usePlan() {
  const projects = ref([]);
  const plan = ref(null);
  const loadingProjects = ref(true);
  const loadingPlan = ref(false);
  const error = ref('');

  // Номер останнього запиту: план попереднього проекту чи місяця, що прийшов
  // пізніше, не має підмінити поточний.
  let requestId = 0;
  let lastParams = null;

  async function loadProjects() {
    loadingProjects.value = true;
    try {
      const { data } = await client.get('/plans/projects');
      projects.value = data.data;
    } catch (e) {
      error.value = e.response?.data?.message || 'Не вдалося завантажити проекти.';
    } finally {
      loadingProjects.value = false;
    }
  }

  /**
   * @returns {Promise<boolean>} чи ця відповідь потрапила на сторінку
   */
  async function loadPlan(projectId, month) {
    const current = ++requestId;
    lastParams = { projectId, month };

    if (!projectId) {
      plan.value = null;
      loadingPlan.value = false;
      return false;
    }

    loadingPlan.value = true;
    error.value = '';
    try {
      const { data } = await client.get(`/plans/projects/${projectId}`, {
        params: { month: toMonthParam(month) },
      });
      if (current !== requestId) return false;
      plan.value = data;
      rememberProject(projectId);
      return true;
    } catch (e) {
      if (current !== requestId) return false;
      plan.value = null;
      error.value = e.response?.data?.message || 'Не вдалося завантажити план.';
      return false;
    } finally {
      if (current === requestId) loadingPlan.value = false;
    }
  }

  function reload() {
    return lastParams ? loadPlan(lastParams.projectId, lastParams.month) : Promise.resolve(false);
  }

  function removeTask(id) {
    plan.value.tasks = plan.value.tasks.filter((task) => task.id !== id);
  }

  async function changeStatus(task, status) {
    const previous = task.status;
    task.status = status;
    try {
      await client.patch(`/plans/tasks/${task.id}`, { status });
      // Закрита задача могла перестати бути поточною — підтягуємо стан.
      if (RELEASING_STATUSES.includes(status)) await reload();
    } catch (e) {
      task.status = previous;
      error.value = e.response?.data?.message || 'Не вдалося змінити статус.';
    }
  }

  /**
   * @returns {Promise<boolean>} чи вдалося
   */
  async function toggleCurrent(task, isCurrent) {
    try {
      if (isCurrent) {
        await client.delete(`/plans/tasks/${task.id}/current`);
      } else {
        await client.put(`/plans/tasks/${task.id}/current`);
      }
      await reload();
      return true;
    } catch (e) {
      error.value = e.response?.data?.message || 'Не вдалося змінити поточну задачу.';
      return false;
    }
  }

  return {
    projects,
    plan,
    loadingProjects,
    loadingPlan,
    error,
    loadProjects,
    loadPlan,
    reload,
    removeTask,
    changeStatus,
    toggleCurrent,
  };
}

// Останній відкритий проект — щоб наступного разу відкрити саме його.
function rememberProject(projectId) {
  try {
    localStorage.setItem(LAST_PROJECT_KEY, String(projectId));
  } catch {
    // Не критично: наступного разу відкриється перший проект.
  }
}

export function rememberedProjectId() {
  try {
    return Number(localStorage.getItem(LAST_PROJECT_KEY)) || null;
  } catch {
    // Сховище може бути недоступне — тоді просто відкриваємо перший проект.
    return null;
  }
}

/**
 * Проект, який відкрити без явного вибору: запамʼятований, інакше перший
 * неархівний, інакше будь-який.
 */
export function defaultProject(projects, rememberedId) {
  return projects.find((project) => project.id === rememberedId)
    || projects.find((project) => !project.archived)
    || projects[0]
    || null;
}
