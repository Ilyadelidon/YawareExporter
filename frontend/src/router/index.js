import { createRouter, createWebHistory } from 'vue-router';
import { useAuthStore } from '../stores/auth';

const routes = [
  {
    path: '/login',
    name: 'login',
    component: () => import('../views/LoginView.vue'),
    meta: { guestOnly: true },
  },
  {
    path: '/',
    component: () => import('../layouts/AppLayout.vue'),
    meta: { requiresAuth: true },
    children: [
      {
        path: '',
        name: 'reports',
        component: () => import('../views/ReportsView.vue'),
      },
      {
        path: 'history',
        name: 'history',
        component: () => import('../views/HistoryView.vue'),
      },
      {
        path: 'plans',
        name: 'plans',
        component: () => import('../views/PlansView.vue'),
      },
      {
        // Персональні підключення працівника; адміністратор налаштовує
        // командні інтеграції у «Налаштуваннях».
        path: 'integrations',
        name: 'integrations',
        component: () => import('../views/IntegrationsView.vue'),
        meta: { employeeOnly: true },
      },
      {
        path: 'timesheet',
        name: 'timesheet',
        component: () => import('../views/TimesheetView.vue'),
        meta: { adminOnly: true },
      },
      {
        path: 'employees',
        name: 'employees',
        component: () => import('../views/EmployeesView.vue'),
        meta: { adminOnly: true },
      },
      {
        path: 'settings',
        name: 'settings',
        component: () => import('../views/SettingsView.vue'),
        meta: { adminOnly: true },
      },
    ],
  },
  {
    // Popup-сторінка, на яку Trello повертає токен у fragment; поза AppLayout.
    path: '/trello/callback',
    name: 'trello-callback',
    component: () => import('../views/TrelloCallbackView.vue'),
    meta: { requiresAuth: true },
  },
];

const router = createRouter({
  history: createWebHistory(),
  routes,
});

router.beforeEach(async (to) => {
  const auth = useAuthStore();

  if (auth.isAuthenticated && !auth.user) {
    try {
      await auth.fetchUser();
    } catch {
      // токен протух — інтерсептор уже почистив localStorage
    }
  }

  if (to.meta.requiresAuth && !auth.isAuthenticated) {
    return { name: 'login' };
  }

  if (to.meta.guestOnly && auth.isAuthenticated) {
    return { name: 'reports' };
  }

  if (to.meta.adminOnly && !auth.isAdmin) {
    return { name: 'reports' };
  }

  if (to.meta.employeeOnly && auth.isAdmin) {
    return { name: 'reports' };
  }

  return true;
});

export default router;
