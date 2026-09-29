<script setup>
// Кнопка, що розгортає налаштування під рядком інтеграції. Основна (primary),
// поки інтеграцію ще треба підключити, і другорядна — коли вона вже працює.
defineProps({
  // id рядка IntegrationRow: панель налаштувань має id `int-panel-${target}`
  target: { type: String, required: true },
  expanded: { type: Boolean, default: false },
  primary: { type: Boolean, default: false },
});

defineEmits(['toggle']);
</script>

<template>
  <button
    type="button"
    class="btn"
    :class="primary ? 'btn-primary' : 'btn-secondary'"
    :aria-expanded="expanded"
    :aria-controls="`int-panel-${target}`"
    @click="$emit('toggle')"
  >
    <slot>Налаштування</slot>
    <svg class="chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><polyline points="6 9 12 15 18 9"></polyline></svg>
  </button>
</template>
