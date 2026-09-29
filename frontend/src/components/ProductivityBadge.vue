<script setup>
import { computed } from 'vue';
import { PRODUCTIVITY } from '../constants/productivity';

const props = defineProps({
  value: { type: String, default: '' },
});

const meta = computed(() => PRODUCTIVITY[props.value]);
</script>

<template>
  <span class="productivity-badge" :class="meta?.class">{{ meta?.label || value }}</span>
</template>

<style scoped>
.productivity-badge {
  display: inline-block;
  padding: 2px 8px;
  font-size: 11.5px;
  font-weight: 600;
  background: var(--line);
  color: var(--muted);
}

.productivity-badge.is-productive {
  background: #d5f2ee;
  color: #0e7d70;
}

.productivity-badge.is-unproductive {
  background: #fbe3e3;
  color: #b33c3c;
}
</style>
