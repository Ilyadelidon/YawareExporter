<script setup>
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import InputText from 'primevue/inputtext';
import Password from 'primevue/password';
import Message from 'primevue/message';
import { useAuthStore } from '../stores/auth';

const auth = useAuthStore();
const router = useRouter();

const email = ref('');
const password = ref('');
const loading = ref(false);
const errorMessage = ref('');

async function handleSubmit() {
  errorMessage.value = '';
  loading.value = true;

  try {
    await auth.login(email.value, password.value);
    router.push({ name: 'reports' });
  } catch (error) {
    if (error.userMessage) {
      errorMessage.value = error.userMessage;
    } else {
      errorMessage.value = error.response?.status === 429
        ? 'Забагато спроб входу — зачекайте хвилину і спробуйте ще раз.'
        : error.response?.data?.message || 'Не вдалося увійти. Спробуйте ще раз.';
    }
  } finally {
    loading.value = false;
  }
}
</script>

<template>
  <div class="login-page">
    <div class="panel login-card">
      <h1 class="login-title">TeamReporter</h1>
      <p class="login-subtitle">Увійдіть зі своїми даними Yaware</p>

      <form class="login-form" @submit.prevent="handleSubmit">
        <Message v-if="errorMessage" severity="error" :closable="false">
          {{ errorMessage }}
        </Message>

        <div class="field">
          <label for="email">Пошта</label>
          <InputText
            id="email"
            v-model="email"
            type="email"
            autocomplete="username"
            required
            fluid
          />
        </div>

        <div class="field">
          <label for="password">Пароль</label>
          <Password
            id="password"
            v-model="password"
            :feedback="false"
            toggle-mask
            autocomplete="current-password"
            required
            fluid
          />
        </div>

        <button class="btn-accent login-submit" type="submit" :disabled="loading">
          <template v-if="loading">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="animation: spin 0.8s linear infinite;"><path d="M21 12a9 9 0 1 1-6.219-8.56"></path></svg>
            Вхід…
          </template>
          <template v-else>Увійти</template>
        </button>

        <small v-if="auth.loginChecking" class="login-hint">
          Перевіряємо ваші дані в Yaware — перший вхід може тривати до двох хвилин…
        </small>
      </form>
    </div>
  </div>
</template>

<style scoped>
.login-page {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1rem;
  background: var(--app-bg);
}

.login-card {
  width: 100%;
  max-width: 400px;
  padding: 32px 28px;
  box-shadow: var(--shadow-card);
  animation: fadeUp 0.35s ease both;
}

.login-title {
  margin: 0;
  text-align: center;
  font-size: 17px;
  font-weight: 800;
  color: var(--ink);
  letter-spacing: -0.01em;
}

.login-subtitle {
  margin: 4px 0 20px;
  text-align: center;
  font-size: 13px;
  color: var(--muted);
}

.login-form {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.field {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
}

.field label {
  font-size: 12.5px;
  font-weight: 600;
  color: var(--text-dim);
}

.login-submit {
  width: 100%;
}

.login-hint {
  text-align: center;
  color: var(--muted);
  font-size: 12px;
}
</style>
