// Швидкі періоди Статистики. Тиждень — з понеділка; поточні тиждень і місяць — по сьогодні.
import { toIsoDate } from './dates';

export function periodPresets(today) {
  const y = today.getFullYear();
  const m = today.getMonth();
  const monday = new Date(y, m, today.getDate() - ((today.getDay() + 6) % 7));
  const lastMonday = new Date(monday.getFullYear(), monday.getMonth(), monday.getDate() - 7);
  const lastSunday = new Date(monday.getFullYear(), monday.getMonth(), monday.getDate() - 1);
  return [
    { value: 'this_week', label: 'Цей тиждень', from: monday, to: today },
    { value: 'last_week', label: 'Минулий тиждень', from: lastMonday, to: lastSunday },
    { value: 'this_month', label: 'Цей місяць', from: new Date(y, m, 1), to: today },
    { value: 'last_month', label: 'Минулий місяць', from: new Date(y, m - 1, 1), to: new Date(y, m, 0) },
  ];
}

// Пресет, з яким точно збігаються дати, інакше null — ручний вибір дат його знімає.
export function matchPreset(presets, from, to) {
  if (!from || !to) return null;
  const fromIso = toIsoDate(from);
  const toIso = toIsoDate(to);
  return presets.find((p) => toIsoDate(p.from) === fromIso && toIsoDate(p.to) === toIso)?.value ?? null;
}
