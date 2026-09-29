<!-- Посилання на сторінку інтеграцій у підказках про ненастроєні підключення -->
<template>
  <router-link class="hint-link" :to="{ name: 'integrations' }"><slot>Перейти до інтеграцій</slot></router-link>
</template>

<style scoped>
.hint-link {
  color: var(--accent);
  font-weight: 600;
  text-decoration: none;
  white-space: nowrap;
}

.hint-link:hover {
  text-decoration: underline;
}
</style>
