import { ref } from 'vue';
import client from '../api/client';

const IN_PROGRESS = ['queued', 'running'];

function sleep(ms) {
  return new Promise((resolve) => { setTimeout(resolve, ms); });
}

/**
 * Спільна Google Таблиця планів (лише для адміністратора) і вивантаження в
 * неї. Експорт іде у фоні: велику таблицю Google «прокидає» хвилинами, тож
 * опитуємо стан, доки фонова задача не завершиться.
 *
 * @param {{ onError: (message: string) => void, pollMs?: number, stuckAfterMs?: number }} options
 */
export function usePlanExport({ onError, pollMs = 3000, stuckAfterMs = 60000 }) {
  const google = ref(null);
  const exporting = ref(false);
  const exportDone = ref(false);
  // Що прогін підтягнув із таблиці (і чого не зміг) — текстом із бекенду.
  const exportSummary = ref('');
  // Задача стоїть у черзі надто довго — найчастіше не запущений обробник черги.
  const exportStuck = ref(false);

  async function loadGoogle() {
    try {
      const { data } = await client.get('/plans/google');
      google.value = data.data;
    } catch {
      google.value = null;
    }
  }

  // Якщо задача хвилину не зрушила з черги, кажемо про це прямо — інакше
  // сторінка нескінченно показує «Вивантаження…» при непрацюючому обробнику.
  async function waitForExport() {
    exporting.value = true;
    exportStuck.value = false;
    const queuedSince = Date.now();
    try {
      while (IN_PROGRESS.includes(google.value?.export?.status)) {
        await sleep(pollMs);
        await loadGoogle();
        exportStuck.value = google.value?.export?.status === 'queued' && Date.now() - queuedSince > stuckAfterMs;
      }
      const result = google.value?.export;
      if (result?.status === 'done') {
        exportDone.value = true;
        exportSummary.value = result.message || '';
      } else if (result?.status === 'failed') {
        onError(`Не вдалося вивантажити плани в Google Таблицю: ${result.message || 'невідома помилка'}`);
      }
    } finally {
      exporting.value = false;
      exportStuck.value = false;
    }
  }

  async function startExport() {
    exportDone.value = false;
    exportSummary.value = '';
    try {
      const { data } = await client.post('/plans/export');
      google.value = { ...google.value, export: data.data.export };
    } catch (e) {
      onError(e.response?.data?.message || 'Не вдалося вивантажити плани в Google Таблицю.');
      return;
    }
    await waitForExport();
  }

  // Експорт міг лишитись у процесі з минулого відкриття сторінки.
  async function resume() {
    await loadGoogle();
    if (IN_PROGRESS.includes(google.value?.export?.status)) await waitForExport();
  }

  return { google, exporting, exportDone, exportSummary, exportStuck, loadGoogle, startExport, resume };
}
