<script setup>
import { computed } from 'vue';

// Лінійні іконки інтерфейсу (24×24). Колір береться з currentColor —
// задається пропсом color або кольором тексту батька.
const ICONS = {
  file: '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><line x1="10" y1="9" x2="8" y2="9"></line>',
  calendar: '<rect x="3" y="4" width="18" height="18" rx="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line>',
  spinner: '<path d="M21 12a9 9 0 1 1-6.219-8.56"></path>',
  check: '<polyline points="20 6 9 17 4 12"></polyline>',
  play: '<polygon points="5 3 19 12 5 21 5 3"></polygon>',
  link: '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path>',
  clock: '<circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline>',
  search: '<circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line>',
  download:'<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line>',
};

// Суцільні іконки: заливка замість обведення.
const FILLED = new Set(['play']);

const props = defineProps({
  name: { type: String, required: true },
  size: { type: Number, default: 16 },
  color: { type: String, default: '' },
  strokeWidth: { type: Number, default: null },
});

const filled = computed(() => FILLED.has(props.name));
const isSpinner = computed(() => props.name === 'spinner');
</script>

<template>
  <svg
    :width="size"
    :height="size"
    viewBox="0 0 24 24"
    :fill="filled ? 'currentColor' : 'none'"
    :stroke="filled ? 'none' : 'currentColor'"
    :stroke-width="strokeWidth ?? (isSpinner ? 2.5 : 2)"
    :class="{ 'is-spinning': isSpinner }"
    :style="color ? { color } : null"
    aria-hidden="true"
    v-html="ICONS[name]"
  ></svg>
</template>

<style scoped>
.is-spinning {
  animation: spin 0.8s linear infinite;
}
</style>
