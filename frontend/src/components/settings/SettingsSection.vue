<script setup>
import '../../styles/integrations-ui.css';

// Секція «Налаштувань»: заголовок з поясненням і панель з одним рядком,
// поки рядок вантажиться — скелет на його місці.
defineProps({
  id: { type: String, required: true },
  title: { type: String, required: true },
  loading: { type: Boolean, default: false },
});
</script>

<template>
  <section class="int-ui" :aria-labelledby="id">
    <header class="section-head">
      <div>
        <h2 :id="id" class="section-title">{{ title }}</h2>
        <p class="section-desc"><slot name="description" /></p>
      </div>
    </header>

    <div class="panel int-list" :aria-busy="loading">
      <div v-if="loading" class="row-skeleton"><div class="skeleton"></div></div>
      <slot v-else />
    </div>
  </section>
</template>
