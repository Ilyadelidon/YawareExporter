// Статуси задач плану, які вважаються закритими: фільтр «Активні задачі»
// їх ховає, а «працюю зараз» на них не ставиться.
export const CLOSED_STATUSES = ['done', 'not_relevant'];

// Після цих статусів задача могла перестати бути чиєюсь поточною.
export const RELEASING_STATUSES = [...CLOSED_STATUSES, 'paused'];

export function isClosedStatus(status) {
  return CLOSED_STATUSES.includes(status);
}
