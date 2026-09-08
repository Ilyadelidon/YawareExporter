<script setup>
import { computed } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';

const auth = useAuthStore();
const router = useRouter();

const roleLabel = computed(() => (auth.isAdmin ? 'Адміністратор' : 'Працівник'));

async function handleLogout() {
  await auth.logout();
  router.push({ name: 'login' });
}
</script>

<template>
  <div class="app-shell">
    <header class="app-header">
      <div class="header-inner">
        <div class="header-left">
          <div class="brand">
            <span class="brand-name">TeamReporter</span>
          </div>
          <nav class="nav">
            <!-- Роут «/» префіксно матчиться з усіма сторінками — підсвічуємо лише точний збіг -->
            <router-link :to="{ name: 'reports' }" class="nav-item" active-class="nav-prefix-match" exact-active-class="router-link-active">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="12" width="4" height="8"></rect><rect x="10" y="7" width="4" height="13"></rect><rect x="17" y="3" width="4" height="17"></rect></svg>
              Звіти
            </router-link>
            <router-link :to="{ name: 'history' }" class="nav-item">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
              Статистика
            </router-link>
            <router-link v-if="auth.isAdmin" :to="{ name: 'timesheet' }" class="nav-item">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"></rect><line x1="3" y1="10" x2="21" y2="10"></line><line x1="9" y1="10" x2="9" y2="22"></line><line x1="15" y1="10" x2="15" y2="22"></line></svg>
              Табель
            </router-link>
            <router-link v-if="auth.isAdmin" :to="{ name: 'employees' }" class="nav-item">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
              Працівники
            </router-link>
            <router-link v-if="auth.isAdmin" :to="{ name: 'settings' }" class="nav-item">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
              Налаштування
            </router-link>
          </nav>
        </div>
        <div class="header-right">
          <div v-if="auth.user" class="user-chip">
            <div class="user-meta">
              <span class="user-name">{{ auth.user.name }}</span>
              <span class="user-role">{{ roleLabel }}</span>
            </div>
          </div>
          <div class="header-divider"></div>
          <button class="logout-btn" type="button" @click="handleLogout">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
            Вийти
          </button>
        </div>
      </div>
    </header>

    <main class="app-content">
      <router-view />
    </main>
  </div>
</template>

<style scoped>
.app-shell {
  min-height: 100vh;
  display: flex;
  flex-direction: column;
}

.app-header {
  background: var(--surface);
  border-bottom: 1px solid var(--line);
  flex-shrink: 0;
  box-shadow: var(--shadow-sm);
}

/* Та сама ширина, що й .app-content, — елементи шапки вирівняні з контентом */
.header-inner {
  display: flex;
  align-items: center;
  justify-content: space-between;
  width: 100%;
  max-width: 1160px;
  margin: 0 auto;
  padding: 0 32px;
  height: 64px;
}

.header-left {
  display: flex;
  align-items: center;
  gap: 36px;
  min-width: 0;
}

.brand {
  display: flex;
  align-items: center;
}

.brand-name {
  font-weight: 700;
  font-size: 15px;
  color: var(--accent);
  letter-spacing: -0.01em;
  white-space: nowrap;
}

.nav {
  display: flex;
  align-items: center;
  gap: 4px;
}

.nav-item {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px 14px;
  border-radius: 0;
  color: var(--muted);
  font-weight: 500;
  font-size: 14px;
  text-decoration: none;
  transition: all 0.15s ease;
}

.nav-item:hover {
  color: var(--accent);
  background: var(--app-bg);
}

.nav-item.router-link-active {
  background: var(--accent);
  color: #ffffff;
  font-weight: 600;
}

.header-right {
  display: flex;
  align-items: center;
  gap: 14px;
}

.user-chip {
  display: flex;
  align-items: center;
  gap: 10px;
}

.user-meta {
  display: flex;
  flex-direction: column;
  line-height: 1.25;
}

.user-name {
  font-size: 13px;
  font-weight: 600;
  color: var(--accent);
}

.user-role {
  font-size: 11.5px;
  color: var(--muted);
}

.header-divider {
  width: 1px;
  height: 22px;
  background: var(--line);
}

.logout-btn {
  display: flex;
  align-items: center;
  gap: 6px;
  background: none;
  border: none;
  color: var(--muted);
  font-family: inherit;
  font-size: 13.5px;
  font-weight: 500;
  cursor: pointer;
  padding: 6px 8px;
  border-radius: 0;
  transition: all 0.15s ease;
}

.logout-btn:hover {
  color: var(--accent);
  background: var(--app-bg);
}

.app-content {
  flex: 1;
  width: 100%;
  max-width: 1160px;
  margin: 0 auto;
  padding: 22px 32px;
  display: flex;
  flex-direction: column;
}

/* Мобільна шапка: бренд і користувач у першому ряду, навігація — окремим
   повним рядом з горизонтальним скролом (пункти не ховаємо). */
@media (max-width: 900px) {
  .header-inner {
    flex-wrap: wrap;
    height: auto;
    row-gap: 2px;
    padding: 10px 16px 0;
  }

  /* Розкриваємо обгортку, щоб бренд і меню стали прямими flex-дітьми
     шапки і меню могло зайняти власний повний ряд. */
  .header-left {
    display: contents;
  }

  .brand {
    order: 1;
  }

  .header-right {
    order: 2;
    min-width: 0;
    gap: 10px;
  }

  .user-chip {
    min-width: 0;
  }

  .user-name {
    display: block;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .user-role {
    display: none;
  }

  .nav {
    order: 3;
    flex-basis: 100%;
    overflow-x: auto;
    scrollbar-width: none;
    margin: 4px -16px 0;
    padding: 0 12px 8px;
  }

  .nav::-webkit-scrollbar {
    display: none;
  }

  .nav-item {
    padding: 9px 10px;
    font-size: 13.5px;
    flex-shrink: 0;
  }

  .app-content {
    padding: 16px;
  }
}
</style>
