import { ref } from 'vue';
import { errorMessage } from '../../utils/errors';

/**
 * Спільне для рядка в «Налаштуваннях»: розгорнуті налаштування і повідомлення
 * про результат останньої дії, яке показується під рядком.
 */
export function useSettingsRow() {
  const open = ref(false);
  // { tone: 'ok' | 'error', text } | null
  const notice = ref(null);

  function say(tone, text) {
    notice.value = { tone, text };
  }

  /**
   * Дія з прапорцем «виконується»: action повертає текст успіху (або нічого),
   * помилка API стає текстом помилки. Повертає true, якщо дія вдалась.
   */
  async function run(busy, fallback, action) {
    busy.value = true;
    notice.value = null;
    try {
      const text = await action();
      if (text) say('ok', text);
      return true;
    } catch (error) {
      say('error', errorMessage(error, fallback));
      return false;
    } finally {
      busy.value = false;
    }
  }

  return { open, notice, say, run };
}
