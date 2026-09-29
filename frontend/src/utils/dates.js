// Дати без часу у форматі API (Y-m-d) — за локальним календарем браузера.

const pad = (n) => String(n).padStart(2, '0');

export function toIsoDate(date) {
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

export function shiftIsoDate(iso, days) {
  const date = new Date(`${iso}T00:00:00`);
  date.setDate(date.getDate() + days);
  return toIsoDate(date);
}

const longDateFormat = new Intl.DateTimeFormat('uk-UA', { day: 'numeric', month: 'long', year: 'numeric' });

// «29 вересня 2026» — без суфікса «р.», який додає сам Intl.
export function formatLongDate(date) {
  return longDateFormat.formatToParts(date)
    .filter((part) => ['day', 'month', 'year'].includes(part.type))
    .map((part) => part.value)
    .join(' ');
}

// «2026-09-29» → «29.09.2026».
export function formatDottedDate(iso) {
  const [year, month, day] = iso.split('-');
  return `${day}.${month}.${year}`;
}

// Місяць у форматі API (Y-m).
export function toMonthParam(date) {
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}`;
}

// «2026-09» → перше число місяця; інше — null.
export function parseMonthParam(value) {
  return typeof value === 'string' && /^\d{4}-\d{2}$/.test(value) ? new Date(`${value}-01T00:00:00`) : null;
}
