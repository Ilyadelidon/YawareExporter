import { beforeEach, describe, expect, it, vi } from 'vitest';
import client from '../src/api/client';
import { useEmployees } from '../src/composables/useEmployees';
import { useEmployeeMemory } from '../src/composables/useEmployeeMemory';
import { integrationRows, verdictClass } from '../src/utils/employees';

vi.mock('../src/api/client', () => ({
  default: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}));

const apiError = (message) => Object.assign(new Error('x'), { response: { data: { message } } });

beforeEach(() => {
  vi.resetAllMocks();
});

describe('integrationRows', () => {
  const info = {
    has_account: true,
    task_provider: 'bitrix',
    trello: { connected: true, username: 'ivan', board_url: null },
    bitrix: { connected: true, username: 'Іван' },
    google: { connected: false, url: null },
    telegram: { connected: true },
  };

  it('is empty for an employee who never logged in', () => {
    expect(integrationRows({ has_account: false })).toEqual([]);
    expect(integrationRows(undefined)).toEqual([]);
  });

  it('marks the active tracker and a Trello without a board', () => {
    const rows = Object.fromEntries(integrationRows(info).map((row) => [row.key, row]));

    expect(rows.trello).toMatchObject({ detail: '@ivan', note: 'дошку не обрано' });
    expect(rows.bitrix).toMatchObject({ detail: 'Іван', note: 'активний трекер' });
    expect(rows.google.connected).toBe(false);
    expect(rows.telegram.connected).toBe(true);
  });

  it('maps verdicts to colour classes', () => {
    expect(verdictClass('personal')).toBe('is-personal');
    expect(verdictClass('other')).toBe('');
  });
});

describe('useEmployees', () => {
  it('shows an error instead of failing silently when the list does not load', async () => {
    client.get.mockRejectedValueOnce(apiError('Збій'));
    const { employees, loading, error, load } = useEmployees();

    await load();

    expect(employees.value).toEqual([]);
    expect(error.value).toBe('Збій');
    expect(loading.value).toBe(false);
  });

  it('skips saving an unchanged position', async () => {
    const { savePosition } = useEmployees();

    await savePosition({ id: 1, position: null }, null);

    expect(client.patch).not.toHaveBeenCalled();
  });

  it('keeps integrations when dismissal returns the bare employee', async () => {
    client.post.mockResolvedValueOnce({ data: { data: { id: 1, active: false, dismissed_at: '2026-09-29' } } });
    const employee = { id: 1, active: true, dismissed_at: null, integrations: { has_account: true } };
    const { savingId, dismiss } = useEmployees();

    expect(await dismiss(employee)).toBe(true);

    expect(client.post).toHaveBeenCalledWith('/employees/1/dismissal');
    expect(employee).toMatchObject({ active: false, dismissed_at: '2026-09-29', integrations: { has_account: true } });
    expect(savingId.value).toBeNull();
  });

  it('reports a failed status change', async () => {
    client.delete.mockRejectedValueOnce(new Error('offline'));
    const employee = { id: 1, dismissed_at: '2026-09-01' };
    const { error, reinstate } = useEmployees();

    expect(await reinstate(employee)).toBe(false);

    expect(employee.dismissed_at).toBe('2026-09-01');
    expect(error.value).toBe('Не вдалося змінити статус працівника.');
  });
});

describe('useEmployeeMemory', () => {
  const memory = (rows) => ({ data: { data: rows, recheck_days: 90 } });

  it('keeps the save error visible after restoring saved rows', async () => {
    client.get.mockImplementation(async () => memory([{ id: 5, verdict: 'personal' }]));
    client.patch.mockRejectedValueOnce(apiError('Не можна'));
    const { rows, error, load, save } = useEmployeeMemory(3);
    await load();

    rows.value[0].verdict = 'work_related';
    await save(rows.value[0]);

    expect(client.patch).toHaveBeenCalledWith('/employees/3/memory/5', { verdict: 'work_related', note: undefined });
    expect(rows.value[0].verdict).toBe('personal');
    expect(error.value).toBe('Не можна');
  });

  it('adds a fact and reloads the list', async () => {
    client.get.mockResolvedValue(memory([{ id: 7, kind: 'fact' }]));
    client.post.mockResolvedValueOnce({ data: { data: {} } });
    const { rows, addFact } = useEmployeeMemory(3);

    expect(await addFact({ name: 'Графік', note: null })).toBe(true);

    expect(client.post).toHaveBeenCalledWith('/employees/3/memory', { kind: 'fact', name: 'Графік', note: null });
    expect(rows.value).toEqual([{ id: 7, kind: 'fact' }]);
  });
});
