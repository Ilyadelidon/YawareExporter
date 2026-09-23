<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import client from '../api/client';
import { useAuthStore } from '../stores/auth';
import { useIntegrationLinksStore } from '../stores/integrations';

const auth = useAuthStore();
const links = useIntegrationLinksStore();
const router = useRouter();
const route = useRoute();

const roleLabel = computed(() => (auth.isAdmin ? 'Адміністратор' : 'Працівник'));

const initials = computed(() => {
  const parts = (auth.user?.name || '').trim().split(/\s+/).filter(Boolean);
  return parts.slice(0, 2).map((p) => p[0].toUpperCase()).join('') || '—';
});

// Бокове меню на мобільному — шухляда поверх контенту; на десктопі завжди відкрите.
const mobileOpen = ref(false);

watch(() => route.fullPath, () => {
  mobileOpen.value = false;
  loadToday();
});

// Кнопки переходу показуємо лише для підключеного: порожній блок у меню
// не потрібен.
const hasShortcuts = computed(() => Boolean(links.google || links.tracker));

const trackerIcon = computed(() => (links.tracker?.provider === 'bitrix' ? 'bitrix' : 'trello'));

// ---- Картка «Звіт за сьогодні» (лише працівнику: в адміна звітів багато) ----

const now = new Date();
const pad = (n) => String(n).padStart(2, '0');
const todayIso = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
const todayShort = `${pad(now.getDate())}.${pad(now.getMonth() + 1)}`;

const todayReport = ref(null);
const todayLoaded = ref(false);
let todayTimer = null;

const TODAY_STATES = {
  none: { text: 'Ще не сформовано', tone: 'warn' },
  pending: { text: 'Формується…', tone: 'info' },
  processing: { text: 'Формується…', tone: 'info' },
  completed: { text: 'Сформовано', tone: 'ok' },
  failed: { text: 'Не вдалося сформувати', tone: 'error' },
  blocked: { text: 'Потрібна ваша дія', tone: 'warn' },
};

const todayState = computed(() => TODAY_STATES[todayReport.value?.status || 'none'] || TODAY_STATES.none);

async function loadToday() {
  if (auth.isAdmin) {
    return;
  }
  try {
    const { data } = await client.get('/reports', { params: { date_from: todayIso, date_to: todayIso } });
    todayReport.value = data.data?.[0] || null;
  } catch {
    // Картка допоміжна: при збої лишаємо попередній стан.
  } finally {
    todayLoaded.value = true;
  }
}

onMounted(() => {
  links.$reset();
  links.load();
  loadToday();
  // Звіт формують на сторінці звітів без переходу — підтягуємо статус,
  // поки він не став остаточним.
  todayTimer = setInterval(() => {
    if (todayReport.value?.status !== 'completed') {
      loadToday();
    }
  }, 30000);
});

