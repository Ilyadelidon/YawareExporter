import { computed, ref } from 'vue';
import client from '../../api/client';
import { errorMessage } from '../../utils/errors';
import { listSummary, normaliseEmail } from '../../utils/settings';
import { useSettingsRow } from './useSettingsRow';

/**
 * Пошти, на які керівнику йдуть листи про критичні порушення з AI-розбору дня.
 * Список персональний: кожен адміністратор веде свої адреси сам, тому тут немає
 * ні чужих адрес, ні спільного перемикача на команду.
 */
export function useAlertEmails() {
  const { open, notice, say, run } = useSettingsRow();

  const loading = ref(true);
  const emails = ref([]);
  const othersCount = ref(0);
  const maxEmails = ref(10);
  const draft = ref('');
  const saving = ref(false);
  // Остання адреса, яку просять прибрати: це вимикає листи, тож питаємо на місці.
  const confirmingRemove = ref(null);
  // Адреса, яку зараз правлять просто в списку: у пошті легко помилитись на одну
  // літеру, і перепечатувати її заново через «прибрати — додати» безглуздо.
  const editing = ref(null);
  const editDraft = ref('');

  const active = computed(() => emails.value.length > 0);
  const full = computed(() => emails.value.length >= maxEmails.value);

  const canAdd = computed(() => {
    const email = normaliseEmail(draft.value);
    return email !== '' && !full.value && !emails.value.includes(email);
  });

  // Правка на ту саму адресу дозволена (просто закриє поле), на чужу зі списку — ні.
  const canSaveEdit = computed(() => {
    const email = normaliseEmail(editDraft.value);
    return email !== '' && (email === editing.value || !emails.value.includes(email));
  });

  const status = computed(() => (active.value
    ? { tone: 'ok', label: 'Увімкнено' }
    : { tone: 'off', label: 'Вимкнено' }));

  const meta = computed(() => listSummary(emails.value, 'Жодної адреси — листи про порушення нікуди не йдуть'));

  function apply(data) {
    emails.value = data.alert_emails || [];
    othersCount.value = data.others_count || 0;
    maxEmails.value = data.max_emails || maxEmails.value;
    // Рядка, який правили чи хотіли прибрати, у новому списку могло вже не бути.
    if (editing.value !== null && !emails.value.includes(editing.value)) cancelEdit();
    if (!emails.value.includes(confirmingRemove.value)) confirmingRemove.value = null;
  }

  async function load() {
    try {
      const { data } = await client.get('/alerts/emails');
      apply(data);
    } catch (error) {
      say('error', errorMessage(error, 'Не вдалося отримати налаштування сповіщень.'));
    } finally {
      loading.value = false;
    }
  }

  function save(next) {
    return run(saving, 'Не вдалося зберегти пошти.', async () => {
      const { data } = await client.put('/alerts/emails', { alert_emails: next });
      apply(data);
      return data.message;
    });
  }

  function toggle() {
    confirmingRemove.value = null;
    cancelEdit();
    open.value = !open.value;
  }

  async function add() {
    if (!canAdd.value || saving.value) return;
    if (await save([...emails.value, normaliseEmail(draft.value)])) draft.value = '';
  }

  function startEdit(email) {
    confirmingRemove.value = null;
    notice.value = null;
    editing.value = email;
    editDraft.value = email;
  }

  function cancelEdit() {
    editing.value = null;
    editDraft.value = '';
  }

  async function saveEdit() {
    if (!canSaveEdit.value || saving.value) return;

    const was = editing.value;
    const now = normaliseEmail(editDraft.value);

    // Нічого не змінилось — не смикаємо сервер зайвим запитом.
    if (was === now) {
      cancelEdit();
      return;
    }

    if (await save(emails.value.map((item) => (item === was ? now : item)))) cancelEdit();
  }

  function cancelRemove() {
    confirmingRemove.value = null;
  }

  async function remove(email) {
    if (saving.value) return;
    // Прибрати останню адресу — це вимкнути листи зовсім, і про це варто спитати.
    if (emails.value.length === 1 && confirmingRemove.value !== email) {
      cancelEdit();
      confirmingRemove.value = email;
      return;
    }
    await save(emails.value.filter((item) => item !== email));
  }

  return {
    loading,
    open,
    notice,
    emails,
    othersCount,
    maxEmails,
    draft,
    saving,
    confirmingRemove,
    editing,
    editDraft,
    active,
    full,
    canAdd,
    canSaveEdit,
    status,
    meta,
    load,
    toggle,
    add,
    startEdit,
    cancelEdit,
    saveEdit,
    cancelRemove,
    remove,
  };
}
