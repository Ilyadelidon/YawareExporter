<script setup>
import { ref } from 'vue';
import { useIntegrationFeedback } from '../../composables/integrations/useIntegrationFeedback';
import { useGoogleSheetIntegration } from '../../composables/integrations/useGoogleSheetIntegration';
import { useAuthStore } from '../../stores/auth';
import DangerConfirm from './DangerConfirm.vue';
import IntegrationIcon from './IntegrationIcon.vue';
import IntegrationRow from './IntegrationRow.vue';
import ManageToggle from './ManageToggle.vue';
import SegmentedControl from './SegmentedControl.vue';

const auth = useAuthStore();
const feedback = useIntegrationFeedback();
const sheet = useGoogleSheetIntegration();

const MODES = [
  { value: 'create', label: 'Створити нову' },
  { value: 'link', label: 'Підключити наявну' },
];

const mode = ref('create');
const name = ref(`Звіти — ${auth.user?.name || ''}`.trim());
const email = ref(auth.user?.email || '');
const link = ref('');

function create() {
  sheet.create({ name: name.value.trim(), email: email.value.trim() });
}

async function linkExisting() {
  const spreadsheet = link.value.trim();
  if (!spreadsheet || sheet.linking) return;
  if (await sheet.link(spreadsheet)) link.value = '';
}
</script>

<template>
  <div v-if="sheet.loading" class="row-skeleton"><div class="skeleton"></div></div>
  <IntegrationRow
    v-else
    id="sheets"
    name="Google Таблиця"
    :meta="sheet.meta"
    :status="sheet.status"
    :open="feedback.isManaging('sheets') && sheet.accountConnected"
    :notice="feedback.noticeFor('sheets')"
  >
    <template #icon><IntegrationIcon name="sheets" /></template>

    <template #action>
      <ManageToggle
        v-if="sheet.accountConnected"
        target="sheets"
        :primary="!sheet.hasSpreadsheet"
        :expanded="feedback.isManaging('sheets')"
        @toggle="feedback.toggleManage('sheets')"
      >{{ sheet.hasSpreadsheet ? 'Налаштування' : 'Підключити' }}</ManageToggle>
    </template>

    <!-- Таблиця вже є -->
    <template v-if="sheet.hasSpreadsheet">
      <div class="fields">
        <div class="field">
          <div class="field-label">Таблиця</div>
          <div class="field-control">
            <template v-if="sheet.state.spreadsheet_title">
              <span class="field-value">{{ sheet.state.spreadsheet_title }}</span>
              <a
                v-if="sheet.state.spreadsheet_url"
                :href="sheet.state.spreadsheet_url"
                target="_blank"
                rel="noopener"
                class="field-link"
              >Відкрити в Google Таблицях ↗</a>
              <p class="field-hint">Звіт за день додається окремою вкладкою, таски розносяться по місячному аркушу.</p>
            </template>
            <p v-else class="field-error">
              Таблицю не вдалося прочитати: її видалили або забрали доступ.
              Відвʼяжіть її і створіть чи підключіть іншу.
            </p>
          </div>
        </div>
      </div>

      <DangerConfirm
        action="Відвʼязати таблицю"
        warning="Файл залишиться, але нові звіти не вивантажуватимуться."
        confirm-label="Так, відвʼязати"
        busy-label="Відвʼязуємо…"
        :busy="sheet.detaching"
        @confirm="sheet.detach"
      />
    </template>

    <!-- Таблиці ще немає: створити нову або підключити наявну -->
    <template v-else>
      <SegmentedControl v-model="mode" :options="MODES" role="tab" small aria-label="Спосіб підключення таблиці" />

      <form v-if="mode === 'create'" class="fields" @submit.prevent="create">
        <div class="field">
          <label class="field-label" for="sheet-name">Назва</label>
          <div class="field-control">
            <input id="sheet-name" v-model="name" type="text" class="input" maxlength="255" />
          </div>
        </div>
        <div class="field">
          <label class="field-label" for="sheet-email">Email редактора</label>
          <div class="field-control">
            <input id="sheet-email" v-model="email" type="email" class="input" placeholder="email@example.com" maxlength="255" />
            <p class="field-hint">Цей email отримає доступ редактора і посилання на таблицю.</p>
          </div>
        </div>
        <div class="field">
          <div></div>
          <div class="field-control">
            <button type="submit" class="btn btn-primary" :disabled="sheet.creating">
              {{ sheet.creating ? 'Створюємо…' : 'Створити таблицю' }}
            </button>
          </div>
        </div>
      </form>

      <form v-else class="fields" @submit.prevent="linkExisting">
        <div class="field">
          <label class="field-label" for="sheet-link">Посилання</label>
          <div class="field-control">
            <input
              id="sheet-link"
              v-model="link"
              type="text"
              class="input"
              placeholder="https://docs.google.com/spreadsheets/d/…"
              maxlength="2048"
            />
            <p class="field-hint">
              Спершу в таблиці натисніть «Поділитися» і дайте доступ <strong>редактора</strong><template v-if="sheet.state.account_email">
              акаунту <strong>{{ sheet.state.account_email }}</strong></template>.
              Наявні аркуші не зміняться, звіти додаватимуться окремими вкладками.
            </p>
          </div>
        </div>
        <div class="field">
          <div></div>
          <div class="field-control">
            <button type="submit" class="btn btn-primary" :disabled="sheet.linking || !link.trim()">
              {{ sheet.linking ? 'Перевіряємо доступ…' : 'Підключити таблицю' }}
            </button>
          </div>
        </div>
      </form>
    </template>
  </IntegrationRow>
</template>
