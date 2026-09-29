<script setup>
import { ref, watch } from 'vue';
import Select from 'primevue/select';
import { useIntegrationFeedback } from '../../composables/integrations/useIntegrationFeedback';
import DangerConfirm from './DangerConfirm.vue';
import IntegrationIcon from './IntegrationIcon.vue';
import IntegrationRow from './IntegrationRow.vue';
import ManageToggle from './ManageToggle.vue';

const props = defineProps({
  // useTrelloIntegration()
  trello: { type: Object, required: true },
  // Trello — джерело тасків для звіту
  active: { type: Boolean, default: false },
});

const feedback = useIntegrationFeedback();

const selectedBoardId = ref(null);
const newBoardName = ref('');

// Після кожного оновлення стану список показує поточну активну дошку.
watch(() => props.trello.state?.board_id, (boardId) => {
  selectedBoardId.value = boardId || null;
}, { immediate: true });

function selectBoard() {
  if (selectedBoardId.value) props.trello.selectBoard(selectedBoardId.value);
}

async function createBoard() {
  const name = newBoardName.value.trim();
  if (!name || props.trello.creatingBoard) return;
  if (await props.trello.createBoard(name)) newBoardName.value = '';
}
</script>

<template>
  <IntegrationRow
    id="trello"
    name="Trello"
    :meta="trello.meta"
    :status="trello.status"
    :tag="active ? 'Активний' : ''"
    :open="feedback.isManaging('trello') && trello.connected"
    :notice="feedback.noticeFor('trello')"
  >
    <template #icon><IntegrationIcon name="trello" /></template>

    <template #action>
      <ManageToggle
        v-if="trello.connected"
        target="trello"
        :expanded="feedback.isManaging('trello')"
        @toggle="feedback.toggleManage('trello')"
      />
      <button v-else type="button" class="btn btn-primary" @click="trello.connect">Підключити</button>
    </template>

    <p v-if="!active" class="panel-hint is-top">
      Зараз таски беруться з Бітрікс24. Дошка Trello зберігається, але у звіт не потрапляє.
    </p>

    <div class="fields">
      <div class="field">
        <div class="field-label">Акаунт</div>
        <div class="field-control">
          <span class="field-value">@{{ trello.state.username }}</span>
        </div>
      </div>

      <div class="field">
        <label class="field-label" for="trello-board">Активна дошка</label>
        <div class="field-control">
          <div class="control-row">
            <Select
              v-model="selectedBoardId"
              input-id="trello-board"
              :options="trello.boards"
              option-label="name"
              option-value="id"
              :loading="trello.boardsLoading"
              placeholder="Оберіть дошку"
              class="control-grow"
            />
            <button
              type="button"
              class="btn btn-secondary"
              :disabled="trello.selectingBoard || !selectedBoardId || selectedBoardId === trello.state.board_id"
              @click="selectBoard"
            >
              {{ trello.selectingBoard ? 'Зберігаємо…' : 'Зберегти' }}
            </button>
          </div>
          <a
            v-if="trello.state.board_url"
            :href="trello.state.board_url"
            target="_blank"
            rel="noopener"
            class="field-link"
          >Відкрити «{{ trello.boardLabel }}» у Trello ↗</a>
        </div>
      </div>

      <div class="field">
        <label class="field-label" for="trello-new-board">Нова дошка</label>
        <div class="field-control">
          <div class="control-row">
            <input
              id="trello-new-board"
              v-model="newBoardName"
              type="text"
              class="input control-grow"
              placeholder="Назва дошки"
              maxlength="255"
              @keyup.enter="createBoard"
            />
            <button
              type="button"
              class="btn btn-secondary"
              :disabled="trello.creatingBoard || !newBoardName.trim()"
              @click="createBoard"
            >
              {{ trello.creatingBoard ? 'Створюємо…' : 'Створити' }}
            </button>
          </div>
          <p class="field-hint">Створюється зі списками й мітками з шаблону і одразу стає активною.</p>
        </div>
      </div>
    </div>

    <DangerConfirm
      action="Відключити Trello"
      warning="Токен буде відкликано, обрану дошку скинуто."
      :busy="trello.disconnecting"
      @confirm="trello.disconnect"
    />
  </IntegrationRow>
</template>
