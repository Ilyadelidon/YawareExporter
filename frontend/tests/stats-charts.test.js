import { describe, expect, it } from 'vitest';
import { dailySeries, labelEvery, niceTicks, periodDates } from '../src/utils/statsCharts';
import { matchPreset, periodPresets } from '../src/utils/statsPresets';
import { toIsoDate } from '../src/utils/dates';

describe('periodDates', () => {
  it('включає обидва кінці і переходить через місяць', () => {
    expect(periodDates('2026-08-30', '2026-09-02')).toEqual(['2026-08-30', '2026-08-31', '2026-09-01', '2026-09-02']);
  });
});

describe('dailySeries', () => {
  it('сумує працівників за датою і заповнює пропуски нулями', () => {
    const stats = [
      { date: '2026-09-01', productive_seconds: 100, neutral_seconds: 10, unproductive_seconds: 1 },
      { date: '2026-09-01', productive_seconds: 200, neutral_seconds: 20, unproductive_seconds: 2 },
      { date: '2026-09-03', productive_seconds: 50, neutral_seconds: 0, unproductive_seconds: 0 },
    ];
    expect(dailySeries(stats, '2026-09-01', '2026-09-03')).toEqual([
      { date: '2026-09-01', productive: 300, neutral: 30, unproductive: 3 },
      { date: '2026-09-02', productive: 0, neutral: 0, unproductive: 0 },
      { date: '2026-09-03', productive: 50, neutral: 0, unproductive: 0 },
    ]);
  });
});

describe('niceTicks', () => {
  it('дає круглий крок від нуля до значення не менше максимуму', () => {
    expect(niceTicks(7.3)).toEqual([0, 2, 4, 6, 8]);
    expect(niceTicks(140)).toEqual([0, 50, 100, 150]);
    expect(niceTicks(0)).toEqual([0, 1]);
  });
});

describe('labelEvery', () => {
  it('проріджує підписи, що не влазять', () => {
    expect(labelEvery(30, 660)).toBe(2);
    expect(labelEvery(7, 660)).toBe(1);
  });
});

describe('periodPresets', () => {
  // Четвер, 2026-10-01: тиждень почався в понеділок 28.09, місяць — 01.10.
  const presets = periodPresets(new Date(2026, 9, 1));
  const iso = (value) => {
    const p = presets.find((preset) => preset.value === value);
    return [toIsoDate(p.from), toIsoDate(p.to)];
  };

  it('рахує тижні з понеділка і місяці цілком', () => {
    expect(iso('this_week')).toEqual(['2026-09-28', '2026-10-01']);
    expect(iso('last_week')).toEqual(['2026-09-21', '2026-09-27']);
    expect(iso('this_month')).toEqual(['2026-10-01', '2026-10-01']);
    expect(iso('last_month')).toEqual(['2026-09-01', '2026-09-30']);
  });

  it('matchPreset знаходить пресет лише при точному збігу дат', () => {
    expect(matchPreset(presets, new Date(2026, 8, 1), new Date(2026, 8, 30))).toBe('last_month');
    expect(matchPreset(presets, new Date(2026, 8, 2), new Date(2026, 8, 30))).toBeNull();
    expect(matchPreset(presets, null, new Date(2026, 8, 30))).toBeNull();
  });
});
