<script setup>
// Кнопка вивантаження планів у Google Таблицю з меню таблиці.
import { computed, ref } from 'vue';
import Popover from 'primevue/popover';
import UiIcon from '../UiIcon.vue';
import '../../styles/plans-ui.css';

const props = defineProps({
  google: { type: Object, default: null },
  exporting: { type: Boolean, default: false },
});

const emit = defineEmits(['export', 'connect']);

const menu = ref(null);

// Підпис кнопки каже, що станеться по кліку: без привʼязаної таблиці це не
// експорт, а вікно підключення.
const label = computed(() => {
  if (props.exporting) {
    return props.google?.export?.status === 'queued' ? 'У черзі…' : 'Вивантаження…';
  }

  return props.google?.spreadsheet_url ? 'Вивантажити в Google' : 'Підключити таблицю';
});

const hint = computed(() => {
  if (!props.google?.spreadsheet_url) return 'Обрати Google Таблицю для планів';

  return `Оновити плани в таблиці «${props.google.spreadsheet_title || 'Google Таблиця'}» просто зараз`;
});

// Щоранку плани вивантажує планувальник, тож кнопка потрібна лише для
// оновлення серед дня. Без цього рядка автоматичний прогін ніяк не видно —
// і незрозуміло, чи таблиця взагалі свіжа.
const lastExportLabel = computed(() => {
  const exported = props.google?.export;
  if (exported?.status !== 'done' || !exported.at) return 'Оновлюється щодня автоматично о 06:40';

  const stamp = new Date(exported.at).toLocaleString('uk-UA', {
    day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit',
  });

  return `Оновлюється щодня о 06:40. Востаннє — ${stamp}`;
});

function connect(event) {
  menu.value?.hide(event);
  emit('connect');
}
</script>

<template>
  <div class="plan-split">
    <button
      type="button"
      class="plan-btn"
      :disabled="exporting"
      :title="hint"
      @click="emit('export')"
    >
      <UiIcon v-if="exporting" name="spinner" :size="14" :stroke-width="2.4" class="btn-icon" />
      <UiIcon v-else name="upload" :size="14" :stroke-width="2.2" class="btn-icon" />
      {{ label }}
    </button>
    <button
      v-if="google?.spreadsheet_url"
      type="button"
      class="plan-btn is-caret"
      title="Інші дії з Google Таблицею"
      aria-label="Інші дії з Google Таблицею"
      @click="menu.toggle($event)"
    >
      <UiIcon name="chevron-down" :size="13" :stroke-width="2.4" />
    </button>
  </div>

  <Popover ref="menu">
    <div class="plan-menu">
      <a
        v-if="google?.spreadsheet_url"
        class="plan-menu-item"
        :href="google.spreadsheet_url"
        target="_blank"
        rel="noopener"
        @click="menu.hide($event)"
      >
        <UiIcon name="table" :size="15" />
        <span>
          Відкрити таблицю
          <small v-if="google.spreadsheet_title">{{ google.spreadsheet_title }}</small>
        </span>
      </a>
      <button type="button" class="plan-menu-item" @click="connect">
        <UiIcon name="link" :size="15" />
        <span>Підключити іншу таблицю</span>
      </button>
      <p class="plan-menu-note">{{ lastExportLabel }}</p>
    </div>
  </Popover>
</template>
