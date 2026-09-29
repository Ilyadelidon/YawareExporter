// Чиста логіка сторінки «Плани»: дні місяця, фільтр і групування задач.
import { isClosedStatus } from '../constants/plans';

const WEEKDAYS = ['Нд', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб'];
const URL_PATTERN = /https?:\/\/\S+/;

/**
 * Колонки таймлайну: кожен день місяця з днем тижня і позначками
 * «вихідний», «сьогодні», «ще не настав».
 *
 * @param {string} month Y-m
 * @param {number} daysInMonth
 * @param {string} today Y-m-d
 */
export function monthDays(month, daysInMonth, today) {
  const [year, monthNumber] = month.split('-').map(Number);
  return Array.from({ length: daysInMonth }, (_, i) => {
    const iso = `${month}-${String(i + 1).padStart(2, '0')}`;
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

/**
 * Задачі під фільтр виконавця й «Активні задачі». Закриту задачу лишаємо,
 * якщо над нею працювали цього місяця — інакше таймлайн місяця втратив би
 * частину відміток.
 *
 * @param {Array} tasks
 * @param {{ person: 'all'|number, hideClosed: boolean }} filter
 */
export function filterTasks(tasks, { person, hideClosed }) {
  return tasks.filter((task) => {
    if (person !== 'all' && task.employee_id !== person) return false;
    if (hideClosed && isClosedStatus(task.status) && !Object.keys(task.days).length) return false;
    return true;
  });
}

/**
 * Задачі по розділах: спершу без розділу, далі розділи за порядком.
 * Порожній розділ показуємо лише з showEmpty — щоб у нього можна було додати
 * першу задачу, а не щоб засмічував відфільтрований вигляд.
 *
 * @param {Array} tasks
 * @param {Array} sections
 * @param {boolean} showEmpty
 */
export function groupTasks(tasks, sections, showEmpty) {
  const bySection = new Map();
  for (const task of tasks) {
    const key = task.section_id ?? 0;
    if (!bySection.has(key)) bySection.set(key, []);
    bySection.get(key).push(task);
  }

  const result = [];
  if (bySection.has(0)) {
    result.push({ key: 'none', section: null, tasks: bySection.get(0) });
  }
  for (const section of sections) {
    const sectionTasks = bySection.get(section.id) || [];
    if (sectionTasks.length || showEmpty) {
      result.push({ key: section.id, section, tasks: sectionTasks });
    }
  }
  return result;
}

// Перше посилання з примітки — його показуємо окремою лінкою.
export function noteLink(note) {
  return note?.match(URL_PATTERN)?.[0] || null;
}

// Примітка без посилання, в один рядок.
export function noteText(note) {
  return (note || '').replace(URL_PATTERN, '').replace(/\s+/g, ' ').trim();
}
