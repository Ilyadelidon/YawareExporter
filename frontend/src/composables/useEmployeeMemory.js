import { ref } from 'vue';
import client from '../api/client';
import { errorMessage } from '../utils/errors';

/**
 * Пам'ять AI по одному працівнику. Будь-яка правка робить рядок
 * «підтвердженим керівником» — далі модель його не перезаписує.
 */
export function useEmployeeMemory(employeeId) {
  const rows = ref([]);
  const loading = ref(true);
  const error = ref('');
  const savingId = ref(null);
  const addingFact = ref(false);
  // Скільки днів живе вердикт AI до переперевірки — приходить з бекенду.
  const recheckDays = ref(null);

  const url = (id = '') => `/employees/${employeeId}/memory${id ? `/${id}` : ''}`;

  async function load() {
    loading.value = true;
    try {
      const { data } = await client.get(url());
      rows.value = data.data;
      recheckDays.value = data.recheck_days;
      error.value = '';
    } catch (e) {
      error.value = errorMessage(e, 'Не вдалося завантажити пам\'ять.');
    } finally {
      loading.value = false;
    }
  }

  async function save(row, changes = {}) {
    savingId.value = row.id;
    error.value = '';
    try {
      const { data } = await client.patch(url(row.id), { verdict: row.verdict, note: row.note, ...changes });
      Object.assign(row, data.data);
    } catch (e) {
      // Вердикт у випадайці вже змінився локально — повертаємо збережений.
      await load();
      error.value = errorMessage(e, 'Не вдалося зберегти.');
    } finally {
      savingId.value = null;
    }
  }

  async function remove(row) {
    savingId.value = row.id;
    error.value = '';
    try {
      await client.delete(url(row.id));
      rows.value = rows.value.filter((item) => item.id !== row.id);
    } catch (e) {
      error.value = errorMessage(e, 'Не вдалося видалити.');
    } finally {
      savingId.value = null;
    }
  }

  async function addFact({ name, note }) {
    addingFact.value = true;
    error.value = '';
    try {
      await client.post(url(), { kind: 'fact', name, note });
      await load();
      return true;
    } catch (e) {
      error.value = errorMessage(e, 'Не вдалося додати факт.');
      return false;
    } finally {
      addingFact.value = false;
    }
  }

  return { rows, loading, error, savingId, addingFact, recheckDays, load, save, remove, addFact };
}
