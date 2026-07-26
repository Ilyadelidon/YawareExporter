import { createApp } from 'vue';
import { createPinia } from 'pinia';
import PrimeVue from 'primevue/config';
import Aura from '@primevue/themes/aura';
import { definePreset } from '@primevue/themes';
import 'primeicons/primeicons.css';
import './style.css';
import App from './App.vue';
import router from './router';

const YawarePreset = definePreset(Aura, {
  primitive: {
    borderRadius: {
      none: '0',
      xs: '0',
      sm: '0',
      md: '0',
      lg: '0',
      xl: '0',
    },
  },
  semantic: {
    primary: {
      50: '#eefaf8',
      100: '#d5f2ee',
      200: '#abe4dd',
      300: '#79d0c6',
      400: '#3fb6a9',
      500: '#149d8d',
      600: '#118779',
      700: '#0e6e63',
      800: '#0b564e',
      900: '#08443e',
      950: '#052b27',
    },
  },
});

const app = createApp(App);

app.use(createPinia());
app.use(router);
app.use(PrimeVue, {
  theme: {
    preset: YawarePreset,
    options: {
      darkModeSelector: false,
    },
  },
  locale: {
    firstDayOfWeek: 1,
    dayNames: ['неділя', 'понеділок', 'вівторок', 'середа', 'четвер', 'пʼятниця', 'субота'],
    dayNamesShort: ['нд', 'пн', 'вт', 'ср', 'чт', 'пт', 'сб'],
    dayNamesMin: ['Нд', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб'],
    monthNames: ['Січень', 'Лютий', 'Березень', 'Квітень', 'Травень', 'Червень', 'Липень', 'Серпень', 'Вересень', 'Жовтень', 'Листопад', 'Грудень'],
    monthNamesShort: ['Січ', 'Лют', 'Бер', 'Кві', 'Тра', 'Чер', 'Лип', 'Сер', 'Вер', 'Жов', 'Лис', 'Гру'],
    today: 'Сьогодні',
    clear: 'Очистити',
    weekHeader: 'Тж',
    dateFormat: 'dd.mm.yy',
    chooseDate: 'Оберіть дату',
    prevMonth: 'Попередній місяць',
    nextMonth: 'Наступний місяць',
  },
});

app.mount('#app');
