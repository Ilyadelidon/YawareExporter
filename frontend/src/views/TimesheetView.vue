<script setup>
// Табель (лише адмін): матриця «працівник × дні місяця», у клітинках —
// робочий час (без непродуктивного) із історичної БД, без норм і підсвіток.
import { onMounted, ref, watch } from 'vue';
import DatePicker from 'primevue/datepicker';
import Message from 'primevue/message';
import { useTimesheet } from '../composables/useTimesheet';
import TimesheetTable from '../components/timesheet/TimesheetTable.vue';
import UiIcon from '../components/UiIcon.vue';

const selectedMonth = ref(new Date());
const { rows, days, loading, error, load } = useTimesheet();

watch(selectedMonth, (month) => {
  if (month) load(month);
});

onMounted(() => load(selectedMonth.value));
</script>

<template>
  <div class="timesheet-page">
    <div class="page-head">
      <div class="page-head-info">
        <div class="page-head-icon">
          <UiIcon name="timesheet" :size="20" color="#149d8d" />
        </div>
        <div>
          <div class="page-head-title">Табель</div>
          <div class="page-head-subtitle">Робочий час по працівниках за місяць (без непродуктивного)</div>
        </div>
      </div>
      <div class="page-head-actions">
        <div class="field-pill">
          <UiIcon name="calendar" :size="15" color="#149d8d" />
          <DatePicker v-model="selectedMonth" view="month" date-format="mm.yy" :manual-input="false" />
        </div>
      </div>
    </div>

    <Message v-if="error" severity="error" :closable="true" class="page-message" @close="error = ''">
      {{ error }}
    </Message>

    <div v-if="loading" class="skeleton sheet-skeleton"></div>

    <div v-else-if="!rows.length && !error" class="panel empty-panel">
      Працівників немає — додайте їх на сторінці «Працівники».
    </div>

    <div v-else-if="rows.length" class="panel sheet-panel">
      <TimesheetTable :rows="rows" :days="days" />
    </div>
  </div>
</template>

<style scoped>
.timesheet-page {
  display: flex;
  flex-direction: column;
}

.page-message {
  margin-top: 14px;
}

.sheet-skeleton {
  height: 200px;
  margin-top: 16px;
}

.empty-panel {
  margin-top: 16px;
  padding: 20px 16px;
  font-size: 13.5px;
  color: var(--muted);
  text-align: center;
}

.sheet-panel {
  margin-top: 16px;
  overflow-x: auto;
  animation: fadeUp 0.35s ease both;
}
</style>
