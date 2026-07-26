<script setup>
import { onMounted, ref } from 'vue';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import client from '../api/client';

const employees = ref([]);
const loading = ref(true);

async function loadEmployees() {
  const { data } = await client.get('/employees');
  employees.value = data.data;
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

    <div class="panel table-panel">
      <DataTable :value="employees" :loading="loading" data-key="id">
        <template #empty>
          <div class="table-empty">Працівників поки немає.</div>
        </template>
        <Column field="name" header="Імʼя" sortable />
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
</style>
