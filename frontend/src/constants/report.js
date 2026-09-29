// Статуси звіту — ті самі, що константи STATUS_* моделі Report на бекенді.
export const REPORT_STATUS = {
  pending: 'pending',
  processing: 'processing',
  completed: 'completed',
  failed: 'failed',
  // Звіт свідомо не сформовано: у дні лишився час поза тасками.
  blocked: 'blocked',
};

// Звіт у черзі або генерується — його статус треба опитувати.
export const ACTIVE_REPORT_STATUSES = [REPORT_STATUS.pending, REPORT_STATUS.processing];

export function isReportActive(report) {
  return ACTIVE_REPORT_STATUSES.includes(report?.status);
}

// Службові ключі summary, які додає бекенд (Report::SUMMARY_*).
export const SUMMARY_KEYS = {
  result: 'Результат',
  googleSheet: 'Google Таблиця',
  warnings: 'Попередження',
};

// Ключі часу з підсумку воркера — показуються окремою смугою, а не рядками.
export const TIME_KEYS = {
  productive: 'Продуктивно',
  neutral: 'Невідомо/нейтрально',
  unproductive: 'Непродуктивний час',
  total: 'Загальний час',
};
