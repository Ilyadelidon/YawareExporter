<script setup>
import { onMounted } from 'vue';
import { useOpsTelegram } from '../../composables/settings/useOpsTelegram';
import { opsChatMeta } from '../../utils/settings';
import IntegrationIcon from '../integrations/IntegrationIcon.vue';
import IntegrationRow from '../integrations/IntegrationRow.vue';
import ManageToggle from '../integrations/ManageToggle.vue';
import UiIcon from '../UiIcon.vue';
import SettingsSection from './SettingsSection.vue';

// Службовий Telegram адміністратора: список чатів, куди йдуть збої й підсумок
// ранкового прогону, з перевіркою кожного і додаванням нового.
const ops = useOpsTelegram();
const {
  loading, open, notice, configured, chats, maxChats, linking, linkCommand,
  testingId, removingId, confirmingRemove, connected, full, busy, status, meta,
} = ops;

onMounted(ops.load);
</script>

<template>
  <SettingsSection id="sec-ops-telegram" title="Технічні сповіщення" :loading="loading">
    <template #description>
      Службовий канал адміністратора: чи пройшла ранкова генерація і що зламалось у сервісі.
      Працівникам такі повідомлення не надходять.
    </template>

    <IntegrationRow
      id="ops-telegram"
      name="Telegram"
      :meta="meta"
      :status="status"
      :open="open && (connected || linking)"
      :notice="notice"
    >
      <template #icon><IntegrationIcon name="telegram" /></template>

      <template #action>
        <ManageToggle v-if="connected" target="ops-telegram" :expanded="open" @toggle="ops.toggle" />
        <button
          v-else-if="configured"
          type="button"
          class="btn btn-primary"
          :disabled="linking"
          @click="ops.connect"
        >
          <UiIcon v-if="linking" name="spinner" :size="13" :stroke-width="3" />
          {{ linking ? 'Чекаємо…' : 'Підключити' }}
        </button>
      </template>

      <div class="fields">
        <div class="field">
          <div class="field-label">Чати</div>
          <div class="field-control">
            <ul class="chat-list">
              <li v-for="chat in chats" :key="chat.id" class="chat-item">
                <template v-if="confirmingRemove === chat.id">
                  <span class="chat-confirm">Прибрати останній чат? Про збої сервісу ви більше не дізнаєтесь у Telegram.</span>
                  <span class="chat-actions">
                    <button type="button" class="btn btn-secondary" :disabled="busy" @click="ops.cancelRemove">Скасувати</button>
                    <button type="button" class="btn btn-danger" :disabled="busy" @click="ops.remove(chat)">
                      {{ removingId === chat.id ? 'Прибираємо…' : 'Так, прибрати' }}
                    </button>
                  </span>
                </template>

                <template v-else>
                  <span class="chat-kind">
                    <UiIcon :name="chat.kind === 'group' ? 'users' : 'user'" :size="15" :stroke-width="1.8" />
                  </span>
                  <span class="chat-text">
                    <span class="chat-name">{{ chat.title }}</span>
                    <span class="chat-meta">{{ opsChatMeta(chat) }}</span>
                  </span>
                  <span class="chat-actions">
                    <button type="button" class="btn btn-link" :disabled="busy" @click="ops.sendTest(chat)">
                      {{ testingId === chat.id ? 'Надсилаємо…' : 'Перевірити' }}
                    </button>
                    <button type="button" class="btn btn-link is-danger" :disabled="busy" @click="ops.remove(chat)">
                      {{ removingId === chat.id ? 'Прибираємо…' : 'Прибрати' }}
                    </button>
                  </span>
                </template>
              </li>

              <!-- Інструкція показується рівно тоді, коли за нею йдуть: поки
                   чекаємо на Start. Далі рядок зникає сам. -->
              <li v-if="linking" class="chat-item is-waiting" role="status">
                <span class="chat-kind">
                  <UiIcon name="spinner" :size="15" :stroke-width="2.6" />
                </span>
                <span class="chat-text">
                  <span class="chat-name">Чекаємо на Start у Telegram</span>
                  <span class="chat-meta">
                    Натисніть Start у боті, що відкрився.
                    <template v-if="linkCommand">Для групи: додайте туди бота й надішліть <code>{{ linkCommand }}</code></template>
                  </span>
                </span>
              </li>

              <li v-else class="chat-item is-add">
                <button
                  v-if="configured && !full"
                  type="button"
                  class="chat-add"
                  :disabled="busy"
                  @click="ops.connect"
                >
                  <span class="chat-add-glyph" aria-hidden="true">+</span>
                  <span>Додати чат</span>
                </button>
                <span v-else class="chat-add-off">
                  {{ configured ? 'Більше чатів додати не можна' : 'Telegram-бот не налаштований на сервері' }}
                </span>
                <span v-if="configured" class="chat-counter">{{ chats.length }} з {{ maxChats }}</span>
              </li>
            </ul>
          </div>
        </div>

        <div class="field">
          <div class="field-label">Що надходить</div>
          <div class="field-control">
            <ul class="what-list">
              <li><b>Ранкова генерація</b> — скільки звітів у черзі, кого пропущено</li>
              <li><b>Тривоги монітора</b> — невдалі звіти й AI-розбори, зависла черга, місце на диску, застарілий бекап</li>
              <li><b>Падіння планувальника</b> — ранковий прогін не стартував</li>
            </ul>
          </div>
        </div>
      </div>
    </IntegrationRow>
  </SettingsSection>
