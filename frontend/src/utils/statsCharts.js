// Підготовка даних для графіків Статистики: ряди по днях і шкала осі.
import { shiftIsoDate } from './dates';

// Усі календарні дні періоду (Y-m-d) включно з обома кінцями.
export function periodDates(from, to) {
  const dates = [];
  for (let date = from; date <= to; date = shiftIsoDate(date, 1)) {
    dates.push(date);
  }
  return dates;
}

/**
 * Час по днях періоду: для адміністратора без фільтра рядки кількох працівників
 * за одну дату сумуються. Дні без даних (вихідні, пропуски) — нулі, щоб на графіку
 * було видно ритм тижня.
 *
 * @param {Array} stats рядки daily_stats
 * @returns {Array<{date: string, productive: number, neutral: number, unproductive: number}>}
 */
export function dailySeries(stats, from, to) {
  const byDate = new Map();
  for (const stat of stats) {
    const day = byDate.get(stat.date) ?? { productive: 0, neutral: 0, unproductive: 0 };
    day.productive += stat.productive_seconds || 0;
    day.neutral += stat.neutral_seconds || 0;
    day.unproductive += stat.unproductive_seconds || 0;
    byDate.set(stat.date, day);
  }
  return periodDates(from, to).map((date) => ({
    date,
    ...(byDate.get(date) ?? { productive: 0, neutral: 0, unproductive: 0 }),
  }));
}

/**
 * «Круглі» поділки осі від нуля: крок 1/2/5 × 10ⁿ, приблизно `count` поділок.
 * Повертає значення поділок; остання ≥ max.
 */
export function niceTicks(max, count = 4) {
  if (!(max > 0)) return [0, 1];
  const raw = max / count;
  const magnitude = 10 ** Math.floor(Math.log10(raw));
  const step = [1, 2, 5, 10].map((m) => m * magnitude).find((s) => s >= raw);
  const ticks = [];
  for (let value = 0; value < max + step; value += step) {
    ticks.push(Number(value.toFixed(6)));
    if (value >= max) break;
  }
  return ticks;
}

// Кожна k-та мітка осі X, щоб підписи не налазили один на одного.
export function labelEvery(total, width, labelWidth = 44) {
  const fit = Math.max(1, Math.floor(width / labelWidth));
  return Math.max(1, Math.ceil(total / fit));
}
