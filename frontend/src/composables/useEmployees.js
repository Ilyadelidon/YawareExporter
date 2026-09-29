import { ref } from 'vue';
import client from '../api/client';
import { errorMessage } from '../utils/errors';

/**
 * Список працівників для адміністратора: посада правиться прямо в таблиці,
 * звільнення й поновлення — окремими діями.
 */
export function useEmployees() {
  const employees = ref([]);
  const loading = ref(true);
  const error = ref('');
  // Працівник, дія над яким ще триває: його рядок блокується.
  const savingId = ref(null);

  async function load() {
    loading.value = true;
    error.value = '';
    try {
      const { data } = await client.get('/employees');
      employees.value = data.data;
    } catch (e) {
      error.value = errorMessage(e, 'Не вдалося завантажити працівників.');
    } finally {
      loading.value = false;
    }
  }

  // Переносить у рядок лише поля, які змінює дія, — інтеграції з відповіді
  // на правку не приходять і мають лишитися.
  async function change(employee, request, fields, fallback) {
    savingId.value = employee.id;
    error.value = '';
    try {
      const { data } = await request();
      fields.forEach((field) => { employee[field] = data.data[field]; });
      return true;
    } catch (e) {
      error.value = errorMessage(e, fallback);
      return false;
    } finally {
      savingId.value = null;
    }
  }

  async function savePosition(employee, position) {
    if (position === (employee.position || null)) {
      return true;
    }

    return change(
      employee,
      () => client.patch(`/employees/${employee.id}`, { position }),
      ['position'],
      'Не вдалося зберегти посаду.',
    );
  }

  function dismiss(employee) {
    return change(
      employee,
      () => client.post(`/employees/${employee.id}/dismissal`),
      ['dismissed_at', 'active'],
      'Не вдалося змінити статус працівника.',
    );
  }

  function reinstate(employee) {
    return change(
      employee,
      () => client.delete(`/employees/${employee.id}/dismissal`),
      ['dismissed_at', 'active'],
      'Не вдалося змінити статус працівника.',
    );
  }

  return { employees, loading, error, savingId, load, savePosition, dismiss, reinstate };
}
