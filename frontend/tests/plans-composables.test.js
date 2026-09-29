import { beforeEach, describe, expect, it, vi } from 'vitest';
import client from '../src/api/client';
import { defaultProject, usePlan } from '../src/composables/usePlan';
import { usePlanDays } from '../src/composables/usePlanDays';
import { usePlanExport } from '../src/composables/usePlanExport';

vi.mock('../src/api/client', () => ({
  default: { get: vi.fn(), put: vi.fn(), patch: vi.fn(), post: vi.fn(), delete: vi.fn() },
}));

function deferred() {
  let resolve;
  const promise = new Promise((r) => { resolve = r; });
  return { promise, resolve };
}

const apiError = (message) => Object.assign(new Error(message), { response: { data: { message } } });

beforeEach(() => {
  vi.resetAllMocks();
});

describe('usePlan', () => {
  it('ignores a plan response that arrives after a newer request', async () => {
    const first = deferred();
    const second = deferred();
    client.get.mockReturnValueOnce(first.promise).mockReturnValueOnce(second.promise);
    const { plan, loadingPlan, loadPlan } = usePlan();
    const month = new Date(2026, 8, 1);

    const firstLoad = loadPlan(1, month);
    const secondLoad = loadPlan(2, month);

    second.resolve({ data: { project: { id: 2 } } });
    expect(await secondLoad).toBe(true);
    first.resolve({ data: { project: { id: 1 } } });
    expect(await firstLoad).toBe(false);

    expect(plan.value.project.id).toBe(2);
    expect(loadingPlan.value).toBe(false);
    expect(client.get).toHaveBeenLastCalledWith('/plans/projects/2', { params: { month: '2026-09' } });
  });

  it('rolls the status back when saving fails', async () => {
    client.patch.mockRejectedValueOnce(apiError('Немає доступу'));
    const { changeStatus, error } = usePlan();
    const task = { id: 5, status: 'pending' };

    await changeStatus(task, 'done');

    expect(task.status).toBe('pending');
    expect(error.value).toBe('Немає доступу');
  });

  it('reloads the plan after closing a task', async () => {
    client.get.mockResolvedValue({ data: { project: { id: 1 } } });
    client.patch.mockResolvedValue({});
    const { loadPlan, changeStatus } = usePlan();
    await loadPlan(1, new Date(2026, 8, 1));

    await changeStatus({ id: 5, status: 'pending' }, 'in_progress');
    expect(client.get).toHaveBeenCalledTimes(1);

    await changeStatus({ id: 5, status: 'in_progress' }, 'done');
    expect(client.get).toHaveBeenCalledTimes(2);
  });

  it('picks the remembered project, then the first active one', () => {
    const projects = [{ id: 1, archived: true }, { id: 2, archived: false }, { id: 3, archived: false }];

    expect(defaultProject(projects, 3).id).toBe(3);
    expect(defaultProject(projects, 99).id).toBe(2);
    expect(defaultProject([{ id: 1, archived: true }], null).id).toBe(1);
    expect(defaultProject([], null)).toBeNull();
  });
});

describe('usePlanDays', () => {
  it('marks a day at once and rolls it back on failure', async () => {
    const onError = vi.fn();
    const onChange = vi.fn();
    const days = usePlanDays({ onError, onChange });
    const task = { id: 7, days: {} };

    client.put.mockResolvedValueOnce({});
    days.begin(task, '2026-09-16');
    expect(await days.mark(task, '2026-09-16')).toBe(true);
    expect(task.days).toEqual({ '2026-09-16': '' });
    expect(days.edit.value.marked).toBe(true);
    expect(onChange).toHaveBeenCalledOnce();

    client.put.mockRejectedValueOnce(apiError('Не можна відмітити день, який ще не настав.'));
    expect(await days.mark(task, '2026-09-18')).toBe(false);
    expect(task.days).toEqual({ '2026-09-16': '' });
    expect(onError).toHaveBeenCalledWith('Не можна відмітити день, який ще не настав.');
  });

  it('saves a comment and moves the last worked day forward', async () => {
    const days = usePlanDays({ onError: vi.fn() });
    const task = { id: 7, days: { '2026-09-16': '' }, last_worked_on: '2026-09-10' };
    client.put.mockResolvedValueOnce({ data: { data: { comment: 'Зроблено' } } });

    days.begin(task, '2026-09-16');
    days.edit.value.comment = 'Зроблено';

    expect(await days.saveComment()).toBe(true);
    expect(client.put).toHaveBeenCalledWith('/plans/tasks/7/days/2026-09-16', { comment: 'Зроблено' });
    expect(task.days['2026-09-16']).toBe('Зроблено');
    expect(task.last_worked_on).toBe('2026-09-16');
    expect(days.saving.value).toBe(false);
  });

  it('removes the mark', async () => {
    const onChange = vi.fn();
    const days = usePlanDays({ onError: vi.fn(), onChange });
    const task = { id: 7, days: { '2026-09-16': 'x', '2026-09-15': '' } };
    client.delete.mockResolvedValueOnce({});

    days.begin(task, '2026-09-16');

    expect(await days.unmark()).toBe(true);
    expect(task.days).toEqual({ '2026-09-15': '' });
    expect(onChange).toHaveBeenCalledOnce();
  });
});

describe('usePlanExport', () => {
  const googleState = (status, message = null) => ({ data: { data: { spreadsheet_url: 'https://sheet', export: { status, message } } } });

  it('polls the queued export until it is done', async () => {
    client.post.mockResolvedValueOnce({ data: { data: { export: { status: 'queued' } } } });
    client.get
      .mockResolvedValueOnce(googleState('running'))
      .mockResolvedValueOnce(googleState('done', 'нових рядків: 2'));
    const onError = vi.fn();
    const exporter = usePlanExport({ onError, pollMs: 0 });

    await exporter.startExport();

    expect(client.get).toHaveBeenCalledTimes(2);
    expect(exporter.exportDone.value).toBe(true);
    expect(exporter.exportSummary.value).toBe('нових рядків: 2');
    expect(exporter.exporting.value).toBe(false);
    expect(onError).not.toHaveBeenCalled();
  });

  it('reports a failed export', async () => {
    client.post.mockResolvedValueOnce({ data: { data: { export: { status: 'running' } } } });
    client.get.mockResolvedValueOnce(googleState('failed', 'квота'));
    const onError = vi.fn();
    const exporter = usePlanExport({ onError, pollMs: 0 });

    await exporter.startExport();

    expect(exporter.exportDone.value).toBe(false);
    expect(onError).toHaveBeenCalledWith('Не вдалося вивантажити плани в Google Таблицю: квота');
  });

  it('resumes an export left running by a previous visit', async () => {
    client.get
      .mockResolvedValueOnce(googleState('running'))
      .mockResolvedValueOnce(googleState('done'));
    const exporter = usePlanExport({ onError: vi.fn(), pollMs: 0 });

    await exporter.resume();

    expect(exporter.exportDone.value).toBe(true);
  });

  it('does not poll when nothing is in progress', async () => {
    client.get.mockResolvedValueOnce(googleState('done'));
    const exporter = usePlanExport({ onError: vi.fn(), pollMs: 0 });

    await exporter.resume();

    expect(client.get).toHaveBeenCalledOnce();
    expect(exporter.exportDone.value).toBe(false);
  });
});
