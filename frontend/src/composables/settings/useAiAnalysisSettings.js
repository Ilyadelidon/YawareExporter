import { computed, ref } from 'vue';
import client from '../../api/client';
import { errorMessage } from '../../utils/errors';
import { useSettingsRow } from './useSettingsRow';

/**
 * Автоматичний AI-розбір дня: одне налаштування на всю команду. Вимкнений —
 * розбір не запускається після звітів, але його можна запустити вручну зі звіту.
 */
export function useAiAnalysisSettings() {
  const { notice, say, run } = useSettingsRow();

  const loading = ref(true);
  const enabled = ref(true);
  const configured = ref(true);
  const saving = ref(false);

  const status = computed(() => {
    if (!configured.value) return { tone: 'error', label: 'Немає ключа' };
    return enabled.value
      ? { tone: 'ok', label: 'Увімкнено' }
      : { tone: 'off', label: 'Вимкнено' };
  });

  const meta = computed(() => {
    if (!configured.value) return 'Ключ AI-провайдера не задано на сервері — розбір не запускається';
    return enabled.value
      ? 'Запускається після кожного готового звіту'
      : 'Лише вручну з панелі звіту';
  });

  function apply(data) {
    enabled.value = data.auto_analysis;
    configured.value = data.configured;
  }

  async function load() {
    try {
      const { data } = await client.get('/analysis/settings');
      apply(data);
    } catch (error) {
      say('error', errorMessage(error, 'Не вдалося отримати налаштування AI-розбору.'));
    } finally {
      loading.value = false;
    }
  }

  function toggle() {
    if (saving.value) return;
    return run(saving, 'Не вдалося зберегти налаштування AI-розбору.', async () => {
      const { data } = await client.put('/analysis/settings', { auto_analysis: !enabled.value });
      apply(data);
      return data.message;
    });
  }

  return { loading, notice, enabled, configured, saving, status, meta, load, toggle };
}
