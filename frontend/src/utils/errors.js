// Текст помилки з відповіді API: повідомлення бекенду, перша помилка
// валідації або запасний текст, якщо сервер нічого не пояснив.
export function errorMessage(error, fallback) {
  const data = error?.response?.data;
  return data?.message || Object.values(data?.errors || {})[0]?.[0] || fallback;
}