onBeforeUnmount(() => clearInterval(todayTimer));

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
      <span class="brand-title">Team<span>Reporter</span></span>
    </div>

    <div class="scrim" @click="mobileOpen = false"></div>

    <aside class="sidebar" aria-label="Головне меню">
      <div class="sidebar-head">
        <div class="brand-mark">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#FFFFFF" stroke-width="2.4" stroke-linecap="square"><path d="M5 20v-7"></path><path d="M10 20V5"></path><path d="M15 20v-4"></path><path d="M20 20V9"></path></svg>
        </div>
        <div class="brand-text">
          <span class="brand-title">Team<span>Reporter</span></span>
          <span class="brand-sub">Щоденні звіти команди</span>
        </div>
        <button class="sidebar-close" type="button" aria-label="Закрити меню" @click="mobileOpen = false">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
        </button>
      </div>

      <nav class="nav" aria-label="Розділи">
        <div class="nav-group">
          <span class="nav-label">Робота</span>
          <!-- Роут «/» префіксно матчиться з усіма сторінками — підсвічуємо лише точний збіг -->
          <router-link :to="{ name: 'reports' }" class="nav-item" active-class="nav-prefix-match" exact-active-class="router-link-active">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="square"><path d="M3 21h18"></path><path d="M6 17v-6"></path><path d="M11 17V5"></path><path d="M16 17v-9"></path></svg>
            Звіти
          </router-link>
          <router-link :to="{ name: 'history' }" class="nav-item">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="square"><path d="M2 12h4l3-8 5 16 3-8h5"></path></svg>
            Статистика
          </router-link>
          <router-link :to="{ name: 'plans' }" class="nav-item">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="square"><path d="M11 6h10"></path><path d="M11 12h10"></path><path d="M11 18h10"></path><path d="M3 6l2 2 3-3"></path><path d="M3 12l2 2 3-3"></path><path d="M4 18h3"></path></svg>
            Плани
          </router-link>
        </div>

        <div v-if="!auth.isAdmin" class="nav-group">
          <span class="nav-label">Налаштування</span>
          <router-link :to="{ name: 'integrations' }" class="nav-item">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="square"><path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1"></path><path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"></path></svg>
            Інтеграції
          </router-link>
        </div>

        <div v-else class="nav-group">
          <span class="nav-label">Адміністрування</span>
          <router-link :to="{ name: 'timesheet' }" class="nav-item">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="square"><path d="M3 4h18v17H3z"></path><path d="M3 10h18"></path><path d="M9 10v11"></path><path d="M15 10v11"></path></svg>
            Табель
          </router-link>
          <router-link :to="{ name: 'employees' }" class="nav-item">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="square"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M22 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
            Працівники
          </router-link>
          <router-link :to="{ name: 'settings' }" class="nav-item">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="square"><path d="M4 6h10"></path><path d="M18 6h2"></path><path d="M4 12h4"></path><path d="M12 12h8"></path><path d="M4 18h12"></path><path d="M20 18h0"></path><circle cx="16" cy="6" r="2"></circle><circle cx="10" cy="12" r="2"></circle><circle cx="18" cy="18" r="2"></circle></svg>
            Налаштування
          </router-link>
        </div>
      </nav>

      <div class="sidebar-foot">
        <div v-if="hasShortcuts" class="nav-group services">
          <span class="nav-label">Підключені сервіси</span>
          <a v-if="links.google" class="nav-item service" :href="links.google.url" target="_blank" rel="noopener" aria-label="Відкрити Google Таблицю">
            <span class="service-logo google">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#FFFFFF" stroke-width="2.2" stroke-linecap="square"><path d="M4 4h16v16H4z"></path><path d="M4 10h16"></path><path d="M4 15h16"></path><path d="M10 10v10"></path></svg>
            </span>
            <span class="service-text">Google Таблиця</span>
            <span class="service-dot" title="Підключено"></span>
            <svg class="service-out" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="square"><path d="M7 17L17 7"></path><path d="M9 7h8v8"></path></svg>
          </a>
          <a
            v-if="links.tracker"
            class="nav-item service"
            :href="links.tracker.url"
            target="_blank"
            rel="noopener"
            :title="links.tracker.name || links.tracker.label"
            :aria-label="`Відкрити ${links.tracker.label}`"
          >
            <span v-if="trackerIcon === 'trello'" class="service-logo trello">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="#FFFFFF"><path d="M5 5h6v13H5z"></path><path d="M13 5h6v8h-6z"></path></svg>
            </span>
            <span v-else class="service-logo bitrix">
              <svg width="16" height="16" viewBox="2 3 12 10" aria-hidden="true"><path fill="#fff" d="M7.81529 11.2372L7.81241 10.1612L4.34898 10.1692C4.82187 8.40197 7.74699 8.0089 7.74174 6.04966C7.73893 5.00103 6.96059 4.23022 5.31836 4.23401C4.30308 4.23636 3.40822 4.51446 2.81202 4.79186L3.17299 5.78453C3.70961 5.535 4.33598 5.31266 5.11226 5.31086C5.70957 5.30948 6.27715 5.55646 6.27885 6.19105C6.2827 7.62598 2.84957 7.74419 2.5603 11.2493L7.81529 11.2372ZM7.96004 9.52589L11.3338 9.51809L11.3384 11.229L12.682 11.2259L12.6774 9.51499L13.812 9.51237L13.8092 8.46374L12.6746 8.46636L12.6632 4.21704L11.6779 4.21932L7.95783 8.69815L7.96004 9.52589ZM9.47993 8.52888L11.3844 6.15118C11.385 6.34433 11.3269 6.95166 11.3284 7.50337L11.3311 8.49686L10.4355 8.49893C10.1668 8.49955 9.65927 8.52846 9.48029 8.52888L9.47993 8.52888Z"/></svg>
            </span>
            <span class="service-text">{{ links.tracker.label }}</span>
            <span class="service-dot" title="Підключено"></span>
            <svg class="service-out" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="square"><path d="M7 17L17 7"></path><path d="M9 7h8v8"></path></svg>
          </a>
        </div>

        <router-link
          v-if="!auth.isAdmin && todayLoaded"
          :to="{ name: 'reports' }"
          class="today-card"
          :class="`tone-${todayState.tone}`"
        >
          <span class="today-icon">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="square"><path d="M4 6h16v14H4z"></path><path d="M4 10h16"></path><path d="M8 3v4"></path><path d="M16 3v4"></path></svg>
            <span class="today-badge"></span>
          </span>
          <span class="today-text">
            <span class="today-title">Звіт за {{ todayShort }}</span>
            <span class="today-status">{{ todayState.text }}</span>
          </span>
        </router-link>

        <div class="user-row">
          <span class="avatar">{{ initials }}</span>
          <span v-if="auth.user" class="user-chip">
            <span class="user-name">{{ auth.user.name }}</span>
            <span class="user-role">{{ roleLabel }}</span>
          </span>
          <button class="logout-btn" type="button" aria-label="Вийти з акаунта" title="Вийти" @click="handleLogout">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="square"><path d="M9 4H5v16h4"></path><path d="M14 8l4 4-4 4"></path><path d="M18 12H9"></path></svg>
          </button>
        </div>
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

  /* Палітра бокового меню (тема «soft» з макета) */
  --sb-bg: #f7f7f7;
  --sb-border: #DCE7E4;
  --sb-fg1: #12201D;
  --sb-fg2: #445753;
  --sb-fg3: #5F726D;
  --sb-act-bg: #FFFFFF;
  --sb-act-fg: #0E7466;
  --sb-accent: #14937F;
  --sb-ok: #16A34A;
  --sb-warn: #B45309;
  --sb-warn-bg: #FEF3E2;
  --sb-info: #0E7466;
  --sb-info-bg: #DDF0EB;
  --sb-error: #B42318;
  --sb-error-bg: #FDECEA;
  --sb-avatar-bg: #DDF0EB;
}

