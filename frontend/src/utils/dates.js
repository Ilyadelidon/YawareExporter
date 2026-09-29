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

const WEEKDAYS = ['Нд', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб'];

/**
 * Дні місяця для таблиць (Плани, Табель): кожен день місяця з днем тижня і позначками
 * «вихідний», «сьогодні», «ще не настав».
 *
 * @param {string} month Y-m
 * @param {number} daysInMonth
 * @param {string} today Y-m-d
 */
export function monthDays(month, daysInMonth, today) {
  const [year, monthNumber] = month.split('-').map(Number);
  return Array.from({ length: daysInMonth }, (_, i) => {
    const iso = `${month}-${pad(i + 1)}`;
    const weekday = new Date(year, monthNumber - 1, i + 1).getDay();
    return {
      day: i + 1,
      iso,
      weekday: WEEKDAYS[weekday],
      weekend: weekday === 0 || weekday === 6,
      today: iso === today,
      future: iso > today,
    };
  });
}
