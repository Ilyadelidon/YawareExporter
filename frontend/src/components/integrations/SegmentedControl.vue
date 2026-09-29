<script setup>
// Сегментований перемикач. role="radio" — вибір значення (джерело тасків),
// role="tab" — перемикання форми під ним (спосіб підключення таблиці).
defineProps({
  modelValue: { type: String, default: null },
  // [{ value, label, disabled?, title? }]
  options: { type: Array, required: true },
  role: { type: String, default: 'radio' },
  small: { type: Boolean, default: false },
  disabled: { type: Boolean, default: false },
});

defineEmits(['update:modelValue']);
</script>

<template>
  <div class="segmented" :class="{ 'is-small': small }" :role="role === 'tab' ? 'tablist' : 'radiogroup'">
    <button
      v-for="option in options"
      :key="option.value"
      type="button"
      :role="role"
      class="segmented-opt"
      :aria-checked="role === 'radio' ? option.value === modelValue : null"
      :aria-selected="role === 'tab' ? option.value === modelValue : null"
      :disabled="disabled || option.disabled"
      :title="option.title || null"
      @click="$emit('update:modelValue', option.value)"
    >{{ option.label }}</button>
  </div>
</template>

<style scoped>
.segmented {
  display: inline-flex;
  border: 1px solid var(--control-line);
  background: var(--surface);
}

.segmented-opt {
  font-family: inherit;
  font-size: 12.5px;
  font-weight: 600;
  color: var(--text-dim);
  background: none;
  border: none;
  padding: 7px 14px;
  cursor: pointer;
  transition: background 0.15s ease, color 0.15s ease;
}

.segmented-opt + .segmented-opt {
  border-left: 1px solid var(--control-line);
}

.segmented-opt:hover:not(:disabled):not([aria-checked='true']):not([aria-selected='true']) {
  background: var(--line);
  color: #2b2f33;
}

.segmented-opt[aria-checked='true'],
.segmented-opt[aria-selected='true'] {
  background: var(--accent);
  color: #fff;
  cursor: default;
}

.segmented-opt:disabled:not([aria-checked='true']) {
  color: var(--muted-2);
  cursor: not-allowed;
}

.segmented-opt:focus-visible {
  outline: 2px solid var(--accent);
  outline-offset: 2px;
  position: relative;
}

.segmented.is-small {
  margin-bottom: 16px;
}

.segmented.is-small .segmented-opt {
  padding: 6px 12px;
  font-size: 12px;
}
</style>
