import { TRACKER_LABELS } from '../constants/trackers';

// Вердикти пам'яті AI — підписи й клас кольору для випадайки.
export const VERDICT_OPTIONS = [
  { value: 'work_related', label: 'Робоче', className: 'is-work' },
  { value: 'personal', label: 'Особисте', className: 'is-personal' },
  { value: 'unknown', label: 'Невідомо', className: 'is-unknown' },
];

export function verdictClass(verdict) {
  return VERDICT_OPTIONS.find((option) => option.value === verdict)?.className || '';
}

/**
 * Рядки вкладки «Інтеграції» з того, що бекенд віддав у списку працівників.
 * Хто жодного разу не входив, той нічого не підключав — рядків немає.
 */
export function integrationRows(info) {
  if (!info?.has_account) {
    return [];
  }

  const trackerNote = (provider) => (info.task_provider === provider ? 'активний трекер' : null);

  return [
    {
      key: 'trello',
      label: TRACKER_LABELS.trello,
      connected: info.trello.connected,
      detail: info.trello.username ? `@${info.trello.username}` : null,
      note: info.trello.connected && !info.trello.board_url ? 'дошку не обрано' : trackerNote('trello'),
      url: info.trello.board_url,
      linkLabel: 'Дошка',
    },
    {
      key: 'bitrix',
      label: TRACKER_LABELS.bitrix,
      connected: info.bitrix.connected,
      detail: info.bitrix.username,
      note: trackerNote('bitrix'),
    },
    {
      key: 'google',
      label: 'Google Таблиця',
      connected: info.google.connected,
      url: info.google.url,
      linkLabel: 'Відкрити',
    },
    {
      key: 'telegram',
      label: 'Telegram-бот',
      connected: info.telegram.connected,
    },
  ];
}
