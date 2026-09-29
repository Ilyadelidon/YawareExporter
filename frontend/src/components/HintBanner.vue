<script setup>
import UiIcon from './UiIcon.vue';

// Підказка на кшталт «підключіть інтеграції» — стилі .hint-banner глобальні.
defineProps({
  title: { type: String, default: '' },
});
</script>

<template>
  <div class="hint-banner">
    <div class="hint-banner-icon">
      <UiIcon name="link" />
    </div>
    <div>
      <div v-if="title" class="hint-banner-title">{{ title }}</div>
      <div class="hint-banner-text"><slot /></div>
    </div>
  </div>
</template>
