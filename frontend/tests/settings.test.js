import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import client from '../src/api/client';
import { useAlertEmails } from '../src/composables/settings/useAlertEmails';
import { useBitrixWorkspace } from '../src/composables/settings/useBitrixWorkspace';
import { useOpsTelegram } from '../src/composables/settings/useOpsTelegram';
import { listSummary, normaliseEmail, opsChatMeta, startCommand } from '../src/utils/settings';

vi.mock('../src/api/client', () => ({
  default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
}));

const apiError = (message, status = 422) => Object.assign(new Error('x'), { response: { status, data: { message } } });

beforeEach(() => {
  vi.resetAllMocks();
});

describe('settings utils', () => {
  it('normalises an email the way the server does', () => {
    expect(normaliseEmail('  Boss@Example.COM ')).toBe('boss@example.com');
    expect(normaliseEmail(null)).toBe('');
  });

  it('summarises a list by its first item', () => {
    expect(listSummary([], 'порожньо')).toBe('порожньо');
    expect(listSummary(['a'], '')).toBe('a');
    expect(listSummary(['a', 'b', 'c'], '')).toBe('a і ще 2');
  });

  it('describes a chat by kind and date', () => {
    expect(opsChatMeta({ kind: 'group', connected_at: null })).toBe('група');
    expect(opsChatMeta({ kind: 'private', connected_at: '2026-09-22T10:00:00Z' })).toBe('особистий чат · з 22.09.2026');
  });

  it('turns a deep link into a group command', () => {
    expect(startCommand('https://t.me/TeamReporter_Bot?start=abc')).toBe('/start abc');
    expect(startCommand('https://t.me/TeamReporter_Bot')).toBeNull();
    expect(startCommand('не посилання')).toBeNull();
  });
});

describe('useBitrixWorkspace', () => {
  it('takes the new state from the connect response without asking again', async () => {
    client.post.mockResolvedValueOnce({
      data: { connected: true, portal_host: 'team.bitrix24.ua', connected_by: 'Адмін', message: 'Підключено.' },
    });
    const bitrix = useBitrixWorkspace();
    bitrix.form.value = { portalUrl: ' https://team.bitrix24.ua ', clientId: 'local.app', clientSecret: 'secret' };
    bitrix.open.value = true;

    expect(await bitrix.connect()).toBe(true);

    expect(client.post).toHaveBeenCalledWith('/bitrix/workspace', {
      portal_url: 'https://team.bitrix24.ua', client_id: 'local.app', client_secret: 'secret',
    });
    expect(client.get).not.toHaveBeenCalled();
    expect(bitrix.connected.value).toBe(true);
    expect(bitrix.meta.value).toBe('team.bitrix24.ua · підключив Адмін');
    expect(bitrix.form.value.clientSecret).toBe('');
    expect(bitrix.open.value).toBe(false);
    expect(bitrix.notice.value).toEqual({ tone: 'ok', text: 'Підключено.' });
  });

  it('does not submit an incomplete form', async () => {
    const bitrix = useBitrixWorkspace();
    bitrix.form.value = { portalUrl: 'https://team.bitrix24.ua', clientId: ' ', clientSecret: 'secret' };

    expect(await bitrix.connect()).toBe(false);
    expect(client.post).not.toHaveBeenCalled();
  });
});

