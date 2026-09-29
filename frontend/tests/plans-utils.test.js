import { describe, expect, it } from 'vitest';
import { filterTasks, groupTasks, monthDays, noteLink, noteText } from '../src/utils/plans';
import { formatDottedDate, parseMonthParam, toMonthParam } from '../src/utils/dates';

const task = (id, overrides = {}) => ({
  id, employee_id: 1, section_id: null, status: 'pending', days: {}, ...overrides,
});

describe('monthDays', () => {
  const days = monthDays('2026-09', 30, '2026-09-17');

  it('builds every day of the month with weekday labels', () => {
    expect(days).toHaveLength(30);
    expect(days[0]).toMatchObject({ day: 1, iso: '2026-09-01', weekday: 'Вт', weekend: false });
    expect(days[29].iso).toBe('2026-09-30');
  });

  it('marks weekends, today and future days', () => {
    expect(days[4]).toMatchObject({ iso: '2026-09-05', weekend: true });
    expect(days[5]).toMatchObject({ iso: '2026-09-06', weekend: true });
    expect(days[16]).toMatchObject({ iso: '2026-09-17', today: true, future: false });
    expect(days[17]).toMatchObject({ today: false, future: true });
    expect(days[15].future).toBe(false);
  });
});

describe('filterTasks', () => {
  const tasks = [
    task(1),
    task(2, { employee_id: 2 }),
    task(3, { status: 'done' }),
    task(4, { status: 'not_relevant', days: { '2026-09-02': '' } }),
  ];

  it('keeps everything without filters', () => {
    expect(filterTasks(tasks, { person: 'all', hideClosed: false })).toHaveLength(4);
  });

  it('filters by person', () => {
    expect(filterTasks(tasks, { person: 2, hideClosed: false }).map((t) => t.id)).toEqual([2]);
  });

  it('hides closed tasks unless worked on this month', () => {
    expect(filterTasks(tasks, { person: 'all', hideClosed: true }).map((t) => t.id)).toEqual([1, 2, 4]);
  });
});

describe('groupTasks', () => {
  const sections = [{ id: 10, name: 'Кошик' }, { id: 20, name: 'Оплата' }];
  const tasks = [task(1, { section_id: 20 }), task(2), task(3, { section_id: 20 })];

  it('puts tasks without section first, then sections in order', () => {
    const groups = groupTasks(tasks, sections, true);

    expect(groups.map((g) => g.key)).toEqual(['none', 10, 20]);
    expect(groups[0].tasks.map((t) => t.id)).toEqual([2]);
    expect(groups[1].tasks).toEqual([]);
    expect(groups[2].tasks.map((t) => t.id)).toEqual([1, 3]);
  });

  it('drops empty sections unless asked to show them', () => {
    expect(groupTasks(tasks, sections, false).map((g) => g.key)).toEqual(['none', 20]);
  });
});

describe('notes', () => {
  it('splits the first link out of a note', () => {
    const note = 'Макет   тут https://figma.com/file/abc\nі ще текст';

    expect(noteLink(note)).toBe('https://figma.com/file/abc');
    expect(noteText(note)).toBe('Макет тут і ще текст');
  });

  it('handles empty notes', () => {
    expect(noteLink(null)).toBeNull();
    expect(noteText(null)).toBe('');
  });
});

describe('dates', () => {
  it('formats and parses months and days', () => {
    expect(formatDottedDate('2026-09-05')).toBe('05.09.2026');
    expect(toMonthParam(new Date(2026, 0, 31))).toBe('2026-01');
    expect(toMonthParam(parseMonthParam('2026-08'))).toBe('2026-08');
    expect(parseMonthParam('2026-8')).toBeNull();
    expect(parseMonthParam(undefined)).toBeNull();
  });
});
