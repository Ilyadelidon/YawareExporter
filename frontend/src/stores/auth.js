import { defineStore } from 'pinia';
import client from '../api/client';

// Скільки чекаємо на асинхронну Yaware-перевірку: Playwright-джоба триває
// до ~2 хв + можлива черга, полимо кожні 3 с.
const LOGIN_CHECK_TIMEOUT_MS = 4 * 60 * 1000;
const LOGIN_CHECK_POLL_MS = 3000;

function loginError(message) {
  const error = new Error(message);
  error.userMessage = message;
  return error;
}

export const useAuthStore = defineStore('auth', {
  state: () => ({
    user: null,
    token: localStorage.getItem('auth_token'),
    // true, поки триває асинхронна перевірка кредів у Yaware (перший вхід).
    loginChecking: false,
  }),

  getters: {
    isAuthenticated: (state) => Boolean(state.token),
    isAdmin: (state) => state.user?.role === 'admin',
  },

  actions: {
    async login(email, password) {
      const response = await client.post('/auth/login', { email, password });

      // 202 — креди пішли на перевірку в Yaware, чекаємо результат полінгом.
      if (response.status === 202 && response.data?.check_id) {
        return this.waitForLoginCheck(response.data.check_id);
      }

      this.setSession(response.data);
    },

    async waitForLoginCheck(checkId) {
      this.loginChecking = true;

      try {
        const deadline = Date.now() + LOGIN_CHECK_TIMEOUT_MS;

        while (Date.now() < deadline) {
          await new Promise((resolve) => setTimeout(resolve, LOGIN_CHECK_POLL_MS));

          let data;
          try {
            ({ data } = await client.get(`/auth/login/pending/${checkId}`));
          } catch (error) {
            if (error.response?.status === 404) {
              throw loginError('Перевірка протермінована — спробуйте увійти ще раз.');
            }
            continue; // тимчасова помилка мережі/429 — пробуємо далі
          }

          if (data.status === 'ok') {
            this.setSession(data);
            return;
          }

          if (data.status === 'failed') {
            throw loginError(data.message || 'Не вдалося увійти в Yaware з цими даними.');
          }
        }

        throw loginError('Перевірка триває надто довго — спробуйте увійти ще раз.');
      } finally {
        this.loginChecking = false;
      }
    },

    setSession(data) {
      this.token = data.token;
      this.user = data.user;
      localStorage.setItem('auth_token', data.token);
    },

    async logout() {
      try {
        await client.post('/auth/logout');
      } finally {
        this.token = null;
        this.user = null;
        localStorage.removeItem('auth_token');
      }
    },

    async fetchUser() {
      if (!this.token) {
        return;
      }
      const { data } = await client.get('/auth/me');
      this.user = data;
    },
  },
});
