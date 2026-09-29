// Допоміжні функції розділу «Налаштування» без стану — їх легко тестувати окремо.

// Адресу зводимо до того ж вигляду, що й сервер, — інакше та сама пошта,
// написана з великої літери, виглядала б як нова.
export function normaliseEmail(value) {
  return String(value ?? '').trim().toLowerCase();
}

// «Перша і ще N» — підпис рядка, коли список згорнуто.
export function listSummary(items, empty) {
  if (!items.length) return empty;
  if (items.length === 1) return items[0];
  return `${items[0]} і ще ${items.length - 1}`;
}

// «група · з 22.09.2026» — тип чату відрізняє однаково названі адресати,
// дата нагадує, коли підписку взагалі вмикали.
export function opsChatMeta(chat) {
  const kind = chat.kind === 'group' ? 'група' : 'особистий чат';
  if (!chat.connected_at) return kind;

  const date = new Date(chat.connected_at).toLocaleDateString('uk-UA', {
    day: '2-digit', month: '2-digit', year: 'numeric',
  });

  return `${kind} · з ${date}`;
}

// З https://t.me/TeamReporter_Bot?start=КОД робимо те, що людина напише в
// групі: там deep-link не працює, бота треба покликати командою.
export function startCommand(url) {
  try {
    const code = new URL(url).searchParams.get('start');
    return code ? `/start ${code}` : null;
  } catch {
    return null;
  }
}
