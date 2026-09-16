<script setup>
import { onMounted, ref } from 'vue';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import Message from 'primevue/message';
import client from '../api/client';
import EmployeeMemoryPanel from '../components/EmployeeMemoryPanel.vue';

const employees = ref([]);
// Пам'ять AI вантажиться лише для розгорнутого рядка — окремий запит на працівника.
const expandedRows = ref({});
const loading = ref(true);
const savingId = ref(null);
const errorMessage = ref('');

async function loadEmployees() {
  const { data } = await client.get('/employees');
  employees.value = data.data;
}

// Посада редагується прямо в таблиці: її знає лише адміністратор, а окрема
// форма заради одного поля не потрібна. Зберігаємо на blur/Enter.
async function savePosition(employee, value) {
  const position = value.trim() || null;
  if (position === (employee.position || null)) {
    return;
  }

  savingId.value = employee.id;
  errorMessage.value = '';
  try {
    const { data } = await client.patch(`/employees/${employee.id}`, { position });
    employee.position = data.data.position;
  } catch (error) {
    errorMessage.value = error.response?.data?.message || 'Не вдалося зберегти посаду.';
  } finally {
    savingId.value = null;
  }
}

// Звільнення відкликає токени працівника одразу, а пароль Yaware стирає,
// тож питаємо підтвердження. Поновити можна, історія лишається.
async function dismiss(employee) {
  if (!window.confirm(
    `Звільнити ${employee.name}? Він одразу вийде із системи і більше не зайде — навіть якщо лишається в Yaware. `
    + 'Звіти, активності й Табель за минулі дні збережуться.',
  )) {
    return;
  }

  await toggleDismissal(employee, () => client.post(`/employees/${employee.id}/dismissal`));
}

async function reinstate(employee) {
  await toggleDismissal(employee, () => client.delete(`/employees/${employee.id}/dismissal`));
}

async function toggleDismissal(employee, request) {
  savingId.value = employee.id;
  errorMessage.value = '';
  try {
    const { data } = await request();
    employee.dismissed_at = data.data.dismissed_at;
    employee.active = data.data.active;
  } catch (error) {
    errorMessage.value = error.response?.data?.message || 'Не вдалося змінити статус працівника.';
  } finally {
    savingId.value = null;
  }
}

onMounted(async () => {
  try {
    await loadEmployees();
  } finally {
    loading.value = false;
  }
});
</script>

<template>
  <div class="employees-page">
    <div class="page-head">
      <div class="page-head-info">
        <div class="page-head-icon">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#149d8d" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
        </div>
        <div>
          <div class="page-head-title">Працівники</div>
          <div class="page-head-subtitle">Список працівників для формування звітів</div>
        </div>
      </div>
    </div>

    <Message v-if="errorMessage" severity="error" :closable="false" class="page-message">{{ errorMessage }}</Message>

    <div class="panel table-panel">
      <DataTable v-model:expanded-rows="expandedRows" :value="employees" :loading="loading" data-key="id">
        <template #empty>
          <div class="table-empty">Працівників поки немає.</div>
        </template>
        <template #expansion="{ data }">
          <EmployeeMemoryPanel :employee-id="data.id" />
        </template>
        <Column expander style="width: 40px" />
        <Column field="name" header="Імʼя" sortable />
        <Column field="position" header="Посада" sortable>
          <template #body="{ data }">
            <input
              class="cell-input"
              type="text"
              placeholder="Не вказано"
              :value="data.position || ''"
              :disabled="savingId === data.id"
              @change="savePosition(data, $event.target.value)"
              @keyup.enter="$event.target.blur()"
            >
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
        <Column header="" style="width: 130px">
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
              @click="dismiss(data)"
            >Звільнити</button>
          </template>
        </Column>
      </DataTable>
    </div>
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

.cell-input {
  width: 100%;
  min-width: 140px;
  padding: 4px 6px;
  border: 1px solid transparent;
  background: transparent;
  font: inherit;
  color: inherit;
}

.cell-input:hover:not(:disabled) {
  border-color: var(--line);
}

.cell-input:focus {
  outline: none;
  border-color: var(--accent);
  background: var(--surface);
}

.cell-input:disabled {
  opacity: 0.6;
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