</template>

<style scoped>
/* Список адресатів: рядок — тип чату, назва з підписом, дії праворуч.
   Остання строка списку — сама дія «додати», щоб список лишався одним
   обʼєктом, а не парою «список + кнопка десь поруч». */
.chat-list {
  list-style: none;
  margin: 0;
  padding: 0;
  border: 1px solid var(--control-line);
  background: var(--surface);
}

.chat-item {
  display: grid;
  grid-template-columns: 22px minmax(0, 1fr) auto;
  align-items: center;
  gap: 4px 10px;
  padding: 9px 8px 9px 11px;
}

.chat-item + .chat-item {
  border-top: 1px solid var(--line);
}

.chat-kind {
  display: flex;
  color: var(--muted-2);
}

.chat-text {
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 1px;
}

.chat-name {
  font-size: 13px;
  font-weight: 600;
  color: #2b2f33;
  overflow-wrap: anywhere;
}

.chat-meta {
  font-size: 11.5px;
  line-height: 1.5;
  color: var(--muted);
  font-variant-numeric: tabular-nums;
}

.chat-meta code {
  font-family: 'IBM Plex Mono', ui-monospace, monospace;
  font-size: 11px;
  font-weight: 600;
  color: var(--text-dim);
  background: var(--line);
  padding: 1px 4px;
}

.chat-actions {
  display: flex;
  align-items: center;
  gap: 4px;
}

.chat-confirm {
  grid-column: 1 / 3;
  font-size: 12.5px;
  color: var(--text-dim);
}

.chat-item.is-waiting .chat-kind {
  color: var(--accent);
}

.chat-item.is-waiting .chat-name {
  color: var(--text-dim);
}

/* Рядок-дія без власної межі: висота як у чатів, підсвічування — на ховер. */
.chat-item.is-add {
  padding: 0 11px 0 0;
  min-height: 38px;
}

.chat-add {
  grid-column: 1 / 3;
  display: flex;
  align-items: center;
  gap: 10px;
  min-height: 38px;
  padding: 0 0 0 11px;
  border: 0;
  background: none;
  font-family: inherit;
  font-size: 12.5px;
  font-weight: 600;
  color: var(--accent);
  cursor: pointer;
  transition: background 0.15s ease;
}

.chat-add:hover:not(:disabled) {
  background: #f4faf9;
}

.chat-add:focus-visible {
  outline: 2px solid var(--accent);
  outline-offset: -2px;
}

.chat-add:disabled {
  cursor: not-allowed;
  opacity: 0.55;
}

/* Ширина = колонка іконок у рядках чатів: підпис «Додати чат» стає рівно під
   назвами чатів, а не з власним відступом. */
.chat-add-glyph {
  width: 22px;
  display: flex;
  justify-content: center;
  font-size: 15px;
  font-weight: 500;
}

.chat-add-off {
  grid-column: 1 / 3;
  padding-left: 11px;
  font-size: 12.5px;
  color: var(--muted);
}

.chat-counter {
  font-size: 11.5px;
  color: var(--muted-2);
  font-variant-numeric: tabular-nums;
}

/* Що надходить: підмет жирним, решта — пояснення в один потік тексту. */
.what-list {
  list-style: none;
  margin: 0;
  padding: 3px 0 0;
  display: flex;
  flex-direction: column;
  gap: 6px;
  max-width: 62ch;
}

.what-list li {
  font-size: 12.5px;
  line-height: 1.55;
  color: var(--muted);
}

.what-list b {
  font-weight: 600;
  color: var(--text-dim);
}

@media (max-width: 760px) {
  .chat-item {
    grid-template-columns: 22px minmax(0, 1fr);
    padding: 9px 11px;
  }

  .chat-actions {
    grid-column: 2 / 3;
    margin-left: -6px;
  }

  .chat-item.is-add {
    grid-template-columns: minmax(0, 1fr) auto;
    padding: 0 11px 0 0;
  }

  .chat-add,
  .chat-add-off {
    grid-column: 1 / 2;
  }
}
</style>
