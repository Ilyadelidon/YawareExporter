<script setup>
import { computed } from 'vue';
import IntegrationsLink from '../IntegrationsLink.vue';
import UiIcon from '../UiIcon.vue';

// Порожній екран сторінки звітів, коли показати звіт нема чого.
const props = defineProps({
  // select-employee | failed | blocked | viewing-other | integrations | not-generated
  variant: { type: String, required: true },
  trackerLabel: { type: String, default: '' },
  integrationsHint: { type: String, default: '' },
});

const content = computed(() => ({
  'select-employee': {
    title: 'Оберіть працівника',
    text: 'Щоб переглянути або сформувати звіт, оберіть працівника у верхній панелі',
  },
  failed: {
    title: 'Не вдалося сформувати звіт',
    text: 'Спробуйте натиснути «Сформувати звіт» ще раз або перевірте повідомлення про помилку вище',
  },
  blocked: {
    title: 'Є час поза тасками',
    text: `Додайте у ${props.trackerLabel} таски з часом початку й завершення так, щоб вони покрили весь робочий день, і натисніть «Сформувати звіт» ще раз`,
  },
  'viewing-other': {
    title: 'Звіту за цю дату немає',
    text: 'Звіти формують самі працівники — тут можна лише переглядати вже сформовані',
  },
  integrations: {
    title: 'Підключіть інтеграції',
    text: props.integrationsHint,
    link: true,
  },
  'not-generated': {
    title: 'Звіт сформується автоматично',
    text: 'Ми зберемо вашу активність, відпрацьований час і виконані задачі в один звіт.',
  },
}[props.variant]));
</script>

<template>
  <div class="empty-state">
    <div class="empty-state-icon">
      <UiIcon name="calendar" :size="24" :stroke-width="1.8" color="var(--muted-2)" />
    </div>
    <div class="empty-state-title">{{ content.title }}</div>
    <div class="empty-state-text">
      {{ content.text }}
      <IntegrationsLink v-if="content.link" />
    </div>
  </div>
</template>

<style scoped>
.empty-state {
  margin-top: 20px;
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  text-align: center;
  padding: 60px 20px;
  border: 1.5px dashed var(--line);
  border-radius: 0;
  background: var(--surface);
  box-shadow: var(--shadow-sm);
  animation: fadeUp 0.35s ease both;
}

.empty-state-icon {
  width: 52px;
  height: 52px;
  border-radius: 0;
  background: var(--app-bg);
  display: flex;
  align-items: center;
  justify-content: center;
  margin-bottom: 16px;
}

.empty-state-title {
  font-size: 15px;
  font-weight: 600;
  color: var(--ink);
  margin-bottom: 6px;
}

.empty-state-text {
  font-size: 13.5px;
  color: var(--muted);
  max-width: 340px;
}
</style>