.brand-title {
  font-family: 'Unbounded', sans-serif;
  font-size: 14px;
  font-weight: 600;
  letter-spacing: -0.01em;
  color: var(--sb-fg1);
  white-space: nowrap;
}

.brand-title span {
  color: var(--sb-accent);
}

/* ---- Бокове меню ---- */

/* На десктопі меню — звичайний елемент потоку (sticky), тож контент поруч
   займає всю решту ширини; на мобільному воно стає шухлядою (position: fixed). */
.sidebar {
  position: sticky;
  top: 0;
  align-self: flex-start;
  height: 100vh;
  width: 264px;
  flex-shrink: 0;
  display: flex;
  flex-direction: column;
  background: var(--sb-bg);
  border-right: 1px solid var(--sb-border);
  color: var(--sb-fg2);
  font-family: 'Onest', system-ui, sans-serif;
  z-index: 40;
}

.sidebar-head {
  display: flex;
  align-items: center;
  gap: 12px;
  height: 72px;
  flex-shrink: 0;
  padding: 0 20px;
  border-bottom: 1px solid var(--sb-border);
}

.brand-mark {
  width: 34px;
  height: 34px;
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--sb-accent);
}

.brand-text {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.brand-sub {
  font-size: 11px;
  line-height: 1.3;
  color: var(--sb-fg3);
}

.sidebar-close {
  display: none;
  align-items: center;
  justify-content: center;
  background: none;
  border: none;
  color: var(--sb-fg3);
  cursor: pointer;
  padding: 6px;
}

.nav {
  display: flex;
  flex-direction: column;
  gap: 24px;
  padding: 20px 12px 12px;
  flex: 1;
  overflow-y: auto;
}

.nav-group {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.nav-label {
  font-size: 11px;
  font-weight: 600;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--sb-fg3);
  padding: 0 12px 8px;
}

.nav-item {
  position: relative;
  display: flex;
  align-items: center;
  gap: 12px;
  height: 40px;
  padding: 0 12px;
  color: var(--sb-fg2);
  font-size: 14px;
  font-weight: 500;
  text-decoration: none;
  transition: box-shadow 0.12s ease, color 0.12s ease;
}

.nav-item svg {
  flex-shrink: 0;
}

.nav-item:hover {
  box-shadow: inset 0 0 0 999px rgba(127, 140, 138, 0.12);
}

.nav-item:focus-visible,
.logout-btn:focus-visible,
.today-card:focus-visible {
  outline: 2px solid #2DD4BF;
  outline-offset: -2px;
}

/* Активний пункт — біла плашка з акцентною смужкою зліва */
.nav-item.router-link-active {
  background: var(--sb-act-bg);
  color: var(--sb-act-fg);
  font-weight: 600;
}

.nav-item.router-link-active::before {
  content: '';
  position: absolute;
  left: 0;
  top: 0;
  bottom: 0;
  width: 3px;
  background: var(--sb-accent);
}

.sidebar-foot {
  flex-shrink: 0;
  display: flex;
  flex-direction: column;
}

/* Переходи в підключені сервіси */
.services {
  padding: 0 12px 12px;
}

.service {
  font-size: 13px;
}

/* Фірмові знаки лишаються у своїх кольорах */
.service-logo {
  width: 24px;
  height: 24px;
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: center;
}

.service-logo.google {
  background: #188038;
}

.service-logo.trello {
  background: #0C66E4;
}

.service-logo.bitrix {
  background: #006DFF;
}

.service-text {
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.service-dot {
  width: 7px;
  height: 7px;
  flex-shrink: 0;
  border-radius: 50% !important;
  background: var(--sb-ok);
}

.service-out {
  color: var(--sb-fg3);
}

/* Картка стану сьогоднішнього звіту */
.today-card {
  margin: 0 12px 12px;
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px;
  background: #FFFFFF;
  border: 1px solid var(--sb-border);
  text-decoration: none;
  --tone: var(--sb-warn);
  --tone-bg: var(--sb-warn-bg);
}

.today-card.tone-ok {
  --tone: var(--sb-ok);
  --tone-bg: var(--sb-info-bg);
}

.today-card.tone-info {
  --tone: var(--sb-info);
  --tone-bg: var(--sb-info-bg);
}

.today-card.tone-error {
  --tone: var(--sb-error);
  --tone-bg: var(--sb-error-bg);
}

.today-icon {
  position: relative;
  width: 32px;
  height: 32px;
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--tone-bg);
  color: var(--tone);
}

.today-badge {
  position: absolute;
  top: -3px;
  right: -3px;
  width: 8px;
  height: 8px;
  border-radius: 50% !important;
  background: var(--tone);
  border: 2px solid var(--sb-bg);
  box-sizing: content-box;
}

.today-card.tone-ok .today-badge {
  display: none;
}

.today-text {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
}

.today-title {
  font-size: 13px;
  font-weight: 600;
  color: var(--sb-fg1);
}

.today-status {
  font-size: 12px;
  color: var(--tone);
}

/* Працівник і вихід */
.user-row {
  height: 72px;
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 0 16px;
  border-top: 1px solid var(--sb-border);
}

.avatar {
  width: 36px;
  height: 36px;
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--sb-avatar-bg);
  color: var(--sb-act-fg);
  font-size: 13px;
  font-weight: 700;
  letter-spacing: 0.02em;
}

.user-chip {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 2px;
  line-height: 1.3;
}

.user-name {
  font-size: 13px;
  font-weight: 600;
  color: var(--sb-fg1);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.user-role {
  font-size: 12px;
  color: var(--sb-fg3);
}

.logout-btn {
  width: 40px;
  height: 40px;
  flex-shrink: 0;
  margin-left: auto;
  display: flex;
  align-items: center;
  justify-content: center;
  border: 0;
  padding: 0;
  background: transparent;
  color: var(--sb-fg3);
  cursor: pointer;
  transition: color 0.12s ease;
}

.logout-btn:hover {
  color: var(--sb-error);
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
  background: var(--sb-bg);
  border-bottom: 1px solid var(--sb-border);
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
  color: var(--sb-fg1);
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
