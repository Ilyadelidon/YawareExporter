<script setup>
import { computed, ref, watch } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { useAuthStore } from '../stores/auth';

const auth = useAuthStore();
const router = useRouter();
const route = useRoute();

const roleLabel = computed(() => (auth.isAdmin ? 'Адміністратор' : 'Працівник'));

// Бокове меню на мобільному — шухляда поверх контенту; на десктопі завжди відкрите.
const mobileOpen = ref(false);

watch(() => route.fullPath, () => {
  mobileOpen.value = false;
});

async function handleLogout() {
  await auth.logout();
  router.push({ name: 'login' });
}
</script>

<template>
  <div class="app-shell" :class="{ 'is-menu-open': mobileOpen }">
    <div class="mobile-bar">
      <button class="burger" type="button" aria-label="Меню" @click="mobileOpen = !mobileOpen">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="18" x2="21" y2="18"></line></svg>
      </button>
      <span class="brand-name">TeamReporter</span>
    </div>

    <div class="scrim" @click="mobileOpen = false"></div>

    <aside class="sidebar">
      <div class="sidebar-head">
        <span class="brand-name">TeamReporter</span>
        <button class="sidebar-close" type="button" aria-label="Закрити меню" @click="mobileOpen = false">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
        </button>
      </div>

      <nav class="nav">
        <span class="nav-label">Огляд</span>
        <!-- Роут «/» префіксно матчиться з усіма сторінками — підсвічуємо лише точний збіг -->
        <router-link :to="{ name: 'reports' }" class="nav-item" active-class="nav-prefix-match" exact-active-class="router-link-active">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="12" width="4" height="8"></rect><rect x="10" y="7" width="4" height="13"></rect><rect x="17" y="3" width="4" height="17"></rect></svg>
          Звіти
        </router-link>
        <router-link :to="{ name: 'history' }" class="nav-item">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
          Статистика
        </router-link>
        <router-link :to="{ name: 'plans' }" class="nav-item">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"></line><line x1="8" y1="12" x2="21" y2="12"></line><line x1="8" y1="18" x2="21" y2="18"></line><polyline points="3 6 4 7 6 5"></polyline><polyline points="3 12 4 13 6 11"></polyline><line x1="3" y1="18" x2="6" y2="18"></line></svg>
          Плани
        </router-link>
        <router-link v-if="!auth.isAdmin" :to="{ name: 'integrations' }" class="nav-item">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path></svg>
          Інтеграції
        </router-link>

        <template v-if="auth.isAdmin">
          <span class="nav-label">Адміністрування</span>
          <router-link :to="{ name: 'timesheet' }" class="nav-item">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"></rect><line x1="3" y1="10" x2="21" y2="10"></line><line x1="9" y1="10" x2="9" y2="22"></line><line x1="15" y1="10" x2="15" y2="22"></line></svg>
            Табель
          </router-link>
          <router-link :to="{ name: 'employees' }" class="nav-item">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
            Працівники
          </router-link>
          <router-link :to="{ name: 'settings' }" class="nav-item">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
            Налаштування
          </router-link>
        </template>
      </nav>

      <div class="sidebar-foot">
        <div v-if="auth.user" class="user-chip">
          <span class="user-name">{{ auth.user.name }}</span>
          <span class="user-role">{{ roleLabel }}</span>
        </div>
        <button class="logout-btn" type="button" @click="handleLogout">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
          Вийти
        </button>
      </div>
    </aside>

    <main class="app-content">
      <router-view />
    </main>
  </div>
</template>

<style scoped>
.app-shell {
  min-height: 100vh;
  display: flex;
}

.brand-name {
  font-weight: 700;
  font-size: 15px;
  color: var(--accent);
  letter-spacing: -0.01em;
  white-space: nowrap;
}

/* ---- Бокове меню ---- */

/* На десктопі меню — звичайний елемент потоку (sticky), тож контент поруч
   займає всю решту ширини; на мобільному воно стає шухлядою (position: fixed). */
