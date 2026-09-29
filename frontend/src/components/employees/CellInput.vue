<script setup>
// Поле, що редагується прямо в клітинці таблиці. Зберігається на blur/Enter:
// віддає обрізане значення або null, якщо поле очистили.
defineProps({
  value: { type: String, default: '' },
  placeholder: { type: String, default: '' },
  disabled: { type: Boolean, default: false },
});

const emit = defineEmits(['commit']);
</script>

<template>
  <input
    class="cell-input"
    type="text"
    :placeholder="placeholder"
    :value="value || ''"
    :disabled="disabled"
    @change="emit('commit', $event.target.value.trim() || null)"
    @keyup.enter="$event.target.blur()"
  >
</template>

<style scoped>
.cell-input {
  width: 100%;
  min-width: 140px;
  padding: 4px 6px;
  border: 1px solid transparent;
  background: transparent;
  font: inherit;
  color: inherit;
}

.cell-input:hover:not(:disabled) {
  border-color: var(--line);
}

.cell-input:focus {
  outline: none;
  border-color: var(--accent);
  background: var(--surface);
}

.cell-input:disabled {
  opacity: 0.6;
}
</style>
