<script setup>
import { onMounted, ref } from 'vue';
import client from '../api/client';

// Trello повертає токен у fragment (#token=...), який не доходить до сервера,
// тому ця сторінка (відкрита в popup) зчитує його і передає на бекенд POST-ом.
const state = ref('processing');
const message = ref('Підключаємо Trello…');

onMounted(async () => {
  const params = new URLSearchParams(window.location.hash.replace(/^#/, ''));
  const token = params.get('token');

  if (!token) {
    state.value = 'error';
    message.value = params.get('error')
      ? 'Ви відхилили доступ у Trello. Закрийте вкладку і спробуйте ще раз.'
      : 'Trello не повернув токен. Закрийте вкладку і спробуйте ще раз.';
    return;
  }

  // Токен не має лишатися в історії браузера.
  window.history.replaceState(null, '', window.location.pathname);

  try {
    const { data } = await client.post('/trello/token', { token });
    state.value = 'success';
    message.value = `Trello підключено як @${data.username}. Цю вкладку можна закрити.`;
    window.opener?.postMessage({ type: 'trello-connected', username: data.username }, window.location.origin);
    window.close();
  } catch (error) {
    state.value = 'error';
    message.value = error.response?.data?.message || 'Не вдалося зберегти токен Trello.';
  }
});
</script>

<template>
  <div class="callback-page">
    <div class="panel callback-panel">
      <div v-if="state === 'processing'" class="spinner"></div>
      <p :class="{ 'text-error': state === 'error' }">{{ message }}</p>
    </div>
  </div>
</template>

<style scoped>
.callback-page {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 24px;
}

.callback-panel {
  padding: 32px 40px;
  text-align: center;
  max-width: 420px;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 14px;
  font-size: 14.5px;
}

.text-error {
  color: #c0392b;
}

.spinner {
  width: 26px;
  height: 26px;
  border: 3px solid var(--line);
  border-top-color: var(--accent);
  /* виняток із глобального border-radius: 0 — спінер лишається круглим */
  border-radius: 50% !important;
  animation: spin 0.8s linear infinite;
}

@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}
</style>
