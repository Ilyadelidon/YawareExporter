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
</style>
