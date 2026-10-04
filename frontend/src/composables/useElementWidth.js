import { onBeforeUnmount, ref, watch } from 'vue';

// Ширина елемента в пікселях, що стежить за зміною розміру (для SVG-графіків:
// малюємо в справжніх пікселях, щоб текст і товщина ліній не масштабувались).
// Елемент може зʼявлятися й зникати (v-if) — тоді спостереження перепідключається.
export function useElementWidth(target) {
  const width = ref(0);
  const observer = new ResizeObserver(([entry]) => {
    width.value = Math.floor(entry.contentRect.width);
  });

  watch(target, (element, previous) => {
    if (previous) observer.unobserve(previous);
    if (element) observer.observe(element);
    else width.value = 0;
  }, { immediate: true, flush: 'post' });

  onBeforeUnmount(() => observer.disconnect());

  return width;
}