.sidebar {
  position: sticky;
  top: 0;
  align-self: flex-start;
  height: 100vh;
  width: 236px;
  flex-shrink: 0;
  display: flex;
  flex-direction: column;
  background: var(--surface);
  border-right: 1px solid var(--line);
  box-shadow: var(--shadow-sm);
  z-index: 40;
}

.sidebar-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  height: 64px;
  flex-shrink: 0;
  padding: 0 20px;
  border-bottom: 1px solid var(--line);
}

.sidebar-close {
  display: none;
  align-items: center;
  justify-content: center;
  background: none;
  border: none;
  color: var(--muted);
  cursor: pointer;
  padding: 6px;
}

.nav {
  display: flex;
  flex-direction: column;
  gap: 2px;
  padding: 14px 12px;
  flex: 1;
  overflow-y: auto;
}

.nav-label {
  font-size: 10.5px;
  font-weight: 700;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--muted-2);
  padding: 12px 8px 6px;
}

.nav-label:first-child {
  padding-top: 2px;
}

.nav-item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 9px 12px;
  color: var(--muted);
  font-weight: 500;
  font-size: 14px;
  text-decoration: none;
  border-left: 2px solid transparent;
  transition: all 0.15s ease;
}

.nav-item:hover {
  color: var(--accent);
  background: var(--app-bg);
}

.nav-item.router-link-active {
  background: var(--accent);
  border-left-color: var(--accent);
  color: #ffffff;
  font-weight: 600;
}

.sidebar-foot {
  flex-shrink: 0;
  border-top: 1px solid var(--line);
  padding: 12px 14px;
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.user-chip {
  display: flex;
  flex-direction: column;
  line-height: 1.25;
  min-width: 0;
}

.user-name {
  font-size: 13px;
  font-weight: 600;
  color: var(--accent);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.user-role {
  font-size: 11.5px;
  color: var(--muted);
}

.logout-btn {
  display: flex;
  align-items: center;
  gap: 8px;
  background: none;
  border: none;
  color: var(--muted);
  font-family: inherit;
  font-size: 13.5px;
  font-weight: 500;
  cursor: pointer;
  padding: 8px 6px;
  transition: all 0.15s ease;
}

.logout-btn:hover {
  color: var(--accent);
  background: var(--app-bg);
}

/* ---- Контент ---- */

.app-content {
  flex: 1;
  min-width: 0;
  /* Заповнюємо решту ширини поруч із меню; на дуже широких екранах
     обмежуємо рядок і центруємо його, щоб поля були з обох боків. */
  max-width: 1500px;
  margin: 0 auto;
  padding: 22px 32px;
  display: flex;
  flex-direction: column;
}

/* Мобільна панель зі шторкою */

.mobile-bar {
  display: none;
  align-items: center;
  gap: 12px;
  height: 56px;
  padding: 0 16px;
  background: var(--surface);
  border-bottom: 1px solid var(--line);
  box-shadow: var(--shadow-sm);
  position: sticky;
  top: 0;
  z-index: 30;
}

.burger {
  display: flex;
  align-items: center;
  justify-content: center;
  background: none;
  border: none;
  color: var(--accent);
  cursor: pointer;
  padding: 6px;
  margin-left: -6px;
}

.scrim {
  display: none;
  position: fixed;
  inset: 0;
  background: rgba(17, 17, 17, 0.35);
  z-index: 35;
}

@media (max-width: 900px) {
  .app-shell {
    flex-direction: column;
  }

  .mobile-bar {
    display: flex;
  }

  .sidebar {
    position: fixed;
    top: 0;
    bottom: 0;
    left: 0;
    height: auto;
    z-index: 40;
    transform: translateX(-100%);
    transition: transform 0.2s ease;
    box-shadow: none;
  }

  .sidebar-close {
    display: flex;
  }

  .is-menu-open .sidebar {
    transform: translateX(0);
    box-shadow: 0 8px 28px rgba(17, 17, 17, 0.18);
  }

  .is-menu-open .scrim {
    display: block;
  }

  .app-content {
    width: 100%;
    padding: 16px;
  }
}
</style>
