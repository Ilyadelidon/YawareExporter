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
