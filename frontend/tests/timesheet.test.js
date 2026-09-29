import { beforeEach, describe, expect, it, vi } from 'vitest';
import client from '../src/api/client';
import { useTimesheet } from '../src/composables/useTimesheet';

vi.mock('../src/api/client', () => ({
  default: { get: vi.fn() },
}));

function deferred() {
  let resolve;
  const promise = new Promise((r) => { resolve = r; });
  return { promise, resolve };
}

const response = (month, daysInMonth, rows) => ({ data: { month, days_in_month: daysInMonth, data: rows } });

beforeEach(() => {
  vi.resetAllMocks();
});

describe('useTimesheet', () => {
  it('requests the month and builds day columns from the response', async () => {
    client.get.mockResolvedValueOnce(response('2026-02', 28, [{ id: 1 }]));
    const { rows, days, loading, load } = useTimesheet();

    await load(new Date(2026, 1, 15));

    expect(client.get).toHaveBeenCalledWith('/timesheet', { params: { month: '2026-02' } });
    expect(rows.value).toEqual([{ id: 1 }]);
    expect(days.value).toHaveLength(28);
    expect(days.value[0]).toMatchObject({ day: 1, iso: '2026-02-01', weekend: true });
    expect(loading.value).toBe(false);
  });

  it('ignores a month that arrives after a newer request', async () => {
    const first = deferred();
    const second = deferred();
    client.get.mockReturnValueOnce(first.promise).mockReturnValueOnce(second.promise);
    const { rows, days, loading, load } = useTimesheet();

    const firstLoad = load(new Date(2026, 7, 1));
    const secondLoad = load(new Date(2026, 8, 1));

    second.resolve(response('2026-09', 30, [{ id: 9 }]));
    await secondLoad;
    expect(loading.value).toBe(false);
    first.resolve(response('2026-08', 31, [{ id: 8 }]));
    await firstLoad;

    expect(rows.value).toEqual([{ id: 9 }]);
    expect(days.value).toHaveLength(30);
  });

  it('drops stale rows and shows the API message on failure', async () => {
    client.get
      .mockResolvedValueOnce(response('2026-08', 31, [{ id: 8 }]))
      .mockRejectedValueOnce(Object.assign(new Error('x'), { response: { data: { message: 'Збій' } } }));
    const { rows, error, load } = useTimesheet();

    await load(new Date(2026, 7, 1));
    await load(new Date(2026, 8, 1));

    expect(rows.value).toEqual([]);
    expect(error.value).toBe('Збій');
  });
});
