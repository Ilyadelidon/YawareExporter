<script setup>
// Пам'ять AI по одному працівнику: що модель уже з'ясувала про його домени
// й застосунки. Правка робить рядок «підтвердженим керівником» — далі модель
// його не перезаписує і він не протухає.
import { onMounted, ref } from 'vue';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import Message from 'primevue/message';
import client from '../api/client';

const props = defineProps({
  employeeId: { type: Number, required: true },
});

const rows = ref([]);
const loading = ref(true);
const errorMessage = ref('');
const savingId = ref(null);
const recheckDays = ref(90);
const newFact = ref({ name: '', note: '' });
const addingFact = ref(false);

const verdictOptions = [
  { value: 'work_related', label: 'Робоче' },
  { value: 'personal', label: 'Особисте' },
  { value: 'unknown', label: 'Невідомо' },
];

const verdictClass = {
  work_related: 'is-work',
  personal: 'is-personal',
  unknown: 'is-unknown',
};

async function load() {
  loading.value = true;
  try {
    const { data } = await client.get(`/employees/${props.employeeId}/memory`);
    rows.value = data.data;
    recheckDays.value = data.recheck_days;
    errorMessage.value = '';
  } catch (error) {
    errorMessage.value = error.response?.data?.message || 'Не вдалося завантажити пам\'ять.';
  } finally {
    loading.value = false;
  }
}

async function save(row, changes) {
  savingId.value = row.id;
  errorMessage.value = '';
  try {
    const { data } = await client.patch(
      `/employees/${props.employeeId}/memory/${row.id}`,
      { verdict: row.verdict, note: row.note, ...changes },
    );
    Object.assign(row, data.data);
  } catch (error) {
    errorMessage.value = error.response?.data?.message || 'Не вдалося зберегти.';
    await load();
  } finally {
    savingId.value = null;
  }
}

async function remove(row) {
  savingId.value = row.id;
  try {
    await client.delete(`/employees/${props.employeeId}/memory/${row.id}`);
    rows.value = rows.value.filter((item) => item.id !== row.id);
  } catch (error) {
    errorMessage.value = error.response?.data?.message || 'Не вдалося видалити.';
  } finally {
    savingId.value = null;
  }
}

async function addFact() {
  const name = newFact.value.name.trim();
  if (!name) {
    return;
  }

  addingFact.value = true;
  errorMessage.value = '';
  try {
    await client.post(`/employees/${props.employeeId}/memory`, {
      kind: 'fact',
      name,
      note: newFact.value.note.trim() || null,
    });
    newFact.value = { name: '', note: '' };
    await load();
  } catch (error) {
    errorMessage.value = error.response?.data?.message || 'Не вдалося додати факт.';
  } finally {
    addingFact.value = false;
  }
}

onMounted(load);
</script>

