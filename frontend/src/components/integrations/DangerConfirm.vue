<script setup>
import { ref } from 'vue';

// Небезпечна дія внизу панелі налаштувань: перша кнопка лише питає
// підтвердження на місці, а виконує — «Так, …».
defineProps({
  // Підпис першої кнопки: «Відключити Trello»
  action: { type: String, required: true },
  // Що станеться після підтвердження
  warning: { type: String, required: true },
  confirmLabel: { type: String, default: 'Так, відключити' },
  busyLabel: { type: String, default: 'Відключаємо…' },
  busy: { type: Boolean, default: false },
});

const emit = defineEmits(['confirm']);

const confirming = ref(false);
</script>

<template>
  <div class="danger">
    <template v-if="!confirming">
      <button type="button" class="btn btn-danger-ghost" @click="confirming = true">{{ action }}</button>
      <!-- Пояснення біля кнопки, поки дію ще не почали -->
      <span v-if="$slots.default" class="danger-text"><slot /></span>
    </template>
    <template v-else>
      <span class="danger-text">{{ warning }}</span>
      <div class="danger-actions">
        <button type="button" class="btn btn-secondary" :disabled="busy" @click="confirming = false">Скасувати</button>
        <button type="button" class="btn btn-danger" :disabled="busy" @click="emit('confirm')">
          {{ busy ? busyLabel : confirmLabel }}
        </button>
      </div>
    </template>
  </div>
</template>
