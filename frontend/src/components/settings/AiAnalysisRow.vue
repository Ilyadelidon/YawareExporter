<script setup>
import { onMounted } from 'vue';
import { useAiAnalysisSettings } from '../../composables/settings/useAiAnalysisSettings';
import IntegrationRow from '../integrations/IntegrationRow.vue';
import UiIcon from '../UiIcon.vue';
import SettingsSection from './SettingsSection.vue';

// Автоматичний AI-розбір дня після кожного звіту: вмикається й вимикається
// одним натиском, без розгорнутої панелі.
const ai = useAiAnalysisSettings();
const { loading, notice, enabled, configured, saving, status, meta } = ai;

onMounted(ai.load);
</script>

<template>
  <SettingsSection id="sec-ai" title="AI-розбір дня" :loading="loading">
    <template #description>
      Після кожного звіту модель розбирає день працівника. Вимкніть, щоб не витрачати запити —
      розбір окремого дня й далі можна запустити вручну.
    </template>

    <IntegrationRow
      id="ai-auto-analysis"
      name="Автоматичний розбір"
      :meta="meta"
      :status="status"
      :notice="notice"
    >
      <template #icon><UiIcon name="checklist" :size="18" color="#149d8d" /></template>

      <template #action>
        <button
          type="button"
          class="btn"
          :class="enabled ? 'btn-secondary' : 'btn-primary'"
          :disabled="saving || !configured"
          @click="ai.toggle"
        >
          {{ saving ? 'Зберігаємо…' : enabled ? 'Вимкнути' : 'Увімкнути' }}
        </button>
      </template>
    </IntegrationRow>
  </SettingsSection>
</template>
