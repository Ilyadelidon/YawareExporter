// Українське відмінювання за числом: pluralUk(5, ['таска', 'таски', 'тасок']) → 'тасок'.
export function pluralUk(n, [one, few, many]) {
  const mod10 = n % 10;
  const mod100 = n % 100;
  if (mod100 >= 11 && mod100 <= 14) return many;
  if (mod10 === 1) return one;
  if (mod10 >= 2 && mod10 <= 4) return few;
  return many;
}
