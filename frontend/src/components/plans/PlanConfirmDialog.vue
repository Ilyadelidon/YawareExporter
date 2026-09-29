<script setup>
// Підтвердження незворотної дії в «Планах» — замість браузерного confirm().
import Dialog from 'primevue/dialog';
import UiIcon from '../UiIcon.vue';
import '../../styles/plans-ui.css';

defineProps({
  visible: { type: Boolean, default: false },
  title: { type: String, required: true },
  message: { type: String, default: '' },
  confirmLabel: { type: String, default: 'Видалити' },
  busy: { type: Boolean, default: false },
  error: { type: String, default: '' },
});

const emit = defineEmits(['update:visible', 'confirm']);
</script>

<template>
  <Dialog
    :visible="visible"
    modal
    :draggable="false"
    class="task-dialog"
    :style="{ width: '440px', maxWidth: 'calc(100vw - 32px)' }"
    @update:visible="emit('update:visible', $event)"
  >
    <template #container="{ closeCallback }">
      <div class="task-dialog-body">
        <header class="td-head">
          <div class="td-title">{{ title }}</div>
          <button type="button" class="td-close" aria-label="Закрити" @click="closeCallback">
            <UiIcon name="close" />
          </button>
        </header>

        <div class="td-content">
          <p class="td-message"><slot>{{ message }}</slot></p>
          <p v-if="error" class="td-error">{{ error }}</p>
        </div>

        <footer class="td-foot">
          <span class="td-foot-gap"></span>
          <button type="button" class="plan-btn" :disabled="busy" @click="closeCallback">Скасувати</button>
          <button type="button" class="plan-btn is-danger-solid" :disabled="busy" autofocus @click="emit('confirm')">
            {{ busy ? 'Видалення…' : confirmLabel }}
          </button>
        </footer>
      </div>
    </template>
  </Dialog>
</template>
