<script setup>
// Працівники (лише адмін): довідник для звітів. Посада правиться прямо в
// таблиці, у розгорнутому рядку — інтеграції працівника й пам'ять AI.
import { onMounted, ref } from 'vue';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import Message from 'primevue/message';
import { useEmployees } from '../composables/useEmployees';
import CellInput from '../components/employees/CellInput.vue';
import EmployeeDetails from '../components/employees/EmployeeDetails.vue';
import PlanConfirmDialog from '../components/plans/PlanConfirmDialog.vue';
import UiIcon from '../components/UiIcon.vue';

const { employees, loading, error, savingId, load, savePosition, dismiss, reinstate } = useEmployees();

const expandedRows = ref({});

// Звільнення відкликає доступ одразу й стирає пароль Yaware, тож спершу
// питаємо підтвердження. Поновити можна, історія лишається.
// Ціль лишається після закриття, щоб заголовок не зникав під час анімації.
const dismissing = ref(null);
const dismissVisible = ref(false);

function askDismiss(employee) {
  dismissing.value = employee;
  dismissVisible.value = true;
}

async function confirmDismiss() {
  await dismiss(dismissing.value);
  // Помилку показує сторінка, тож діалог закриваємо в будь-якому разі.
  dismissVisible.value = false;
}

onMounted(load);
</script>

<template>
  <div class="employees-page">
    <div class="page-head">
      <div class="page-head-info">
        <div class="page-head-icon">
          <UiIcon name="users" :size="20" color="#149d8d" />
        </div>
        <div>
          <div class="page-head-title">Працівники</div>
          <div class="page-head-subtitle">Список працівників для формування звітів</div>
        </div>
      </div>
    </div>

    <Message v-if="error" severity="error" :closable="true" class="page-message" @close="error = ''">
      {{ error }}
    </Message>

    <div class="panel table-panel">
      <DataTable v-model:expanded-rows="expandedRows" :value="employees" :loading="loading" data-key="id">
        <template #empty>
          <div class="table-empty">Працівників поки немає.</div>
        </template>
        <template #expansion="{ data }">
          <EmployeeDetails :employee="data" />
        </template>
        <Column expander style="width: 40px" />
        <Column field="name" header="Імʼя" sortable />
        <Column field="position" header="Посада" sortable>
          <template #body="{ data }">
            <CellInput
              :value="data.position"
              placeholder="Не вказано"
              :disabled="savingId === data.id"
              @commit="savePosition(data, $event)"
            />
          </template>
        </Column>
        <Column field="email" header="Пошта" sortable />
        <Column field="yaware_id" header="Yaware ID" />
        <Column header="Статус">
          <template #body="{ data }">
            <span v-if="data.dismissed_at" class="status-dismissed">Звільнений</span>
            <span v-else>Працює</span>
          </template>
        </Column>
        <Column header="" style="width: 170px">
          <template #body="{ data }">
            <button
              v-if="data.dismissed_at"
              class="row-btn is-dim"
              type="button"
              :disabled="savingId === data.id"
              @click="reinstate(data)"
            >Поновити</button>
            <button
              v-else
              class="row-btn"
              type="button"
              :disabled="savingId === data.id"
              @click="askDismiss(data)"
            >Видалити з системи</button>
          </template>
        </Column>
      </DataTable>
    </div>

    <PlanConfirmDialog
      v-model:visible="dismissVisible"
      :title="`Видалити ${dismissing?.name ?? ''} з системи?`"
      confirm-label="Видалити з системи"
      :busy="savingId === dismissing?.id"
      @confirm="confirmDismiss"
    >
      Доступ закриється одразу, і зайти знову не вийде — навіть поки людина лишається в Yaware.
      Звіти, активності й Табель за минулі дні збережуться; поновити можна будь-коли.
    </PlanConfirmDialog>
  </div>
</template>

<style scoped>
.employees-page {
  display: flex;
  flex-direction: column;
}

.table-panel {
  margin-top: 16px;
  overflow-x: auto;
  animation: fadeUp 0.35s ease both;
}

/* На вузьких екранах таблиця скролиться в межах панелі, а не обрізається */
.table-panel :deep(.p-datatable-table) {
  min-width: 480px;
}

.table-empty {
  padding: 10px 4px;
  font-size: 13.5px;
  color: var(--muted);
}

.page-message {
  margin-top: 16px;
}

.status-dismissed {
  color: var(--muted);
}

.row-btn {
  padding: 5px 12px;
  border: 1px solid var(--line);
  background: transparent;
  font: inherit;
  font-size: 12.5px;
  color: var(--text-dim);
  cursor: pointer;
  white-space: nowrap;
  transition: all 0.12s ease;
}

.row-btn:hover:not(:disabled) {
  border-color: #c0392b;
  color: #c0392b;
}

.row-btn.is-dim:hover:not(:disabled) {
  border-color: var(--accent);
  color: var(--accent);
}

.row-btn:disabled {
  cursor: not-allowed;
  opacity: 0.6;
}
</style>
