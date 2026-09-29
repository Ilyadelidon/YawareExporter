<script setup>
import { onMounted, onUnmounted, ref } from 'vue';
import { useBitrixWorkspace } from '../../composables/settings/useBitrixWorkspace';
import DangerConfirm from '../integrations/DangerConfirm.vue';
import IntegrationIcon from '../integrations/IntegrationIcon.vue';
import IntegrationRow from '../integrations/IntegrationRow.vue';
import ManageToggle from '../integrations/ManageToggle.vue';
import SettingsSection from './SettingsSection.vue';

// Портал Бітрікс24 команди: інструкція, як завести застосунок на порталі,
// форма з його реквізитами, а після підключення — відключення.
const bitrix = useBitrixWorkspace();
const { loading, state, open, notice, form, saving, disconnecting, connected, portalLabel, redirectUri, status, meta, canSubmit } = bitrix;

const copied = ref(false);
let copiedTimer = null;

async function copyRedirect() {
  try {
    await navigator.clipboard.writeText(redirectUri.value);
    copied.value = true;
    clearTimeout(copiedTimer);
    copiedTimer = setTimeout(() => { copied.value = false; }, 2000);
  } catch {
    // без доступу до буфера адресу видно в полі — її можна виділити вручну
  }
}

onMounted(bitrix.load);
onUnmounted(() => clearTimeout(copiedTimer));
</script>

<template>
  <SettingsSection id="sec-team-tracker" title="Таск-трекер команди" :loading="loading">
    <template #description>
      Портал, на який входять працівники, що обрали Бітрікс24. Таски читаються
      особистим токеном кожного, а не спільним ключем.
    </template>

    <IntegrationRow
      id="bitrix-workspace"
      name="Портал Бітрікс24"
      :meta="meta"
      :status="status"
      :open="open"
      :notice="notice"
    >
      <template #icon><IntegrationIcon name="bitrix" /></template>

      <template #action>
        <ManageToggle target="bitrix-workspace" :primary="!connected" :expanded="open" @toggle="bitrix.toggle">
          {{ connected ? 'Налаштування' : 'Підключити' }}
        </ManageToggle>
      </template>

      <!-- Портал підключено -->
      <template v-if="connected">
        <div class="fields">
          <div class="field">
            <div class="field-label">Портал</div>
            <div class="field-control">
              <a :href="state.portal_url" target="_blank" rel="noopener" class="field-link is-inline">{{ portalLabel }} ↗</a>
            </div>
          </div>

          <div class="field">
            <div class="field-label">Доступ до тасків</div>
            <div class="field-control">
              <span class="field-value">Особистий у кожного працівника (OAuth)</span>
            </div>
          </div>

          <div v-if="state.connected_by" class="field">
            <div class="field-label">Підключив</div>
            <div class="field-control">
              <span class="field-value">{{ state.connected_by }}</span>
            </div>
          </div>
        </div>

        <DangerConfirm
          action="Відключити портал"
          warning="Токени всіх працівників буде видалено, і їхні звіти перестануть отримувати таски з Бітрікса."
          :busy="disconnecting"
          @confirm="bitrix.disconnect"
        >Щоб замінити реквізити застосунку, відключіть портал і підключіть заново.</DangerConfirm>
      </template>

      <!-- Портал ще не підключено: спершу застосунок на порталі, потім реквізити -->
      <template v-else>
        <ol class="steps">
          <li>
            У Бітріксі відкрийте <strong>Розробникам → Інші → Локальний застосунок</strong>,
            тип <strong>«Серверний»</strong>.
          </li>
          <li>
            Права: <strong>task</strong> (Завдання) і <strong>user</strong> (Користувачі).
            З обмеженим <code class="code">user_brief</code> портал не віддає пошту, і працівник
            не побачить, яким акаунтом підключився.
          </li>
          <li>
            Шлях повернення (redirect URI) — скопіюйте без змін:
            <span class="copy-row">
              <code class="code">{{ redirectUri }}</code>
              <button type="button" class="btn btn-link" :disabled="!redirectUri" @click="copyRedirect">
                {{ copied ? 'Скопійовано' : 'Копіювати' }}
              </button>
            </span>
          </li>
          <li>Збережіть застосунок і перенесіть його реквізити у форму нижче.</li>
        </ol>

        <form class="fields" @submit.prevent="bitrix.connect">
          <div class="field">
            <label class="field-label" for="bitrix-portal">Адреса порталу</label>
            <div class="field-control">
              <input
                id="bitrix-portal"
                v-model="form.portalUrl"
                type="text"
                class="input"
                placeholder="https://ваш-портал.bitrix24.ua"
                maxlength="255"
              />
            </div>
          </div>

          <div class="field">
            <label class="field-label" for="bitrix-client-id">ID застосунку</label>
            <div class="field-control">
              <input
                id="bitrix-client-id"
                v-model="form.clientId"
                type="text"
                class="input"
                placeholder="local.6xxxxxxxxxxxxx.xxxxxxxx"
                maxlength="255"
              />
              <p class="field-hint">Поле <strong>client_id</strong> на сторінці застосунку.</p>
            </div>
          </div>

          <div class="field">
            <label class="field-label" for="bitrix-client-secret">Ключ застосунку</label>
            <div class="field-control">
              <input
                id="bitrix-client-secret"
                v-model="form.clientSecret"
                type="password"
                class="input"
                placeholder="client_secret"
                maxlength="255"
                autocomplete="off"
              />
              <p class="field-hint">
                Зберігається зашифрованим. Самі таски сервіс читає не ним, а особистим токеном працівника.
              </p>
            </div>
          </div>

          <div class="field">
            <div></div>
            <div class="field-control">
              <button type="submit" class="btn btn-primary" :disabled="saving || !canSubmit">
                {{ saving ? 'Зберігаємо…' : 'Підключити портал' }}
              </button>
            </div>
          </div>
        </form>
      </template>
    </IntegrationRow>
  </SettingsSection>
</template>

<style scoped>
/* Покрокова інструкція: нумерація відділяє «що зробити на порталі» від форми. */
.steps {
  margin: 0 0 18px;
  padding: 0 0 16px 18px;
  border-bottom: 1px solid #e8edf0;
  font-size: 12.5px;
  line-height: 1.55;
  color: var(--text-dim);
  max-width: 76ch;
}

.steps li + li {
  margin-top: 6px;
}

.steps li::marker {
  font-weight: 700;
  color: var(--muted);
}

.steps strong {
  color: #2b2f33;
}

.code {
  font-family: ui-monospace, SFMono-Regular, Consolas, monospace;
  font-size: 12px;
  color: #2b2f33;
  background: var(--line);
  padding: 1px 5px;
  word-break: break-all;
}

.copy-row {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 4px 8px;
  margin-top: 4px;
}
</style>