describe('useAlertEmails', () => {
  const state = (emails) => ({ data: { alert_emails: emails, others_count: 0, max_emails: 10, message: 'ok' } });

  it('refuses an address already in the list, whatever its case', async () => {
    client.get.mockResolvedValueOnce(state(['boss@example.com']));
    const alerts = useAlertEmails();
    await alerts.load();

    alerts.draft.value = ' BOSS@example.com';
    expect(alerts.canAdd.value).toBe(false);

    alerts.draft.value = 'Second@example.com';
    client.put.mockResolvedValueOnce(state(['boss@example.com', 'second@example.com']));
    await alerts.add();

    expect(client.put).toHaveBeenCalledWith('/alerts/emails', { alert_emails: ['boss@example.com', 'second@example.com'] });
    expect(alerts.draft.value).toBe('');
  });

  it('asks before removing the last address', async () => {
    client.get.mockResolvedValueOnce(state(['boss@example.com']));
    const alerts = useAlertEmails();
    await alerts.load();

    await alerts.remove('boss@example.com');
    expect(client.put).not.toHaveBeenCalled();
    expect(alerts.confirmingRemove.value).toBe('boss@example.com');

    client.put.mockResolvedValueOnce(state([]));
    await alerts.remove('boss@example.com');
    expect(client.put).toHaveBeenCalledWith('/alerts/emails', { alert_emails: [] });
    expect(alerts.confirmingRemove.value).toBeNull();
    expect(alerts.active.value).toBe(false);
  });

  it('edits an address in place and skips an unchanged one', async () => {
    client.get.mockResolvedValueOnce(state(['boss@example.com', 'deputy@example.com']));
    const alerts = useAlertEmails();
    await alerts.load();

    alerts.startEdit('boss@example.com');
    alerts.editDraft.value = 'Boss@example.com ';
    await alerts.saveEdit();
    expect(client.put).not.toHaveBeenCalled();
    expect(alerts.editing.value).toBeNull();

    alerts.startEdit('boss@example.com');
    alerts.editDraft.value = 'deputy@example.com';
    expect(alerts.canSaveEdit.value).toBe(false);

    alerts.editDraft.value = 'chief@example.com';
    client.put.mockResolvedValueOnce(state(['chief@example.com', 'deputy@example.com']));
    await alerts.saveEdit();
    expect(client.put).toHaveBeenCalledWith('/alerts/emails', { alert_emails: ['chief@example.com', 'deputy@example.com'] });
    expect(alerts.editing.value).toBeNull();
  });

  it('keeps the draft and shows the server error when saving fails', async () => {
    client.put.mockRejectedValueOnce(apiError('Некоректна адреса.'));
    const alerts = useAlertEmails();
    alerts.draft.value = 'boss@example';

    await alerts.add();

    expect(alerts.draft.value).toBe('boss@example');
    expect(alerts.notice.value).toEqual({ tone: 'error', text: 'Некоректна адреса.' });
    expect(alerts.saving.value).toBe(false);
  });
});

describe('useOpsTelegram', () => {
  const chat = (id, title) => ({ id, title, kind: 'private', connected_at: null });
  const state = (chats) => ({ data: { configured: true, max_chats: 5, chats } });

  beforeEach(() => {
    vi.useFakeTimers();
    vi.stubGlobal('window', { open: vi.fn() });
  });

  afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
  });

  it('waits for a new chat by id, not by count', async () => {
    client.get.mockResolvedValueOnce(state([chat(1, 'Старий'), chat(2, 'Другий')]));
    const ops = useOpsTelegram();
    await ops.load();

    client.post.mockResolvedValueOnce({ data: { url: 'https://t.me/Bot?start=code' } });
    await ops.connect();
    expect(ops.linkCommand.value).toBe('/start code');

    // Поки чекали, один чат прибрали з іншої вкладки — кількість та сама, нового нема.
    client.get.mockResolvedValueOnce(state([chat(1, 'Старий')]));
    await vi.advanceTimersByTimeAsync(3000);
    expect(ops.linking.value).toBe(true);

    client.get.mockResolvedValueOnce(state([chat(1, 'Старий'), chat(3, 'Група')]));
    await vi.advanceTimersByTimeAsync(3000);
    expect(ops.linking.value).toBe(false);
    expect(ops.notice.value).toEqual({ tone: 'ok', text: 'Чат «Група» підключено.' });
  });

  it('stops polling once disposed', async () => {
    client.get.mockResolvedValue(state([]));
    client.post.mockResolvedValueOnce({ data: { url: 'https://t.me/Bot?start=code' } });
    const ops = useOpsTelegram();

    await ops.connect();
    ops.dispose();
    await vi.advanceTimersByTimeAsync(10000);

    expect(client.get).not.toHaveBeenCalled();
  });

  it('takes the remaining chats from the unlink response', async () => {
    client.get.mockResolvedValueOnce(state([chat(1, 'Перший'), chat(2, 'Другий')]));
    const ops = useOpsTelegram();
    await ops.load();
    ops.open.value = true;

    client.delete.mockResolvedValueOnce({ data: { ...state([chat(2, 'Другий')]).data, message: 'Прибрано.' } });
    await ops.remove(ops.chats.value[0]);

    expect(client.get).toHaveBeenCalledTimes(1);
    expect(ops.chats.value.map((item) => item.id)).toEqual([2]);
    expect(ops.removingId.value).toBeNull();
    expect(ops.open.value).toBe(true);
    expect(ops.notice.value).toEqual({ tone: 'ok', text: 'Прибрано.' });
  });

  it('explains the rate limit on a test message', async () => {
    client.post.mockRejectedValueOnce(apiError('Too Many Attempts.', 429));
    const ops = useOpsTelegram();

    await ops.sendTest(chat(1, 'Чат'));

    expect(ops.notice.value.text).toBe('Забагато пробних повідомлень — зачекайте хвилину.');
    expect(ops.testingId.value).toBeNull();
  });
});