<template>
  <div class="memory-panel">
    <Message v-if="errorMessage" severity="warn" :closable="false">{{ errorMessage }}</Message>

    <div v-if="loading" class="skeleton memory-skeleton"></div>

    <template v-else>
      <div v-if="!rows.length" class="memory-empty">
        Пам'ять порожня — вона наповнюється після кожного AI-розбору дня.
      </div>

      <DataTable v-else :value="rows" data-key="id" class="memory-table">
        <Column field="name" header="Активність" sortable />
        <Column field="verdict" header="Вердикт" style="width: 130px">
          <template #body="{ data }">
            <select
              v-if="data.kind === 'activity'"
              v-model="data.verdict"
              class="memory-select"
              :class="verdictClass[data.verdict]"
              :disabled="savingId === data.id"
              @change="save(data)"
            >
              <option v-for="option in verdictOptions" :key="option.value" :value="option.value">
                {{ option.label }}
              </option>
            </select>
            <span v-else class="memory-kind">Факт</span>
          </template>
        </Column>
        <Column field="note" header="Нотатка">
          <template #body="{ data }">
            <input
              class="cell-input"
              type="text"
              placeholder="—"
              :value="data.note || ''"
              :disabled="savingId === data.id"
              @change="save(data, { note: $event.target.value.trim() || null })"
              @keyup.enter="$event.target.blur()"
            >
          </template>
        </Column>
        <Column field="occurrences" header="Днів" sortable style="width: 70px">
          <template #body="{ data }">
            <span class="memory-count">{{ data.occurrences }}</span>
          </template>
        </Column>
        <Column field="source" header="Джерело" style="width: 130px">
          <template #body="{ data }">
            <span class="memory-source" :class="{ 'is-admin': data.source === 'admin' }">
              {{ data.source === 'admin' ? 'Керівник' : 'AI' }}
            </span>
          </template>
        </Column>
        <Column style="width: 40px">
          <template #body="{ data }">
            <button
              type="button"
              class="memory-remove"
              title="Видалити з пам'яті"
              :disabled="savingId === data.id"
              @click="remove(data)"
            >×</button>
          </template>
        </Column>
      </DataTable>

      <div class="memory-add">
        <input v-model="newFact.name" class="memory-input" type="text" placeholder="Факт про робочий контекст">
        <input v-model="newFact.note" class="memory-input" type="text" placeholder="Пояснення (необовʼязково)">
        <button type="button" class="memory-action" :disabled="addingFact" @click="addFact">Додати</button>
      </div>

      <p class="memory-hint">
        Вердикти AI переперевіряються раз на {{ recheckDays }} днів. Виправлений
        керівником рядок модель більше не змінює.
      </p>
    </template>
  </div>
</template>

<style scoped>
.memory-panel {
  padding: 12px 4px 4px;
}

.memory-skeleton {
  height: 90px;
  border-radius: 10px;
}

.memory-empty {
  padding: 10px 4px;
  font-size: 13px;
  color: var(--muted);
}

.memory-table :deep(.p-datatable-table) {
  min-width: 620px;
}

.memory-select,
.memory-input {
  padding: 4px 6px;
  border: 1px solid var(--line);
  background: var(--surface);
  font: inherit;
  font-size: 12.5px;
  color: var(--ink);
}

.memory-select {
  font-weight: 600;
  cursor: pointer;
}

.memory-select.is-work {
  color: #0e7d70;
}

.memory-select.is-personal {
  color: #b33c3c;
}

.memory-select:disabled {
  opacity: 0.55;
}

.cell-input {
  width: 100%;
  min-width: 160px;
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

.memory-count {
  display: block;
  text-align: right;
  font-variant-numeric: tabular-nums;
  font-weight: 600;
}

.memory-kind,
.memory-source {
  display: inline-block;
  padding: 2px 8px;
  font-size: 11.5px;
  font-weight: 600;
  white-space: nowrap;
  background: var(--line);
  color: var(--muted);
}

.memory-source.is-admin {
  background: #d5f2ee;
  color: #0e7d70;
}

.memory-remove {
  border: none;
  background: transparent;
  font-size: 17px;
  line-height: 1;
  color: var(--muted);
  cursor: pointer;
}

.memory-remove:hover:not(:disabled) {
  color: #b33c3c;
}

.memory-add {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 12px;
}

.memory-add .memory-input {
  flex: 1 1 200px;
}

.memory-action {
  padding: 6px 11px;
  border: 1px solid var(--line);
  background: var(--surface);
  font-family: inherit;
  font-size: 12px;
  font-weight: 600;
  color: var(--text-dim);
  cursor: pointer;
  transition: all 0.15s ease;
}

.memory-action:hover:not(:disabled) {
  border-color: var(--accent);
  color: var(--accent);
}

.memory-action:disabled {
  opacity: 0.55;
  cursor: default;
}

.memory-hint {
  margin: 10px 0 0;
  font-size: 12px;
  line-height: 1.5;
  color: var(--muted);
}
</style>
