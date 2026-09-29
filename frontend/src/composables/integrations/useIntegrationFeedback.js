import { inject, provide, ref } from 'vue';
import { errorMessage } from '../../utils/errors';

const KEY = Symbol('integrationFeedback');

/**
 * Спільний стан рядків на сторінці інтеграцій: чиї налаштування розгорнуті
 * і повідомлення про результат останньої дії. Повідомлення одне на сторінку
 * й показується під рядком тієї інтеграції, якої стосується.
 * Сторінка створює стан через provideIntegrationFeedback(), рядки беруть
 * його через useIntegrationFeedback().
 */
export function provideIntegrationFeedback() {
  // null | ключ інтеграції ('trello', 'bitrix', 'sheets', 'telegram')
  const managing = ref(null);
  // { key, tone: 'ok' | 'error', text } | null
  const notice = ref(null);

  function say(key, tone, text) {
    notice.value = { key, tone, text };
  }

  function noticeFor(key) {
    return notice.value?.key === key ? notice.value : null;
  }

  function clear() {
    notice.value = null;
  }

  function isManaging(key) {
    return managing.value === key;
  }

  function toggleManage(key) {
    managing.value = managing.value === key ? null : key;
  }

  function openManage(key) {
    managing.value = key;
  }

  function closeManage() {
    managing.value = null;
  }

  /**
   * Дія з прапорцем «виконується» і повідомленням під рядком: action
   * повертає текст успіху (або нічого), помилка API стає текстом помилки.
   * Повертає true, якщо дія вдалась.
   */
  async function run(key, busy, fallback, action) {
    busy.value = true;
    clear();
    try {
      const text = await action();
      if (text) say(key, 'ok', text);
      return true;
    } catch (error) {
      say(key, 'error', errorMessage(error, fallback));
      return false;
    } finally {
      busy.value = false;
    }
  }

  const feedback = { say, noticeFor, clear, isManaging, toggleManage, openManage, closeManage, run };
  provide(KEY, feedback);
  return feedback;
}

export function useIntegrationFeedback() {
  return inject(KEY);
}
