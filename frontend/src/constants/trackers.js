// Назви таск-трекерів для інтерфейсу; ключ — task_provider користувача.
export const TRACKER_LABELS = {
  trello: 'Trello',
  bitrix: 'Бітрікс24',
};

export function trackerLabel(provider) {
  return TRACKER_LABELS[provider] || TRACKER_LABELS.trello;
}

// Трекер, з яким звʼязана задача плану, — підписи для посилання й стану.
export const PLAN_TRACKERS = {
  bitrix: {
    label: TRACKER_LABELS.bitrix,
    linkHint: 'Задача в Бітрікс24 з тегом «План»',
    unlinked: 'поза Бітріксом',
    unlinkedHint: 'У Бітріксі задачу видалили або зняли з неї тег «План» — у плані вона лишилась, але більше не синхронізується',
  },
  trello: {
    label: TRACKER_LABELS.trello,
    linkHint: 'Картка Trello з міткою «План»',
    unlinked: 'поза Trello',
    unlinkedHint: 'У Trello картку архівували, видалили або зняли з неї мітку «План» — у плані вона лишилась, але більше не синхронізується',
  },
};
