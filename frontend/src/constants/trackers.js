// Назви таск-трекерів для інтерфейсу; ключ — task_provider користувача.
export const TRACKER_LABELS = {
  trello: 'Trello',
  bitrix: 'Бітрікс24',
};

export function trackerLabel(provider) {
  return TRACKER_LABELS[provider] || TRACKER_LABELS.trello;
}
