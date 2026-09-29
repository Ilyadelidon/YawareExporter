import { ref, toRaw } from 'vue';
import client from '../api/client';

/**
 * Відмітки «працював над задачею цього дня» з коментарем. Стан відмітки
 * живе просто в task.days, тож таблиця оновлюється без перезавантаження.
 *
 * @param {{ onError: (message: string) => void, onChange?: () => void }} options
 *   onChange — після відмітки чи зняття (підказки в меню залежать від них)
 */
export function usePlanDays({ onError, onChange = () => {} }) {
  // Відкритий редактор дня: { task, iso, comment, marked }.
  const edit = ref(null);
  const saving = ref(false);

  function isMarked(task, iso) {
    return Object.hasOwn(task.days, iso);
  }

  function begin(task, iso) {
    const marked = isMarked(task, iso);
    edit.value = { task, iso, comment: marked ? task.days[iso] : '', marked };
  }

  /**
   * Відмічає день одразу, без коментаря; невдача відкочує відмітку.
   *
   * @returns {Promise<boolean>}
   */
  async function mark(task, iso) {
    task.days = { ...task.days, [iso]: '' };
    try {
      await client.put(`/plans/tasks/${task.id}/days/${iso}`);
      // edit тримає реактивну обгортку задачі — порівнюємо самі обʼєкти.
      if (edit.value && toRaw(edit.value.task) === toRaw(task) && edit.value.iso === iso) edit.value.marked = true;
      onChange();
      return true;
    } catch (e) {
      const { [iso]: _, ...rest } = task.days;
      task.days = rest;
      onError(e.response?.data?.message || 'Не вдалося відмітити день.');
      return false;
    }
  }

  /** @returns {Promise<boolean>} */
  async function saveComment() {
    const current = edit.value;
    saving.value = true;
    try {
      const { data } = await client.put(`/plans/tasks/${current.task.id}/days/${current.iso}`, { comment: current.comment });
      current.task.days = { ...current.task.days, [current.iso]: data.data.comment };
      current.task.last_worked_on = current.task.last_worked_on > current.iso ? current.task.last_worked_on : current.iso;
      return true;
    } catch (e) {
      onError(e.response?.data?.message || 'Не вдалося зберегти коментар.');
      return false;
    } finally {
      saving.value = false;
    }
  }

  /** @returns {Promise<boolean>} */
  async function unmark() {
    const current = edit.value;
    saving.value = true;
    try {
      await client.delete(`/plans/tasks/${current.task.id}/days/${current.iso}`);
      const { [current.iso]: _, ...rest } = current.task.days;
      current.task.days = rest;
      onChange();
      return true;
    } catch (e) {
      onError(e.response?.data?.message || 'Не вдалося зняти відмітку.');
      return false;
    } finally {
      saving.value = false;
    }
  }

  return { edit, saving, isMarked, begin, mark, saveComment, unmark };
}
