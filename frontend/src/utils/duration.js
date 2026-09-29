// Формат тривалості для таблиць: секунди → «Г:ХХ» або «Г:ХХ:СС», 0/null → «—».

export function formatDuration(seconds) {
  if (!seconds) {
    return '—';
  }
  const hours = Math.floor(seconds / 3600);
  const minutes = Math.floor((seconds % 3600) / 60);
  return `${hours}:${String(minutes).padStart(2, '0')}`;
}

export function formatDurationWithSeconds(seconds) {
  if (!seconds) {
    return '—';
  }
  const secs = seconds % 60;
  if (!secs) {
    return formatDuration(seconds);
  }
  const hours = Math.floor(seconds / 3600);
  const minutes = Math.floor((seconds % 3600) / 60);
  return `${hours}:${String(minutes).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
}

// Зворотне до формату: «Г:ХХ:СС» (як у підсумку воркера) → секунди; null — не час.
export function parseClock(value) {
  if (typeof value !== 'string') return null;
  const match = value.trim().match(/^(\d+):(\d{2}):(\d{2})$/);
  if (!match) return null;
  return Number(match[1]) * 3600 + Number(match[2]) * 60 + Number(match[3]);
}
