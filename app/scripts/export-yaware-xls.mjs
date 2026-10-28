import fs from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { stdin as input, stdout as output, platform } from 'node:process';
import { chromium } from 'playwright';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

// export-yaware-xls.mjs лежить у app/scripts
const appRoot = path.resolve(__dirname, '..');          // D:\YawareExporter\app
const portableRoot = path.resolve(appRoot, '..');       // D:\YawareExporter
// Python: на Windows — портативний з теки python/, на Linux-сервері — системний python3
// (потрібен openpyxl); YAWARE_PYTHON_BINARY перевизначає обидва варіанти.
const pythonPath = process.env.YAWARE_PYTHON_BINARY
  || (platform === 'win32' ? path.join(portableRoot, 'python', 'python.exe') : 'python3');

const {
  YAWARE_EMAIL: ENV_YAWARE_EMAIL,
  YAWARE_PASSWORD: ENV_YAWARE_PASSWORD,
  YAWARE_HEADLESS = 'true',
  YAWARE_DOWNLOAD_DIR,
  YAWARE_DATE: ENV_YAWARE_DATE,
} = process.env;

// Worker-режим: без інтерактиву та прогрес-бара; параметри з env,
// результат — JSON останнім рядком stdout (для запуску з Laravel-черги).
const WORKER_MODE = process.env.YAWARE_WORKER === 'true';
const TARGET_EMPLOYEE_EMAIL = process.env.YAWARE_TARGET_EMAIL || '';

// Таски Trello за дату звіту: [{name, comment, start: 'Y-m-d H:i', due: 'Y-m-d H:i'}].
// Без них звіт генерується як раніше — з порожньою табличкою тасок.
const TRELLO_TASKS = (() => {
  try {
    const parsed = JSON.parse(process.env.YAWARE_TRELLO_TASKS || '[]');
    return Array.isArray(parsed) ? parsed : [];
  } catch {
    return [];
  }
})();

const TEMP_DOWNLOAD_PREFIX = '.yaware-source-';
const CREDENTIALS_FILE_NAME = '.yaware-credentials.json';

const LOGIN_URL = 'https://app.yaware.com.ua/';
const REPORT_URL = 'https://app.yaware.com.ua/reports/by-application';
const IDLE_ACTIVITIES_URL = 'https://app.yaware.com.ua/reports/by-idle-activities';
const downloadDirectory = path.resolve(process.cwd(), YAWARE_DOWNLOAD_DIR || 'downloads');

// Дані звітів беремо з GraphQL API, яким користується сам веб-додаток Yaware.
// Старий канал через RapidAPI (api4yaware) власник API вимкнув (405 "provider has
// disabled request access"), тому авторизуємось JWT-токеном із залогіненої сесії.
const GRAPHQL_API_URL = 'https://data-api-3.yaware.com/graphql/v1';
const JWT_GENERATE_URL = 'https://app.yaware.com.ua/jwt/generate';
const SUMMARY_START_COLUMN_INDEX = 19;
const TASK_TABLE_START_COLUMN_INDEX = 24;
const TASK_TABLE_START_ROW_INDEX = 6;
const TASK_TABLE_EMPTY_ROW_COUNT = 10;
const DAY_REPORT_CATEGORIES = [
  { title: 'Нейтрально', keys: ['neutral'] },
  { title: 'Продуктивно', keys: ['productive'] },
  { title: 'Непродуктивно', keys: ['unproductive', 'distracting'] },
];
const CHANGE_CREDENTIALS_SHORTCUT = 'CTRL+I';
const CHANGE_CREDENTIALS_HINT = `[ ${CHANGE_CREDENTIALS_SHORTCUT} ] - змінити пошту`;
const HOTKEY_TRIGGERED = Symbol('hotkey-triggered');
const execFileAsync = promisify(execFile);

const credentialsFilePath = path.resolve(process.cwd(), CREDENTIALS_FILE_NAME);

async function loadSavedCredentials() {
  try {
    const rawConfig = await fs.readFile(credentialsFilePath, 'utf8');
    const parsedConfig = JSON.parse(rawConfig);
    const email = typeof parsedConfig?.email === 'string' ? parsedConfig.email.trim() : '';
    const password = typeof parsedConfig?.password === 'string' ? parsedConfig.password : '';

    if (!email || !password) {
      return null;
    }

    return { email, password };
  } catch (error) {
    if (error && 'code' in error && error.code === 'ENOENT') {
      return null;
    }

    throw new Error(`Не вдалося прочитати збережені облікові дані: ${error.message}`);
  }
}

async function saveCredentials(email, password) {
  const payload = JSON.stringify({ email, password }, null, 2);
  await fs.writeFile(credentialsFilePath, `${payload}\n`, { mode: 0o600 });
}

function isValidDisplayDate(value) {
  return /^\d{2}\.\d{2}\.\d{4}$/.test(value);
}

function renderProgressBar(progress, label) {
  if (WORKER_MODE) {
    return;
  }

  const normalizedProgress = Math.max(0, Math.min(100, Math.round(progress)));
  const barWidth = 28;
  const filledWidth = Math.round((normalizedProgress / 100) * barWidth);
  const bar = `${'█'.repeat(filledWidth)}${'░'.repeat(barWidth - filledWidth)}`;
  output.write(`\rПрогрес: [${bar}] ${String(normalizedProgress).padStart(3, ' ')}% · ${label.padEnd(45, ' ')}`);
}

// YAWARE_TIMING=true — у stderr час кожного кроку і XHR-запити, яких чекав
// воркер (заміри й налагодження очікувань); у звичайному режимі мовчить.
const TIMING_ENABLED = process.env.YAWARE_TIMING === 'true';
const timingStartedAt = Date.now();

function logTiming(message) {
  if (TIMING_ENABLED) {
    console.error(`[timing] +${((Date.now() - timingStartedAt) / 1000).toFixed(1)}s ${message}`);
  }
}

function updateProgress(progress, label) {
  logTiming(label);
  renderProgressBar(progress, label);
}

function finishProgress(label) {
  if (WORKER_MODE) {
    return;
  }

  renderProgressBar(100, label);
  output.write('\n');
}

function clearProgressBar() {
  if (WORKER_MODE) {
    return;
  }

  output.write('\r');
  clearConsoleLine();
}

function clearConsoleLine() {
  output.clearLine?.(0);
  output.cursorTo?.(0);
}

// У worker-режимі попередження додатково збираються і віддаються в підсумковому JSON.
const workerWarnings = [];

// Час дня, не покритий жодною таскою (секунди), як його порахував Python при
// збірці Excel. null — розподіл не рахувався: тасок немає, або звіт зібрано
// зі старого HTML-формату, де колонки розподілу взагалі немає.
let outsideTasksSeconds = null;

function reportWarning(message) {
  workerWarnings.push(message);
  clearProgressBar();
  output.write(`Попередження: ${message}\n`);
}

async function promptInput(question, options = {}) {
  const {
    hidden = false,
    hint = '',
    allowChangeCredentialsHotkey = false,
  } = options;

  return new Promise((resolve) => {
    let value = '';

    const cleanup = () => {
      input.setRawMode?.(false);
      input.pause();
      input.removeListener('data', onData);
    };

    const finish = (result) => {
      cleanup();
      resolve(result);
    };

    const redrawValue = () => {
      clearConsoleLine();
      output.write(`> ${hidden ? '*'.repeat(value.length) : value}`);
    };

    const onData = (key) => {
      if (key === '\u0003') {
        output.write('\n');
        cleanup();
        process.exit(130);
      }

      if (allowChangeCredentialsHotkey && key === '\t') {
        output.write('\n');
        finish(HOTKEY_TRIGGERED);
        return;
      }

      if (key === '\r' || key === '\n') {
        output.write('\n');
        finish(value.trim());
        return;
      }

      if (key === '\u007f' || key === '\b') {
        if (value.length > 0) {
          value = value.slice(0, -1);
          redrawValue();
        }
        return;
      }

      if (key >= ' ') {
        value += key;
        redrawValue();
      }
    };

    output.write(`${question}\n`);
    if (hint) {
      output.write(`${hint}\n`);
    }
    clearConsoleLine();
    output.write('> ');

    input.resume();
    input.setRawMode?.(true);
    input.setEncoding('utf8');
    input.on('data', onData);
  });
}

function convertDisplayDateToApiDate(displayDate) {
  const [day, month, year] = displayDate.split('.').map(Number);
  const utcDate = new Date(Date.UTC(year, month - 1, day));

  if (
      Number.isNaN(utcDate.getTime())
      || utcDate.getUTCFullYear() !== year
      || utcDate.getUTCMonth() !== month - 1
      || utcDate.getUTCDate() !== day
  ) {
    throw new Error('Некоректна дата. Використовуйте формат ДД.ММ.РРРР, наприклад 16.03.2026.');
  }

  return `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
}

async function promptReportDate(options = {}) {
  const answer = await promptInput('Введіть дату для вигрузки та формування звітів (ДД.ММ.РРРР):', options);

  if (answer === HOTKEY_TRIGGERED) {
    return HOTKEY_TRIGGERED;
  }

  const reportDate = answer.trim();

  if (!isValidDisplayDate(reportDate)) {
    throw new Error('Некоректний формат дати. Використовуйте формат ДД.ММ.РРРР, наприклад 16.03.2026.');
  }

  return {
    reportDate,
    apiReportDate: convertDisplayDateToApiDate(reportDate),
  };
}

async function promptHidden(question) {
  return promptInput(question, { hidden: true });
}

async function promptRuntimeConfig(savedCredentials) {
  const storedEmail = savedCredentials?.email || '';
  const storedPassword = savedCredentials?.password || '';
  let email = ENV_YAWARE_EMAIL || storedEmail;
  let password = ENV_YAWARE_PASSWORD || storedPassword;
  let shouldPersistCredentials = !savedCredentials && !ENV_YAWARE_EMAIL && !ENV_YAWARE_PASSWORD;
  const canChangeSavedCredentials = Boolean(storedEmail && storedPassword && !ENV_YAWARE_EMAIL && !ENV_YAWARE_PASSWORD);

  if (canChangeSavedCredentials) {
    output.write(`${CHANGE_CREDENTIALS_HINT}\n\n`);
  }

  output.write('Підготовка вигрузки Yaware.\n');

  if (!email) {
    email = (await promptInput('Пошта:')).trim();
    if (!email) {
      throw new Error('Пошта є обовʼязковою.');
    }
  } else if (canChangeSavedCredentials) {
    output.write(`Використовуються збережені облікові дані для ${storedEmail}.\n`);
  }

  if (!password) {
    password = await promptHidden('Пароль:');
    if (!password) {
      throw new Error('Пароль є обовʼязковим.');
    }
  }

  if (ENV_YAWARE_DATE) {
    const rawDate = ENV_YAWARE_DATE.trim();
    if (!isValidDisplayDate(rawDate)) {
      throw new Error('Некоректний формат дати в YAWARE_DATE. Використовуйте формат ДД.ММ.РРРР.');
    }
    return {
      email,
      password,
      reportDate: rawDate,
      apiReportDate: convertDisplayDateToApiDate(rawDate),
      shouldPersistCredentials,
    };
  }

  let dateConfig = await promptReportDate({
    allowChangeCredentialsHotkey: canChangeSavedCredentials,
  });

  if (dateConfig === HOTKEY_TRIGGERED) {
    output.write('Зміна облікових даних.\n');

    email = (await promptInput('Пошта:')).trim();
    if (!email) {
      throw new Error('Пошта є обовʼязковою.');
    }

    password = await promptHidden('Пароль:');
    if (!password) {
      throw new Error('Пароль є обовʼязковим.');
    }

    shouldPersistCredentials = true;
    dateConfig = await promptReportDate({ allowChangeCredentialsHotkey: true });
    if (dateConfig === HOTKEY_TRIGGERED) {
      dateConfig = await promptReportDate();
    }
  }

  return {
    email,
    password,
    ...dateConfig,
    shouldPersistCredentials,
  };
}

async function promptCredentialsRetry() {
  const email = (await promptInput('Пошта:')).trim();

  if (!email) {
    throw new Error('Пошта є обовʼязковою.');
  }

  const password = await promptHidden('Пароль:');
  if (!password) {
    throw new Error('Пароль є обовʼязковим.');
  }

  return { email, password };
}

async function openDirectory(directoryPath) {
  if (platform === 'win32') {
    await execFileAsync('explorer.exe', [directoryPath]);
    return;
  }

  if (platform === 'darwin') {
    await execFileAsync('open', [directoryPath]);
    return;
  }

  await execFileAsync('xdg-open', [directoryPath]);
}

function escapeHtml(value) {
  return String(value)
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#39;');
}

function getNestedValue(source, path) {
  return path.split('.').reduce((current, key) => {
    if (current === null || current === undefined) {
      return undefined;
    }

    return current[key];
  }, source);
}

function getFirstDefinedValue(source, candidatePaths) {
  for (const candidatePath of candidatePaths) {
    const value = getNestedValue(source, candidatePath);
    if (value !== null && value !== undefined && value !== '') {
      return value;
    }
  }

  return '';
}

function formatPeriodLabel(apiDate) {
  const [year, month, day] = apiDate.split('-').map(Number);
  const months = ['січ', 'лют', 'бер', 'квіт', 'трав', 'чер', 'лип', 'сер', 'вер', 'жовт', 'лист', 'груд'];
  return `${day} ${months[month - 1]} ${year} р.`;
}

function formatTimeValue(value) {
  if (!value && value !== 0) {
    return '';
  }

  const rawValue = String(value).trim();

  if (/^\d{1,2}:\d{2}:\d{2}$/.test(rawValue)) {
    const [hours, minutes, seconds] = rawValue.split(':');
    return `${hours.padStart(2, '0')}:${minutes}:${seconds}`;
  }

  if (/^\d{1,2}:\d{2}$/.test(rawValue)) {
    const [hours, minutes] = rawValue.split(':');
    return `${hours.padStart(2, '0')}:${minutes}:00`;
  }

  if (typeof value === 'number' || /^\d+$/.test(rawValue)) {
    return formatDurationValue(value);
  }

  return rawValue;
}

function formatDurationValue(value) {
  if (!value && value !== 0) {
    return '';
  }

  const rawValue = String(value).trim();
  if (!/^\d+$/.test(rawValue) && typeof value !== 'number') {
    return rawValue;
  }

  const totalSeconds = Number(rawValue);
  if (Number.isNaN(totalSeconds)) {
    return String(value);
  }

  const hours = Math.floor(totalSeconds / 3600);
  const minutes = Math.floor((totalSeconds % 3600) / 60);
  const seconds = totalSeconds % 60;
  const parts = [];

  if (hours) {
    parts.push(`${hours} год`);
  }
  if (minutes) {
    parts.push(`${minutes} хв`);
  }
  if (seconds || parts.length === 0) {
    parts.push(`${seconds} сек`);
  }

  return parts.join(' ');
}

function formatDurationClockValue(value) {
  if (!value && value !== 0) {
    return '';
  }

  const rawValue = String(value).trim();
  const parsedDuration = parseSortableDuration(rawValue);

  if (/^\d{1,2}:\d{2}:\d{2}$/.test(rawValue)) {
    const [hours, minutes, seconds] = rawValue.split(':');
    return `${hours.padStart(2, '0')}:${minutes}:${seconds}`;
  }

  if (/^\d{1,2}:\d{2}$/.test(rawValue)) {
    const [hours, minutes] = rawValue.split(':');
    return `${hours.padStart(2, '0')}:${minutes}:00`;
  }

  if (parsedDuration >= 0) {
    const hours = String(Math.floor(parsedDuration / 3600)).padStart(2, '0');
    const minutes = String(Math.floor((parsedDuration % 3600) / 60)).padStart(2, '0');
    const seconds = String(parsedDuration % 60).padStart(2, '0');
    return `${hours}:${minutes}:${seconds}`;
  }

  if (!/^\d+$/.test(rawValue) && typeof value !== 'number') {
    return rawValue;
  }

  const totalSeconds = Number(rawValue);
  if (Number.isNaN(totalSeconds)) {
    return String(value);
  }

  const hours = String(Math.floor(totalSeconds / 3600)).padStart(2, '0');
  const minutes = String(Math.floor((totalSeconds % 3600) / 60)).padStart(2, '0');
  const seconds = String(totalSeconds % 60).padStart(2, '0');
  return `${hours}:${minutes}:${seconds}`;
}

function calculateLatenessValue(firstActionValue) {
  const normalizedFirstAction = formatTimeValue(firstActionValue);
  const firstActionSeconds = parseSortableDuration(normalizedFirstAction);
  const workdayStartSeconds = parseSortableDuration('09:00:00');

  if (firstActionSeconds < 0 || workdayStartSeconds < 0 || firstActionSeconds <= workdayStartSeconds) {
    return '';
  }

  return formatDurationClockValue(firstActionSeconds - workdayStartSeconds);
}

function calculateLeftEarlierValue(lastActivityValue) {
  const normalizedLastActivity = formatTimeValue(lastActivityValue);
  const lastActivitySeconds = parseSortableDuration(normalizedLastActivity);
  const workdayEndSeconds = parseSortableDuration('18:00:00');

  if (lastActivitySeconds < 0 || workdayEndSeconds < 0 || lastActivitySeconds >= workdayEndSeconds) {
    return '';
  }

  return formatDurationClockValue(workdayEndSeconds - lastActivitySeconds);
}

function findSummaryRecord(payload) {
  if (Array.isArray(payload)) {
    return payload.find((item) => item && typeof item === 'object') || {};
  }

  if (!payload || typeof payload !== 'object') {
    return {};
  }

  const preferredPaths = [
    'data.0',
    'data.rows.0',
    'data.items.0',
    'summary.0',
    'summary.rows.0',
    'rows.0',
    'items.0',
    'result.0',
    'result.rows.0',
    'response.0',
    'response.rows.0',
  ];

  for (const preferredPath of preferredPaths) {
    const candidate = getNestedValue(payload, preferredPath);
    if (candidate && typeof candidate === 'object') {
      return candidate;
    }
  }

  for (const value of Object.values(payload)) {
    if (Array.isArray(value) && value[0] && typeof value[0] === 'object') {
      return value[0];
    }

    if (value && typeof value === 'object') {
      const nested = findSummaryRecord(value);
      if (nested && Object.keys(nested).length > 0) {
        return nested;
      }
    }
  }

  return payload;
}

function mapSummaryRow(summaryPayload, apiReportDate, reportEmployeeName = '') {
  const summaryRecord = findSummaryRecord(summaryPayload);
  const userName = getFirstDefinedValue(summaryRecord, [
    'user.name',
    'user.fullName',
    'employee.name',
    'employee.fullName',
    'name',
    'fullName',
  ]);
  const userEmail = getFirstDefinedValue(summaryRecord, [
    'user.email',
    'employee.email',
    'email',
  ]);
  const userDisplay = escapeHtml(reportEmployeeName || userName || userEmail || '');

  const firstActionValue = getFirstDefinedValue(summaryRecord, [
    'time_start',
    'firstAction',
    'first_action',
    'firstActionTime',
    'activity.firstAction',
  ]);

  const lastActivityValue = getFirstDefinedValue(summaryRecord, [
    'time_end',
    'lastActivity',
    'last_activity',
    'lastActivityTime',
    'activity.lastActivity',
  ]);

  return {
    'Період': formatPeriodLabel(apiReportDate),
    'Працівник': userDisplay || '',
    'Група': getFirstDefinedValue(summaryRecord, [
      'group',
      'group.name',
      'team.name',
      'department.name',
      'groupName',
    ]),
    'Перша дія': formatTimeValue(firstActionValue),
    'Остання активність': formatTimeValue(lastActivityValue),
    'Запізнення': calculateLatenessValue(firstActionValue),
    'Пішов раніше належного': calculateLeftEarlierValue(lastActivityValue),
    'Непродуктивний час': formatDurationClockValue(getFirstDefinedValue(summaryRecord, [
      'distracting',
      'unproductive',
      'unproductiveTime',
      'times.unproductive',
      'durations.unproductive',
    ])),
    'Невідомо/нейтрально': formatDurationClockValue(getFirstDefinedValue(summaryRecord, [
      'uncategorized',
      'neutral',
      'neutralTime',
      'unknown',
      'unknownNeutral',
      'times.neutral',
      'durations.neutral',
    ])),
    'Продуктивно': formatDurationClockValue(getFirstDefinedValue(summaryRecord, [
      'productive',
      'productiveTime',
      'times.productive',
      'durations.productive',
    ])),
    'Загальний час': formatDurationClockValue(getFirstDefinedValue(summaryRecord, [
      'total',
      'totalTime',
      'times.total',
      'durations.total',
    ])),
  };
}

function decodeHtmlEntities(value) {
  return String(value)
      .replaceAll('&nbsp;', ' ')
      .replaceAll('&amp;', '&')
      .replaceAll('&lt;', '<')
      .replaceAll('&gt;', '>')
      .replaceAll('&quot;', '"')
      .replaceAll('&#39;', "'");
}

function stripHtmlTags(value) {
  return decodeHtmlEntities(
      String(value)
          .replace(/<br\s*\/?>/gi, '\n')
          .replace(/<\/p>/gi, '\n')
          .replace(/<[^>]+>/g, ' ')
          .replace(/\s+\n/g, '\n')
          .replace(/\n\s+/g, '\n')
          .replace(/[ \t]+/g, ' ')
          .trim(),
  );
}

function parseHtmlTableCells(rowHtml) {
  const cells = [];
  const cellRegex = /<(td|th)\b([^>]*)>([\s\S]*?)<\/\1>/gi;
  let match;

  while ((match = cellRegex.exec(rowHtml)) !== null) {
    const [, tagName, rawAttributes, rawContent] = match;
    const colspanMatch = rawAttributes.match(/colspan=["']?(\d+)["']?/i);
    const rowspanMatch = rawAttributes.match(/rowspan=["']?(\d+)["']?/i);

    cells.push({
      type: tagName.toLowerCase(),
      value: stripHtmlTags(rawContent),
      html: rawContent.trim(),
      colspan: colspanMatch ? Number(colspanMatch[1]) : 1,
      rowspan: rowspanMatch ? Number(rowspanMatch[1]) : 1,
    });
  }

  return cells;
}

function extractWorksheetTableRows(excelFileContent) {
  const tableMatch = excelFileContent.match(/<table\b[\s\S]*?<\/table>/i);
  if (!tableMatch) {
    return [];
  }

  const rows = [];
  const rowRegex = /<tr\b[^>]*>([\s\S]*?)<\/tr>/gi;
  let rowMatch;

  while ((rowMatch = rowRegex.exec(tableMatch[0])) !== null) {
    rows.push(parseHtmlTableCells(rowMatch[1]));
  }

  return rows;
}

function getPrimaryCellValue(primaryRows, rowNumber, columnIndex) {
  const row = primaryRows[rowNumber - 1];
  if (!row) {
    return '';
  }

  const cell = row[columnIndex];
  return cell ? String(cell.value ?? '') : '';
}

function buildSummaryRows(summaryRow) {
  const columns = Object.keys(summaryRow);
  const values = Object.values(summaryRow);

  return [
    columns.map((column) => ({
      type: 'th',
      value: column,
      isSummaryCell: true,
    })),
    values.map((value, index) => ({
      type: 'td',
      value: columns[index] === 'Працівник'
          ? stripHtmlTags(String(value).replace(/<br\s*\/?>/gi, '\n'))
          : String(value),
      html: columns[index] === 'Працівник' ? String(value) : '',
      column: columns[index],
      isSummaryCell: true,
    })),
  ];
}

function humanizeColumnName(key) {
  return String(key)
      .replace(/([a-z0-9])([A-Z])/g, '$1 $2')
      .replaceAll('_', ' ')
      .replaceAll('-', ' ')
      .replace(/\s+/g, ' ')
      .trim()
      .replace(/^./, (letter) => letter.toUpperCase());
}

function isPlainObject(value) {
  return Boolean(value) && typeof value === 'object' && !Array.isArray(value);
}

function collectObjectArrays(payload, results = [], visited = new Set()) {
  if (!payload || typeof payload !== 'object' || visited.has(payload)) {
    return results;
  }
  visited.add(payload);

  if (Array.isArray(payload)) {
    if (payload.length > 0 && payload.every((item) => isPlainObject(item))) {
      results.push(payload);
    }

    payload.forEach((item) => collectObjectArrays(item, results, visited));
    return results;
  }

  Object.values(payload).forEach((value) => collectObjectArrays(value, results, visited));
  return results;
}

function rowMatchesCategory(row, categoryKeys) {
  const normalizedCategoryKeys = categoryKeys.map((key) => key.toLowerCase());
  const candidateFieldNames = ['productivity', 'type', 'category', 'group', 'kind', 'status'];

  const candidateValues = Object.entries(row)
      .filter(([key, value]) => (
          value !== null
          && value !== undefined
          && value !== ''
          && !Array.isArray(value)
          && !isPlainObject(value)
          && candidateFieldNames.includes(key.toLowerCase())
      ))
      .map(([, value]) => String(value).trim().toLowerCase());

  if (candidateValues.some((value) => normalizedCategoryKeys.includes(value))) {
    return true;
  }

  const searchable = JSON.stringify(row).toLowerCase();
  return normalizedCategoryKeys.some((key) => {
    const escapedKey = key.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    return new RegExp(`(^|[^a-z])${escapedKey}([^a-z]|$)`).test(searchable);
  });
}

function pickCategoryRows(reportPayload, categoryKeys) {
  const arrays = collectObjectArrays(reportPayload);
  const matchingArrays = arrays.filter((rows) => rows.some((row) => rowMatchesCategory(row, categoryKeys)));

  const sourceRows = matchingArrays[0] || [];
  return sourceRows.filter((row) => rowMatchesCategory(row, categoryKeys));
}

function formatReportValue(key, value) {
  if (value === null || value === undefined || value === '') {
    return '';
  }

  if (typeof value === 'object') {
    return JSON.stringify(value);
  }

  const normalizedKey = String(key).toLowerCase();
  if (
      normalizedKey.includes('time')
      || normalizedKey.includes('duration')
      || normalizedKey.includes('spent')
      || normalizedKey.includes('seconds')
  ) {
    return formatDurationClockValue(value);
  }

  return String(value);
}

function getReportColumnValue(row, candidateKeys) {
  for (const candidateKey of candidateKeys) {
    const exactValue = row[candidateKey];
    if (exactValue !== null && exactValue !== undefined && exactValue !== '') {
      return exactValue;
    }

    const normalizedCandidateKey = candidateKey.toLowerCase();
    const matchedKey = Object.keys(row).find((key) => key.toLowerCase() === normalizedCandidateKey);
    if (matchedKey) {
      const matchedValue = row[matchedKey];
      if (matchedValue !== null && matchedValue !== undefined && matchedValue !== '') {
        return matchedValue;
      }
    }
  }

  return '';
}

function parseSortableDuration(value) {
  if (value === null || value === undefined || value === '') {
    return -1;
  }

  if (typeof value === 'number') {
    return value;
  }

  const rawValue = String(value).trim().toLowerCase();
  if (/^\d+$/.test(rawValue)) {
    return Number(rawValue);
  }

  if (/^\d{1,2}:\d{2}(:\d{2})?$/.test(rawValue)) {
    const [hours = '0', minutes = '0', seconds = '0'] = rawValue.split(':');
    return (Number(hours) * 3600) + (Number(minutes) * 60) + Number(seconds);
  }

  const hoursMatch = rawValue.match(/(\d+)\s*(год|hours?|hrs?|h)\b/);
  const minutesMatch = rawValue.match(/(\d+)\s*(хв|min(?:ute)?s?|m)\b/);
  const secondsMatch = rawValue.match(/(\d+)\s*(сек|sec(?:ond)?s?|s)\b/);

  if (hoursMatch || minutesMatch || secondsMatch) {
    return (
        (Number(hoursMatch?.[1] || 0) * 3600)
        + (Number(minutesMatch?.[1] || 0) * 60)
        + Number(secondsMatch?.[1] || 0)
    );
  }

  return -1;
}

function localizeDayReportValue(value) {
  const normalizedValue = String(value ?? '').trim().toLowerCase();
  const localizedValues = {
    neutral: 'Нейтрально',
    productive: 'Продуктивно',
    unproductive: 'Непродуктивно',
  };

  return localizedValues[normalizedValue] || String(value ?? '');
}

function mapReportRows(rows) {
  if (!rows.length) {
    return {
      columns: ['Продуктивність', 'Діяльність', 'Категорія', 'Загальний час'],
      rows: [['', 'Немає даних', '', '']],
    };
  }

  const timeAliases = ['time', 'duration', 'spent', 'seconds'];
  const sortedRows = [...rows].sort((leftRow, rightRow) => {
    return (
        parseSortableDuration(getReportColumnValue(rightRow, timeAliases))
        - parseSortableDuration(getReportColumnValue(leftRow, timeAliases))
    );
  });

  return {
    columns: ['Продуктивність', 'Діяльність', 'Категорія', 'Загальний час'],
    rows: sortedRows.map((row) => {
      const rawCategory = String(getReportColumnValue(row, ['category']) || '').trim();
      const localizedCategory = rawCategory.toLowerCase() === 'uncategorized' ? '' : localizeDayReportValue(rawCategory);
      const rawProductivity = getReportColumnValue(row, ['productivity', 'type']);

      return [
        localizeDayReportValue(formatReportValue('productivity', rawProductivity)),
        stripHtmlTags(formatReportValue('activity', getReportColumnValue(row, ['activity', 'title', 'name', 'caption', 'application', 'app', 'domain', 'url']))),
        localizedCategory,
        formatReportValue('time', getReportColumnValue(row, timeAliases)),
      ];
    }),
  };
}

function buildDayReportRows(reportPayload) {
  const rows = [];

  DAY_REPORT_CATEGORIES.forEach(({ title, keys }, categoryIndex) => {
    const categoryRows = pickCategoryRows(reportPayload, keys);
    const table = mapReportRows(categoryRows);
    const totalDurationSeconds = categoryRows.reduce((sum, row) => (
        sum + Math.max(0, parseSortableDuration(getReportColumnValue(row, ['time', 'duration', 'spent', 'seconds'])))
    ), 0);
    const totalDurationValue = formatDurationClockValue(totalDurationSeconds);

    if (rows.length > 0) {
      rows.push([{ type: 'td', value: '', isSpacerCell: true }]);
    }

    rows.push([
      { type: 'th', value: title, isSectionTitle: true, categoryIndex },
      { type: 'th', value: '', isSectionTitle: true, categoryIndex },
      { type: 'th', value: '', isSectionTitle: true, categoryIndex },
      { type: 'th', value: totalDurationValue, isSectionTitle: true, categoryIndex },
    ]);
    rows.push(table.columns.map((column) => ({
      type: 'th',
      value: column,
      isReportCell: true,
      isReportHeader: true,
      categoryIndex,
    })));
    table.rows.forEach((tableRow) => {
      rows.push(tableRow.map((value) => ({
        type: 'td',
        value,
        isReportCell: true,
        categoryIndex,
      })));
    });
  });

  return rows;
}

function durationSecondsValue(value) {
  const seconds = parseSortableDuration(value);
  return seconds >= 0 ? seconds : 0;
}

// Структуровані дані дня для історичної БД на боці Laravel: сирі секунди
// замість відформатованих рядків, щоб по них можна було рахувати аналітику.
function buildHistoryData(summaryPayload, dayReportPayload, apiReportDate, idleActivitiesRows = null) {
  const summaryRecord = findSummaryRecord(summaryPayload);

  const firstActionValue = getFirstDefinedValue(summaryRecord, [
    'time_start',
    'firstAction',
    'first_action',
    'firstActionTime',
    'activity.firstAction',
  ]);
  const lastActivityValue = getFirstDefinedValue(summaryRecord, [
    'time_end',
    'lastActivity',
    'last_activity',
    'lastActivityTime',
    'activity.lastActivity',
  ]);

  const stats = {
    first_action: formatTimeValue(firstActionValue) || null,
    last_action: formatTimeValue(lastActivityValue) || null,
    lateness_seconds: durationSecondsValue(calculateLatenessValue(firstActionValue)),
    left_early_seconds: durationSecondsValue(calculateLeftEarlierValue(lastActivityValue)),
    productive_seconds: durationSecondsValue(getFirstDefinedValue(summaryRecord, [
      'productive',
      'productiveTime',
      'times.productive',
      'durations.productive',
    ])),
    unproductive_seconds: durationSecondsValue(getFirstDefinedValue(summaryRecord, [
      'distracting',
      'unproductive',
      'unproductiveTime',
      'times.unproductive',
      'durations.unproductive',
    ])),
    neutral_seconds: durationSecondsValue(getFirstDefinedValue(summaryRecord, [
      'uncategorized',
      'neutral',
      'neutralTime',
      'unknown',
      'unknownNeutral',
      'times.neutral',
      'durations.neutral',
    ])),
    total_seconds: durationSecondsValue(getFirstDefinedValue(summaryRecord, [
      'total',
      'totalTime',
      'times.total',
      'durations.total',
    ])),
  };

  const activities = [];
  DAY_REPORT_CATEGORIES.forEach(({ keys }) => {
    pickCategoryRows(dayReportPayload, keys).forEach((row) => {
      const rawCategory = String(getReportColumnValue(row, ['category']) || '').trim();

      activities.push({
        productivity: keys[0],
        name: stripHtmlTags(formatReportValue('activity', getReportColumnValue(row, ['activity', 'title', 'name', 'caption', 'application', 'app', 'domain', 'url']))),
        category: rawCategory.toLowerCase() === 'uncategorized' ? null : (rawCategory || null),
        duration_seconds: durationSecondsValue(getReportColumnValue(row, ['time', 'duration', 'spent', 'seconds'])),
      });
    });
  });

  const idleActivities = Array.isArray(idleActivitiesRows)
      ? idleActivitiesRows.map((row) => row.map((cell) => String(cell?.value ?? '')))
      : null;

  return {
    date: apiReportDate,
    stats,
    activities,
    idle_activities: idleActivities,
  };
}

function buildSecondaryRows(summaryPayload, dayReportPayload, apiReportDate, reportEmployeeName = '', idleActivitiesRows = null) {
  const rows = [
    ...buildSummaryRows(mapSummaryRow(summaryPayload, apiReportDate, reportEmployeeName)),
    [{ type: 'td', value: '', isSpacerCell: true }],
    ...buildDayReportRows(dayReportPayload),
  ];

  if (idleActivitiesRows && idleActivitiesRows.length > 0) {
    const maxCols = Math.max(...idleActivitiesRows.map((row) => row.length), 1);
    rows.push([{ type: 'td', value: '', isSpacerCell: true }]);
    rows.push(Array.from({ length: maxCols }, (_, i) => ({
      type: 'th',
      value: i === 0 ? 'Офлайн активність' : '',
      isSectionTitle: true,
      categoryIndex: 3,
    })));
    idleActivitiesRows.forEach((tableRow, rowIndex) => {
      rows.push(tableRow.map((cell) => ({
        type: rowIndex === 0 ? 'th' : 'td',
        value: cell.value,
        isReportCell: true,
        isReportHeader: rowIndex === 0,
        categoryIndex: 3,
      })));
    });
  }

  return rows;
}

function buildTaskTableRows() {
  const rows = [
    [
      { type: 'th', value: '', isTaskTableCell: true, isTaskTableHeader: true },
      { type: 'th', value: 'Назва таски', isTaskTableCell: true, isTaskTableHeader: true },
      { type: 'th', value: 'Коментар', isTaskTableCell: true, isTaskTableHeader: true },
      { type: 'th', value: 'Час', isTaskTableCell: true, isTaskTableHeader: true },
    ],
  ];

  for (let rowIndex = 0; rowIndex < TASK_TABLE_EMPTY_ROW_COUNT; rowIndex += 1) {
    rows.push(Array.from({ length: 4 }, () => ({
      type: 'td',
      value: '',
      isTaskTableCell: true,
    })));
  }

  return rows;
}

function buildPrimaryDeltaConfig(primaryRows) {
  const firstFormulaRowNumber = 3;
  const firstPrimaryDataRowNumber = 2;
  const lastFormulaRowNumber = primaryRows.length;
  const totalRowNumber = lastFormulaRowNumber >= firstPrimaryDataRowNumber
      ? lastFormulaRowNumber + 1
      : null;

  if (lastFormulaRowNumber < firstFormulaRowNumber) {
    return {
      formulas: [],
      primaryDurationTotalFormula: totalRowNumber
          ? `=SUM(D${firstPrimaryDataRowNumber}:D${lastFormulaRowNumber})`
          : null,
      totalFormula: null,
      totalRowNumber,
    };
  }

  const formulas = primaryRows.map((_, rowIndex) => {
    if (rowIndex < firstFormulaRowNumber - 1) {
      return null;
    }

    const rowNumber = rowIndex + 1;
    return `=G${rowNumber}-H${rowNumber - 1}`;
  });

  return {
    formulas,
    primaryDurationTotalFormula: `=SUM(D${firstPrimaryDataRowNumber}:D${lastFormulaRowNumber})`,
    totalFormula: `=SUM(K${firstFormulaRowNumber}:K${lastFormulaRowNumber})`,
    totalRowNumber,
  };
}

function mergeTablesIntoSheet(primaryRows, secondaryRows, secondaryStartColumnIndex = 10, floatingBlocks = []) {
  const floatingBlockRowCounts = floatingBlocks.map(({ startRowIndex, rows }) => startRowIndex + rows.length);
  const totalRows = Math.max(primaryRows.length, secondaryRows.length, ...floatingBlockRowCounts);
  const mergedRows = [];

  for (let rowIndex = 0; rowIndex < totalRows; rowIndex += 1) {
    const primaryRow = primaryRows[rowIndex] || [];
    const secondaryRow = secondaryRows[rowIndex] || [];
    const row = [];

    primaryRow.forEach((cell) => {
      row.push(cell);
      for (let repeat = 1; repeat < (cell.colspan || 1); repeat += 1) {
        row.push({ type: 'td', value: '' });
      }
    });

    while (row.length < secondaryStartColumnIndex) {
      row.push({ type: 'td', value: '' });
    }

    secondaryRow.forEach((cell) => {
      row.push(cell);
      for (let repeat = 1; repeat < (cell.colspan || 1); repeat += 1) {
        row.push({ type: 'td', value: '' });
      }
    });

    floatingBlocks.forEach(({ startColumnIndex, startRowIndex, rows }) => {
      if (rowIndex < startRowIndex || rowIndex >= startRowIndex + rows.length) {
        return;
      }

      const floatingRow = rows[rowIndex - startRowIndex] || [];
      while (row.length < startColumnIndex) {
        row.push({ type: 'td', value: '' });
      }

      floatingRow.forEach((cell) => {
        row.push(cell);
        for (let repeat = 1; repeat < (cell.colspan || 1); repeat += 1) {
          row.push({ type: 'td', value: '' });
        }
      });
    });

    mergedRows.push(row);
  }

  return mergedRows;
}

function buildCombinedExcelHtml(sheetRows) {
  const sheetHtmlRows = sheetRows.map((row) => {
    const cellsHtml = row.map((cell, cellIndex) => {
      const tagName = cell.type === 'th' ? 'th' : 'td';
      const attributes = [];
      const styles = [];
      const cellValue = cell.html && cell.column === 'Працівник'
          ? cell.html
          : escapeHtml(cell.value ?? '');

      if (cell.colspan && cell.colspan > 1) {
        attributes.push(`colspan="${cell.colspan}"`);
      }

      if (cell.rowspan && cell.rowspan > 1) {
        attributes.push(`rowspan="${cell.rowspan}"`);
      }

      if (cell.isSummaryCell || cell.isPrimaryDeltaCell) {
        styles.push('border:1px solid #d9d9d9');
      }

      if (cell.isTaskTableCell) {
        styles.push('border:1px solid #000000');
      }

      if (cell.isTaskTableHeader) {
        styles.push('background:#ffff00', 'font-weight:400', 'text-align:left', 'color:#000000');
      } else if (cell.isSectionTitle) {
        styles.push('background:#fff59d', 'font-weight:700', 'text-align:left');
      } else if (cell.isReportHeader) {
        styles.push('background:#eef2ff', 'font-weight:700');
      } else if (cell.isReportTotal) {
        styles.push('background:#f8fafc', 'font-weight:700');
      } else if (cell.isSpacerCell) {
        styles.push('background:#ffffff', 'border:none', 'padding:8px 12px');
      }

      if (cell.isSummaryCell && cell.column === 'Загальний час' && tagName === 'td') {
        styles.push('background:#fff59d', 'font-weight:700');
      }

      if (cell.column === 'Запізнення' || cell.column === 'Непродуктивний час') {
        styles.push('color:#d93025');
      } else if (cell.column === 'Продуктивно') {
        styles.push('color:#2e7d32');
      } else if (cell.column === 'Загальний час') {
        styles.push('color:#1a73e8');
      }

      if (cell.isDurationCell) {
        styles.push('mso-number-format:"[h]\\:mm\\:ss"', 'text-align:center');
      }

      if (cell.isPrimaryDeltaTotal) {
        styles.push('font-weight:700');
      }

      if (styles.length > 0) {
        attributes.push(`style="${styles.join(';')}"`);
      }

      return `<${tagName}${attributes.length ? ` ${attributes.join(' ')}` : ''}>${cellValue}</${tagName}>`;
    }).join('');

    return `<tr>${cellsHtml}</tr>`;
  }).join('\n');

  return `<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8" />
  <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
  <style>
    body { font-family: Arial, sans-serif; margin: 0; padding: 12px; }
    table { border-collapse: collapse; }
    th, td {
      border: 1px solid #d9d9d9;
      padding: 14px 12px;
      text-align: center;
      vertical-align: middle;
      font-size: 11px;
      line-height: 1.4;
      color: #334155;
    }
    th {
      background: #f3f4f6;
      font-weight: 400;
      color: #1f2937;
    }
    td { background: #ffffff; }
  </style>
</head>
<body>
  <table>
    ${sheetHtmlRows}
  </table>
</body>
</html>`;
}

// JWT живе ~300 секунд, тому кешуємо його із запасом і оновлюємо при потребі.
let cachedJwt = null;

// Пауза перед кожною наступною спробою отримати токен.
const JWT_RETRY_DELAYS_MS = [3_000, 10_000, 20_000];

/**
 * Тіло відповіді як JSON. Замість HTML Yaware віддає сторінку входу (сесія
 * злетіла) або сторінку помилки/техобслуговування — тоді ловимо це тут і
 * пояснюємо людською мовою, а не сирим SyntaxError про «<!DOCTYPE».
 */
function parseJsonResponseBody(body, humanMessage) {
  try {
    return JSON.parse(body);
  } catch {
    const error = new Error(humanMessage);
    error.code = 'YAWARE_HTML_RESPONSE';
    throw error;
  }
}

async function requestJwtToken() {
  const response = await context.request.get(JWT_GENERATE_URL);
  const body = await response.text();

  if (!response.ok()) {
    throw new Error(`JWT request failed with ${response.status()}: ${body}`);
  }

  return parseJsonResponseBody(
      body,
      'Yaware не видав токен доступу — замість нього повернулась HTML-сторінка (сесія злетіла або сервіс тимчасово недоступний).',
  );
}

/**
 * Повторний вхід перед наступною спробою: якщо сесія жива, Yaware одразу
 * відкриває застосунок і форми входу на сторінці немає — така «помилка» входу
 * нічого не означає, справжня перевірка це повторний запит токена.
 */
async function renewYawareSession() {
  // Окрема вкладка (кукі спільні з контекстом): GraphQL-запити йдуть паралельно
  // з налаштуванням фільтрів, і перехід основної вкладки на логін зірвав би їх.
  const loginPage = await context.newPage();
  try {
    await loginPage.goto(LOGIN_URL, { waitUntil: 'domcontentloaded' });
    await performLogin(loginPage, yawareEmail, yawarePassword);
  } catch (error) {
    if (error?.code === 'INVALID_CREDENTIALS') {
      throw error;
    }
  } finally {
    await loginPage.close().catch(() => null);
  }
}

async function fetchJwtToken() {
  if (cachedJwt && cachedJwt.expiresAt > Date.now()) {
    return cachedJwt.token;
  }

  let payload = null;

  for (let attempt = 0; ; attempt += 1) {
    try {
      payload = await requestJwtToken();
      break;
    } catch (error) {
      if (error?.code !== 'YAWARE_HTML_RESPONSE' || attempt >= JWT_RETRY_DELAYS_MS.length) {
        throw error;
      }

      await new Promise((resolve) => setTimeout(resolve, JWT_RETRY_DELAYS_MS[attempt]));
      await renewYawareSession();
    }
  }

  const { token, lifetime } = payload;
  if (!token) {
    throw new Error('JWT request did not return a token.');
  }

  const lifetimeSeconds = Math.max(Number(lifetime) || 300, 60);
  cachedJwt = { token, expiresAt: Date.now() + (lifetimeSeconds - 30) * 1000 };
  return token;
}

async function graphqlRequest(requestLabel, query, variables = {}) {
  const token = await fetchJwtToken();
  const response = await context.request.post(GRAPHQL_API_URL, {
    headers: {
      'Content-Type': 'application/json',
      Authorization: `Bearer ${token}`,
    },
    data: { query, variables },
  });

  const body = await response.text();

  if (!response.ok()) {
    throw new Error(`${requestLabel} failed with ${response.status()}: ${body}`);
  }

  const payload = parseJsonResponseBody(
      body,
      'Yaware повернув HTML-сторінку замість даних звіту — сервіс тимчасово недоступний. Спробуйте за кілька хвилин.',
  );

  if (Array.isArray(payload?.errors) && payload.errors.length > 0) {
    throw new Error(`${requestLabel} failed: ${JSON.stringify(payload.errors)}`);
  }

  return payload?.data;
}

// id користувача у GraphQL — base64 від "accountId|USER|userId";
// веб-фільтрам звітів і запитам по даті потрібен саме числовий userId.
function decodeGraphqlUserId(globalId) {
  try {
    const decoded = Buffer.from(String(globalId), 'base64').toString('utf8');
    const numericId = decoded.split('|').pop();
    return /^\d+$/.test(numericId) ? numericId : '';
  } catch {
    return '';
  }
}

async function fetchUserByEmail() {
  const data = await graphqlRequest('getUsers GraphQL request', `
    query {
      account {
        getUsersByFilter(filter: {roleIn: [USER], isActive: true}, limit: 500, internal: true) {
          users { id firstName lastName email }
        }
      }
    }
  `);

  const users = data?.account?.getUsersByFilter?.users || [];
  return {
    data: users.map((user) => ({
      id: decodeGraphqlUserId(user.id),
      name: [user.firstName, user.lastName].filter(Boolean).join(' ').trim(),
      email: user.email || '',
    })),
  };
}

function collectObjectRecords(payload, results = [], visited = new Set()) {
  if (!payload || typeof payload !== 'object' || visited.has(payload)) {
    return results;
  }
  visited.add(payload);

  if (Array.isArray(payload)) {
    payload.forEach((item) => {
      if (item && typeof item === 'object') {
        results.push(item);
        collectObjectRecords(item, results, visited);
      }
    });
    return results;
  }

  Object.values(payload).forEach((value) => {
    if (value && typeof value === 'object') {
      collectObjectRecords(value, results, visited);
    }
  });

  return results;
}

function normalizeEmail(value) {
  return String(value || '').trim().toLowerCase();
}

function resolveEmployeeFromUsersPayload(usersPayload, email) {
  const normalizedEmail = normalizeEmail(email);
  const records = collectObjectRecords(usersPayload);

  const matchingRecord = records.find((record) => normalizeEmail(getFirstDefinedValue(record, [
    'email',
    'user.email',
    'employee.email',
    'login',
    'username',
  ])) === normalizedEmail);

  if (!matchingRecord) {
    throw new Error(`Could not find a Yaware user for email: ${email}`);
  }

  const employeeId = getFirstDefinedValue(matchingRecord, [
    'id',
    'userId',
    'user_id',
    'employeeId',
    'employee_id',
    'uid',
    'user.id',
    'employee.id',
  ]);

  if (!employeeId) {
    throw new Error(`Yaware user found for ${email}, but the API response did not include an employee id.`);
  }

  const employeeName = getFirstDefinedValue(matchingRecord, [
    'name',
    'fullName',
    'user.name',
    'user.fullName',
    'employee.name',
    'employee.fullName',
  ]) || [
    getFirstDefinedValue(matchingRecord, ['firstname', 'first_name', 'firstName']),
    getFirstDefinedValue(matchingRecord, ['lastname', 'last_name', 'lastName']),
  ].filter(Boolean).join(' ').trim();

  return {
    id: String(employeeId).trim(),
    name: String(employeeName || '').trim(),
  };
}

async function fetchEmployeeByEmail(email) {
  const usersPayload = await fetchUserByEmail(email);
  return resolveEmployeeFromUsersPayload(usersPayload, email);
}

// GraphQL повертає час як "РРРР-ММ-ДД ГГ:ХХ:СС", а формувальник звіту чекає "ГГ:ХХ:СС".
function extractClockTime(value) {
  const match = String(value || '').match(/\d{1,2}:\d{2}(?::\d{2})?$/);
  return match ? match[0] : '';
}

async function fetchSummaryByPeriod(apiReportDate, employeeId) {
  const data = await graphqlRequest('Summary GraphQL request', `
    query($filter: ProductivityGridFilterInput!) {
      account {
        getProductivityGridByFilter(filter: $filter, limit: 10, offset: 0, groupBy: DAY) {
          ... on ProductivityGridDaily {
            productivityGridDaily {
              userFullName email groupName
              productive unproductive neutral total
              firstActivity lastActivity
            }
          }
        }
      }
    }
  `, {
    filter: {
      dateRange: { fromISO: apiReportDate, toISO: apiReportDate },
      userIdIn: [String(employeeId)],
    },
  });

  const rows = data?.account?.getProductivityGridByFilter?.productivityGridDaily || [];
  return {
    data: rows.map((row) => ({
      name: row.userFullName || '',
      email: row.email || '',
      group: row.groupName || '',
      firstAction: extractClockTime(row.firstActivity),
      lastActivity: extractClockTime(row.lastActivity),
      productive: row.productive ?? 0,
      unproductive: row.unproductive ?? 0,
      neutral: row.neutral ?? 0,
      total: row.total ?? 0,
    })),
  };
}

// Коди продуктивності у GraphQL: 10 — продуктивно, 0 — нейтрально, -10 — непродуктивно.
function mapProductivityCode(code) {
  const numericCode = Number(code) || 0;
  if (numericCode > 0) {
    return 'productive';
  }
  if (numericCode < 0) {
    return 'unproductive';
  }
  return 'neutral';
}

async function fetchReportByDay(apiReportDate, employeeId) {
  const data = await graphqlRequest('ReportByDay GraphQL request', `
    query($filter: ApplicationGridFilterInput!) {
      account {
        getApplicationGridByFilter(filter: $filter, limit: 1000, offset: 0, groupBy: DAY) {
          ... on ApplicationGrid {
            applicationGrid { appName productivity time }
          }
        }
      }
    }
  `, {
    filter: {
      dateRange: { fromISO: apiReportDate, toISO: apiReportDate },
      userIdIn: [String(employeeId)],
      // Разом з офлайн-активністю суми категорій сходяться зі зведенням до секунди.
      includeIdleActivity: true,
    },
  });

  const rows = data?.account?.getApplicationGridByFilter?.applicationGrid || [];
  return {
    data: rows.map((row) => ({
      activity: row.appName || '',
      time: row.time ?? 0,
      productivity: mapProductivityCode(row.productivity),
    })),
  };
}

function parseClockSeconds(value) {
  const match = /(\d{1,2}):(\d{2}):(\d{2})\s*$/.exec(String(value || ''));
  if (!match) {
    return null;
  }
  return Number(match[1]) * 3600 + Number(match[2]) * 60 + Number(match[3]);
}

// Хронологія дій із точністю до секунди. Без офлайн-активності рядки збігаються
// з XLS-звітом «за додатками» (там час обрізаний до хвилин), а з нею видно,
// чим заповнені паузи між рядками.
async function fetchActivityTimeline(apiReportDate, employeeId) {
  const query = `
    query($filter: ApplicationGridFilterInput!) {
      account {
        getApplicationGridByFilter(filter: $filter, limit: 10000, offset: 0, groupBy: DAY, orderField: timeStart, orderDirection: ASC) {
          ... on ApplicationGridWithTitle {
            totalCount
            applicationGridWithTitle { timeStart timeEnd }
          }
        }
      }
    }
  `;

  const loadIntervals = async (includeIdleActivity) => {
    const data = await graphqlRequest('ActivityTimeline GraphQL request', query, {
      filter: {
        dateRange: { fromISO: apiReportDate, toISO: apiReportDate },
        userIdIn: [String(employeeId)],
        groupDayByApplication: true,
        includeIdleActivity,
      },
    });
    const grid = data?.account?.getApplicationGridByFilter;
    const rows = grid?.applicationGridWithTitle || [];
    if (Number(grid?.totalCount) > rows.length) {
      throw new Error(`API повернув ${rows.length} з ${grid.totalCount} дій.`);
    }
    return rows.map((row) => ({
      start: parseClockSeconds(row.timeStart),
      end: parseClockSeconds(row.timeEnd),
    }));
  };

  const [windows, allEvents] = await Promise.all([loadIntervals(false), loadIntervals(true)]);
  return { windows, allEvents };
}

async function saveCombinedHtmlExcel(summaryPayload, dayReportPayload, downloadedFilePath, outputDirectory, apiReportDate, reportEmployeeName = '', idleActivitiesRows = null) {
  const downloadedFileBuffer = await fs.readFile(downloadedFilePath);
  const downloadedFileContentUtf8 = downloadedFileBuffer.toString('utf8');
  const downloadedFileContentLatin1 = downloadedFileBuffer.toString('latin1');
  const downloadedFileContent = downloadedFileContentUtf8.includes('<table')
      ? downloadedFileContentUtf8
      : downloadedFileContentLatin1;
  const primaryRows = extractWorksheetTableRows(downloadedFileContent);
  if (primaryRows.length === 0) {
    throw emptyDayError();
  }

  const secondaryRows = buildSecondaryRows(summaryPayload, dayReportPayload, apiReportDate, reportEmployeeName, idleActivitiesRows);
  const taskTableRows = buildTaskTableRows();
  const primaryDeltaConfig = buildPrimaryDeltaConfig(primaryRows);
  const combinedRows = mergeTablesIntoSheet(primaryRows, secondaryRows, SUMMARY_START_COLUMN_INDEX, [
    {
      startColumnIndex: TASK_TABLE_START_COLUMN_INDEX,
      startRowIndex: TASK_TABLE_START_ROW_INDEX,
      rows: taskTableRows,
    },
  ]);

  const primaryEmployeeName = getPrimaryCellValue(primaryRows, 2, 4);
  if (primaryEmployeeName && combinedRows[1]?.[SUMMARY_START_COLUMN_INDEX + 1]) {
    combinedRows[1][SUMMARY_START_COLUMN_INDEX + 1] = {
      ...combinedRows[1][SUMMARY_START_COLUMN_INDEX + 1],
      value: primaryEmployeeName,
      html: '',
    };
  }

  for (let rowIndex = 1; rowIndex < primaryRows.length; rowIndex += 1) {
    if (!combinedRows[rowIndex]?.[3]) {
      continue;
    }

    combinedRows[rowIndex][3] = {
      ...combinedRows[rowIndex][3],
      isDurationCell: true,
    };
  }

  primaryDeltaConfig.formulas.forEach((formula, rowIndex) => {
    if (!formula) {
      return;
    }

    while (combinedRows[rowIndex].length < 11) {
      combinedRows[rowIndex].push({ type: 'td', value: '' });
    }

    combinedRows[rowIndex][10] = {
      type: 'td',
      value: formula,
      isDurationCell: true,
      isPrimaryDeltaCell: true,
    };
  });

  if (primaryDeltaConfig.totalFormula && primaryDeltaConfig.totalRowNumber) {
    const totalRowIndex = primaryDeltaConfig.totalRowNumber - 1;
    while (combinedRows.length <= totalRowIndex) {
      combinedRows.push([]);
    }

    while (combinedRows[totalRowIndex].length < 4) {
      combinedRows[totalRowIndex].push({ type: 'td', value: '' });
    }

    combinedRows[totalRowIndex][3] = {
      type: 'td',
      value: primaryDeltaConfig.primaryDurationTotalFormula,
      isDurationCell: true,
    };

    while (combinedRows[totalRowIndex].length < 11) {
      combinedRows[totalRowIndex].push({ type: 'td', value: '' });
    }

    combinedRows[totalRowIndex][10] = {
      type: 'td',
      value: primaryDeltaConfig.totalFormula,
      isDurationCell: true,
      isPrimaryDeltaCell: true,
      isPrimaryDeltaTotal: true,
    };
  }

  if (!primaryDeltaConfig.totalFormula && primaryDeltaConfig.primaryDurationTotalFormula && primaryDeltaConfig.totalRowNumber) {
    const totalRowIndex = primaryDeltaConfig.totalRowNumber - 1;
    while (combinedRows.length <= totalRowIndex) {
      combinedRows.push([]);
    }

    while (combinedRows[totalRowIndex].length < 4) {
      combinedRows[totalRowIndex].push({ type: 'td', value: '' });
    }

    combinedRows[totalRowIndex][3] = {
      type: 'td',
      value: primaryDeltaConfig.primaryDurationTotalFormula,
      isDurationCell: true,
    };
  }

  const excelHtml = buildCombinedExcelHtml(combinedRows);

  const combinedExcelPath = path.join(outputDirectory, `yaware-report-${apiReportDate}.xls`);
  await fs.writeFile(combinedExcelPath, excelHtml, 'utf8');

  return combinedExcelPath;
}

async function saveCombinedXlsx(summaryPayload, dayReportPayload, downloadedFilePath, outputDirectory, apiReportDate, reportEmployeeName = '', idleActivitiesRows = null, activityTimeline = null) {
  const secondaryRows = buildSecondaryRows(summaryPayload, dayReportPayload, apiReportDate, reportEmployeeName, idleActivitiesRows);
  const combinedExcelPath = path.join(outputDirectory, `yaware-report-${apiReportDate}.xlsx`);
  const pythonScript = `
import copy
import json
import os
import posixpath
import tempfile
import xml.etree.ElementTree as ET
import zipfile

MAIN_NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main'
REL_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships'
PKG_REL_NS = 'http://schemas.openxmlformats.org/package/2006/relationships'
XML_NS = 'http://www.w3.org/XML/1998/namespace'
ET.register_namespace('', MAIN_NS)
ET.register_namespace('r', REL_NS)

secondary_rows = json.loads(os.environ['SECONDARY_ROWS_JSON'])
task_table_rows = json.loads(os.environ['TASK_TABLE_ROWS_JSON'])
source_path = os.environ['SOURCE_XLSX_PATH']
output_path = os.environ['OUTPUT_XLSX_PATH']
start_column_index = int(os.environ['SUMMARY_START_COLUMN_INDEX'])
task_table_start_column_index = int(os.environ['TASK_TABLE_START_COLUMN_INDEX'])
task_table_start_row_index = int(os.environ['TASK_TABLE_START_ROW_INDEX'])
trello_tasks = json.loads(os.environ.get('TRELLO_TASKS_JSON') or '[]')
report_date_iso = os.environ.get('REPORT_DATE_ISO', '')
# Пауза між сусідніми рядками активності, більша за цей поріг, розриває блок таски
# (наприклад, обідня перерва) — як у ручних звітах.
task_gap_split = int(os.environ.get('TASK_GAP_SPLIT_MINUTES') or '5') / 1440.0
# Хвіст активності після due, який ще зараховується тасці: due рідко ставлять посекундно.
task_due_grace = int(os.environ.get('TASK_DUE_GRACE_MINUTES') or '15') / 1440.0
# Короткий залишок поза тасками (перемикання, пауза між тасками) не вартий окремого
# рядка — його дописуємо до попередньої таски. Більший лишається «Поза тасками».
task_outside_merge = int(os.environ.get('TASK_OUTSIDE_MERGE_MINUTES') or '15') / 1440.0
# Скільки часу дня не належить жодній тасці — за цим числом бекенд вирішує,
# чи віддавати звіт працівнику. -1 означає, що розподіл не рахувався взагалі
# (тасок немає або в дні немає рядків активності), і це не те саме, що 0.
task_coverage_path = os.environ.get('TASK_COVERAGE_PATH') or ''
outside_seconds_total = -1

def column_letter(index):
    index += 1
    result = ''
    while index:
        index, remainder = divmod(index - 1, 26)
        result = chr(65 + remainder) + result
    return result

def split_cell_reference(reference):
    letters = ''.join(ch for ch in reference if ch.isalpha()) or 'A'
    digits = ''.join(ch for ch in reference if ch.isdigit()) or '1'
    return letters, int(digits)

def column_index(column_name):
    value = 0
    for char in column_name:
        value = value * 26 + (ord(char.upper()) - 64)
    return value - 1

# Ширина колонки за замовчуванням у Excel — 8.43 символи.
DEFAULT_COLUMN_WIDTH = 8.43

def get_column_width(worksheet_root, target_column_index):
    column_number = target_column_index + 1
    cols = worksheet_root.find(f'{{{MAIN_NS}}}cols')
    if cols is not None:
        for col in cols.findall(f'{{{MAIN_NS}}}col'):
            minimum = int(col.attrib.get('min', '1'))
            maximum = int(col.attrib.get('max', minimum))
            if minimum <= column_number <= maximum and col.attrib.get('width'):
                return float(col.attrib['width'])
    sheet_format = worksheet_root.find(f'{{{MAIN_NS}}}sheetFormatPr')
    if sheet_format is not None and sheet_format.attrib.get('defaultColWidth'):
        return float(sheet_format.attrib['defaultColWidth'])
    return DEFAULT_COLUMN_WIDTH

def set_column_width(worksheet_root, target_column_index, width):
    column_number = target_column_index + 1
    cols = worksheet_root.find(f'{{{MAIN_NS}}}cols')
    if cols is None:
        # <cols> за схемою стоїть між sheetFormatPr і sheetData.
        cols = ET.Element(f'{{{MAIN_NS}}}cols')
        sheet_data_element = worksheet_root.find(f'{{{MAIN_NS}}}sheetData')
        worksheet_root.insert(list(worksheet_root).index(sheet_data_element), cols)

    target_col = None
    for col in list(cols.findall(f'{{{MAIN_NS}}}col')):
        minimum = int(col.attrib.get('min', '1'))
        maximum = int(col.attrib.get('max', minimum))
        if not minimum <= column_number <= maximum:
            continue
        if minimum == maximum:
            target_col = col
            break
        # Діапазон, що накриває нашу колонку, розрізаємо — сусіди лишаються як були.
        template = dict(col.attrib)
        cols.remove(col)
        if minimum < column_number:
            left = ET.SubElement(cols, f'{{{MAIN_NS}}}col', dict(template))
            left.attrib['min'] = str(minimum)
            left.attrib['max'] = str(column_number - 1)
        target_col = ET.SubElement(cols, f'{{{MAIN_NS}}}col', dict(template))
        target_col.attrib['min'] = str(column_number)
        target_col.attrib['max'] = str(column_number)
        if column_number < maximum:
            right = ET.SubElement(cols, f'{{{MAIN_NS}}}col', dict(template))
            right.attrib['min'] = str(column_number + 1)
            right.attrib['max'] = str(maximum)
        break

    if target_col is None:
        target_col = ET.SubElement(cols, f'{{{MAIN_NS}}}col', {'min': str(column_number), 'max': str(column_number)})

    target_col.attrib['width'] = f'{width:.2f}'
    target_col.attrib['customWidth'] = '1'

    ordered = sorted(cols.findall(f'{{{MAIN_NS}}}col'), key=lambda item: int(item.attrib.get('min', '1')))
    for col in ordered:
        cols.remove(col)
    for col in ordered:
        cols.append(col)

def cell_sort_key(cell):
    column_name, row_number = split_cell_reference(cell.attrib.get('r', 'A1'))
    return (row_number, column_index(column_name))

# Індекс рядків аркуша за номером: ensure_row викликається тисячі разів, і
# лінійний пошук по всіх рядках щоразу забирав половину часу скрипта. Рядки
# додаються лише через ensure_row, тож індекс не застаріває.
row_index_by_sheet = {}

def ensure_row(sheet_data, row_number):
    row_tag = f'{{{MAIN_NS}}}row'
    row_index = row_index_by_sheet.get(id(sheet_data))
    if row_index is None:
        row_index = {int(row.attrib.get('r', '0')): row for row in sheet_data.findall(row_tag)}
        row_index_by_sheet[id(sheet_data)] = row_index

    existing_row = row_index.get(row_number)
    if existing_row is not None:
        return existing_row

    new_row = ET.Element(row_tag, {'r': str(row_number)})
    next_row_numbers = [number for number in row_index if number > row_number]
    if next_row_numbers:
        sheet_data.insert(list(sheet_data).index(row_index[min(next_row_numbers)]), new_row)
    else:
        sheet_data.append(new_row)
    row_index[row_number] = new_row
    return new_row

def remove_existing_cell(row_element, cell_reference):
    cell_tag = f'{{{MAIN_NS}}}c'
    for existing_cell in list(row_element.findall(cell_tag)):
        if existing_cell.attrib.get('r') == cell_reference:
            row_element.remove(existing_cell)

def place_cell(row_element, cell):
    """Ставить клітинку на місце однойменної, зберігаючи порядок колонок —
    без пересортування всього рядка після кожної вставки."""
    remove_existing_cell(row_element, cell.attrib['r'])
    new_key = cell_sort_key(cell)
    for index, existing_cell in enumerate(row_element):
        if cell_sort_key(existing_cell) > new_key:
            row_element.insert(index, cell)
            return
    row_element.append(cell)

def find_cell(row_element, cell_reference):
    cell_tag = f'{{{MAIN_NS}}}c'
    for existing_cell in row_element.findall(cell_tag):
        if existing_cell.attrib.get('r') == cell_reference:
            return existing_cell
    return None

def get_cell_style_id(row_element, cell_reference):
    cell = find_cell(row_element, cell_reference)
    if cell is None:
        return None
    return cell.attrib.get('s')

def load_shared_strings(temp_directory):
    shared_strings_path = os.path.join(temp_directory, 'xl', 'sharedStrings.xml')
    if not os.path.exists(shared_strings_path):
        return []

    shared_strings_tree = ET.parse(shared_strings_path)
    shared_strings_root = shared_strings_tree.getroot()
    values = []
    for item in shared_strings_root.findall(f'{{{MAIN_NS}}}si'):
        text_parts = []
        direct_text = item.find(f'{{{MAIN_NS}}}t')
        if direct_text is not None and direct_text.text is not None:
            text_parts.append(direct_text.text)
        for run_text in item.findall(f'.//{{{MAIN_NS}}}r/{{{MAIN_NS}}}t'):
            if run_text.text is not None:
                text_parts.append(run_text.text)
        values.append(''.join(text_parts))
    return values

def get_cell_text(cell, shared_strings):
    if cell is None:
        return ''

    cell_type = cell.attrib.get('t')
    if cell_type == 'inlineStr':
        text_node = cell.find(f'{{{MAIN_NS}}}is/{{{MAIN_NS}}}t')
        return text_node.text if text_node is not None and text_node.text is not None else ''
    if cell_type == 's':
        value_node = cell.find(f'{{{MAIN_NS}}}v')
        if value_node is None or value_node.text is None:
            return ''
        try:
            index = int(value_node.text)
        except ValueError:
            return ''
        return shared_strings[index] if 0 <= index < len(shared_strings) else ''

    value_node = cell.find(f'{{{MAIN_NS}}}v')
    return value_node.text if value_node is not None and value_node.text is not None else ''

def get_cell_text_by_reference(sheet_data, cell_reference, shared_strings):
    _, row_number = split_cell_reference(cell_reference)
    row_element = ensure_row(sheet_data, row_number)
    return get_cell_text(find_cell(row_element, cell_reference), shared_strings)

def parse_duration_seconds(value):
    raw_value = str(value or '').strip()
    if not raw_value:
        return None

    parts = raw_value.split(':')
    if len(parts) == 2:
        hours_text, minutes_text = parts
        seconds_text = '0'
    elif len(parts) == 3:
        hours_text, minutes_text, seconds_text = parts
    else:
        return None

    if not all(part.isdigit() for part in (hours_text, minutes_text, seconds_text)):
        return None

    hours = int(hours_text)
    minutes = int(minutes_text)
    seconds = int(seconds_text)
    if minutes >= 60 or seconds >= 60:
        return None

    return hours * 3600 + minutes * 60 + seconds

def set_numeric_cell(cell, numeric_value, style_id=None):
    cell.attrib.pop('t', None)
    if style_id is not None:
        cell.attrib['s'] = str(style_id)
    value_node = cell.find(f'{{{MAIN_NS}}}v')
    if value_node is None:
        value_node = ET.SubElement(cell, f'{{{MAIN_NS}}}v')
    value_node.text = f'{numeric_value:.15g}'

    inline_string = cell.find(f'{{{MAIN_NS}}}is')
    if inline_string is not None:
        cell.remove(inline_string)

def convert_existing_cell_to_time(row_element, cell_reference, shared_strings, style_id):
    existing_cell = find_cell(row_element, cell_reference)
    if existing_cell is None:
        return

    duration_seconds = parse_duration_seconds(get_cell_text(existing_cell, shared_strings))
    if duration_seconds is not None:
        set_numeric_cell(existing_cell, duration_seconds / 86400, style_id=style_id)
    else:
        existing_cell.attrib['s'] = str(style_id)

def upsert_inline_string(row_element, cell_reference, value, style_id=None):
    cell_tag = f'{{{MAIN_NS}}}c'

    cell_attributes = {'r': cell_reference, 't': 'inlineStr'}
    if style_id is not None:
        cell_attributes['s'] = str(style_id)
    cell = ET.Element(cell_tag, cell_attributes)
    inline_string = ET.SubElement(cell, f'{{{MAIN_NS}}}is')
    text = ET.SubElement(inline_string, f'{{{MAIN_NS}}}t')
    if value.strip() != value or '\\n' in value:
        text.attrib[f'{{{XML_NS}}}space'] = 'preserve'
    text.text = value
    place_cell(row_element, cell)

def upsert_numeric_cell(row_element, cell_reference, numeric_value, style_id=None):
    cell_tag = f'{{{MAIN_NS}}}c'

    cell_attributes = {'r': cell_reference}
    if style_id is not None:
        cell_attributes['s'] = str(style_id)
    cell = ET.Element(cell_tag, cell_attributes)
    value_element = ET.SubElement(cell, f'{{{MAIN_NS}}}v')
    value_element.text = f'{numeric_value:.15g}'
    place_cell(row_element, cell)

def upsert_styled_blank_cell(row_element, cell_reference, style_id):
    cell_tag = f'{{{MAIN_NS}}}c'

    cell = ET.Element(cell_tag, {'r': cell_reference, 's': str(style_id)})
    place_cell(row_element, cell)

def get_numeric_cell_value(row_element, cell_reference):
    cell = find_cell(row_element, cell_reference)
    if cell is None or cell.attrib.get('t'):
        return None
    value_node = cell.find(f'{{{MAIN_NS}}}v')
    if value_node is None or value_node.text is None:
        return None
    try:
        return float(value_node.text)
    except ValueError:
        return None

def trello_time_fraction(value, report_date_iso, default):
    # 'Y-m-d H:i' -> частка доби; дати поза днем звіту обрізаються до меж дня.
    raw_value = str(value or '').strip()
    if not raw_value or not report_date_iso:
        return default
    date_part, _, time_part = raw_value.partition(' ')
    if date_part < report_date_iso:
        return 0.0
    if date_part > report_date_iso:
        return 1.0
    hours_text, _, minutes_text = time_part.partition(':')
    try:
        return (int(hours_text) * 3600 + int(minutes_text) * 60) / 86400.0
    except ValueError:
        return default

def upsert_formula_cell(row_element, cell_reference, formula, style_id=None):
    cell_tag = f'{{{MAIN_NS}}}c'

    cell_attributes = {'r': cell_reference}
    if style_id is not None:
        cell_attributes['s'] = str(style_id)
    cell = ET.Element(cell_tag, cell_attributes)
    formula_element = ET.SubElement(cell, f'{{{MAIN_NS}}}f')
    formula_element.text = formula
    value_element = ET.SubElement(cell, f'{{{MAIN_NS}}}v')
    value_element.text = '0'
    place_cell(row_element, cell)

def load_activity_timeline(sheet_data, primary_row_count):
    # Рядки XLS мають збігатися з API один до одного: той самий порядок і ті самі хвилини.
    try:
        with open(os.environ['ACTIVITY_TIMELINE_PATH'], 'r', encoding='utf-8') as timeline_file:
            timeline = json.load(timeline_file)
    except (KeyError, OSError, ValueError):
        return None
    if not timeline:
        return None

    windows = timeline.get('windows') or []
    if len(windows) != primary_row_count - 1:
        return None

    for offset, window in enumerate(windows):
        row_number = offset + 2
        row_element = ensure_row(sheet_data, row_number)
        if window.get('start') is None or window.get('end') is None:
            return None
        for column, seconds in (('G', window['start']), ('H', window['end'])):
            sheet_value = get_numeric_cell_value(row_element, f'{column}{row_number}')
            # Звіряємо до хвилини: старий експорт Yaware давав «09:07», з 2026-10-28 —
            # «09:07:45», тож секунди відкидаємо з обох боків, а не округлюємо.
            if sheet_value is None or round(sheet_value * 86400) // 60 != seconds // 60:
                return None

    events = sorted(
        (event['start'], event['end'])
        for event in (timeline.get('allEvents') or [])
        if event.get('start') is not None and event.get('end') is not None and event['end'] > event['start']
    )
    return windows, events

def split_gap(gap_start, gap_end, events):
    # Повертає (секунди, покриті подіями, список непокритих проміжків як (початок, кінець)).
    covered_seconds = 0
    uncovered = []
    cursor = gap_start
    for event_start, event_end in events:
        if event_end <= cursor:
            continue
        if event_start >= gap_end:
            break
        if event_start > cursor:
            uncovered.append((cursor, event_start))
        covered_until = min(event_end, gap_end)
        covered_seconds += covered_until - max(event_start, cursor)
        cursor = covered_until
        if cursor >= gap_end:
            break
    if cursor < gap_end:
        uncovered.append((cursor, gap_end))
    return covered_seconds, uncovered

IDLE_NAME_HEADER = "Ім'я офлайн активності"
IDLE_TABLE_HEADERS = ['Період', 'Працівник', 'Група', IDLE_NAME_HEADER, 'Коментар', 'Перша дія', 'Тривалість', 'Остання активність']
MISSED_IDLE_NAME = 'Пропущено'
IDLE_SUMMARY_START_COLUMN = 'W'
# Колонка «Час -» основної таблиці (пауза між рядками).
PAUSE_COLUMN = 'K'
IDLE_CANCELLED_NAMES = ('Перерва', 'Особисте', MISSED_IDLE_NAME)
IDLE_DISTRIBUTION_LABELS = ['1хв', '2хв', '3хв', '4хв', '5хв']
# Ті самі межі, що й у колонках L–Q: (назва, діапазон над назвою, нижня межа в секундах, верхня включно або None, тільки пропущене).
# Рядки «Пропущено» (проміжки без жодних подій, як у Q) потрапляють лише в останню колонку.
IDLE_DURATION_BUCKETS = [
    ('1хв', '0:30 – 1:59', 30, 119, False),
    ('2хв', '2:00 – 2:59', 120, 179, False),
    ('3хв', '3:00 – 3:59', 180, 239, False),
    ('4хв', '4:00 – 4:59', 240, 299, False),
    ('5+ хв', 'від 5:00', 300, None, False),
    (MISSED_IDLE_NAME, 'від 0:30', 30, None, True),
]

def format_clock(seconds):
    return f'{seconds // 3600:02d}:{seconds % 3600 // 60:02d}:{seconds % 60:02d}'

# Позначка клітинки «Час на завдання»: формулу підставляємо після розподілу тасок.
TASK_TIME_TOTAL_PLACEHOLDER = '__TASK_TIME_TOTAL__'

def build_idle_table(rows, generated_idle_rows, employee_name, primary_total_row=None):
    # Таблиця офлайн-активностей іде останньою у secondary_rows: заголовок і рядки до кінця.
    header_index = next(
        (index for index, row in enumerate(rows) if any(cell.get('value') == IDLE_NAME_HEADER for cell in row)),
        None,
    )
    if header_index is None:
        if not generated_idle_rows:
            return
        rows.append([{'value': ''}])
        rows.append([{'value': 'Офлайн активність' if index == 0 else '', 'isSectionTitle': True} for index in range(len(IDLE_TABLE_HEADERS))])
        rows.append([{'value': header} for header in IDLE_TABLE_HEADERS])
        header_index = len(rows) - 1

    headers = [str(cell.get('value', '')) for cell in rows[header_index]]
    data_rows = rows[header_index + 1:]
    template_row = data_rows[0] if data_rows else []

    def column_of(header):
        return headers.index(header) if header in headers else None

    def template_value(header):
        index = column_of(header)
        return template_row[index].get('value', '') if index is not None and index < len(template_row) else ''

    period_value = template_value('Період') or os.environ.get('REPORT_PERIOD', '')
    employee_value = template_value('Працівник') or employee_name
    group_value = template_value('Група')
    for idle_name, pause_start, pause_end in generated_idle_rows:
        values = {
            'Період': period_value,
            'Працівник': employee_value,
            'Група': group_value,
            IDLE_NAME_HEADER: idle_name,
            'Перша дія': format_clock(pause_start),
            'Тривалість': format_clock(pause_end - pause_start),
            'Остання активність': format_clock(pause_end),
        }
        data_rows.append([{'value': values.get(header, '')} for header in headers])

    start_column = column_of('Перша дія')

    def start_seconds(row):
        if start_column is None or start_column >= len(row):
            return 0
        parsed = parse_duration_seconds(row[start_column].get('value', ''))
        return parsed if parsed is not None else 0

    data_rows = sorted(data_rows, key=start_seconds)

    # Праворуч від таблиці — тривалість кожного рядка у своїй колонці, над назвами діапазони, внизу підсумки.
    table_width = len(headers)
    duration_column = column_of('Тривалість')
    name_column = column_of(IDLE_NAME_HEADER)
    title_row = rows[header_index - 1]
    title_row.extend({'value': '', 'isSectionTitle': True} for _ in range(table_width - len(title_row)))
    title_row.extend({'value': range_label, 'idleStyle': 'range'} for _, range_label, _, _, _ in IDLE_DURATION_BUCKETS)
    rows[header_index].extend({'value': title, 'idleStyle': 'header'} for title, _, _, _, _ in IDLE_DURATION_BUCKETS)
    for row in data_rows:
        row.extend({'value': ''} for _ in range(table_width - len(row)))
        duration_seconds = parse_duration_seconds(row[duration_column].get('value', '')) if duration_column is not None else None
        if duration_seconds is not None:
            # Числом, а не текстом, щоб під колонкою можна було підбити суму.
            row[duration_column] = {'value': row[duration_column].get('value', ''), 'durationSeconds': duration_seconds}
        is_missed = name_column is not None and row[name_column].get('value') == MISSED_IDLE_NAME
        for _, _, lower_bound, upper_bound, missed_only in IDLE_DURATION_BUCKETS:
            if missed_only == is_missed and duration_seconds is not None and duration_seconds >= lower_bound and (upper_bound is None or duration_seconds <= upper_bound):
                row.append({'value': '', 'seconds': duration_seconds})
            else:
                row.append({'value': '', 'idleStyle': 'cell'})

    first_data_row_number = header_index + 2
    last_data_row_number = header_index + 1 + len(data_rows)
    total_row = [{'value': '', 'isSpacerCell': True} for _ in range(table_width)]
    if duration_column is not None:
        letter = column_letter(start_column_index + duration_column)
        total_row[duration_column] = {'value': '', 'formula': f'SUM({letter}{first_data_row_number}:{letter}{last_data_row_number})'}
    for bucket_offset in range(len(IDLE_DURATION_BUCKETS)):
        letter = column_letter(start_column_index + table_width + bucket_offset)
        total_row.append({'value': '', 'formula': f'SUM({letter}{first_data_row_number}:{letter}{last_data_row_number})'})
    rows[header_index + 1:] = data_rows + [total_row]

    # Через 3 порожні рядки під таблицею — зведення по кожній офлайн-активності (і коментарю) у колонках W–AA, без заливки й рамок.
    comment_column = column_of('Коментар')
    summary_groups = {}
    for row in data_rows:
        idle_name = str(row[name_column].get('value', '')).strip() if name_column is not None else ''
        comment = str(row[comment_column].get('value', '')).strip() if comment_column is not None else ''
        duration_seconds = row[duration_column].get('durationSeconds') if duration_column is not None else None
        if not idle_name:
            continue
        # Одна активність — один рядок; різні коментарі до неї йдуть через кому.
        group = summary_groups.setdefault(idle_name, [0, 0, []])
        group[0] += 1
        group[1] += duration_seconds or 0
        if comment and comment not in group[2]:
            group[2].append(comment)
    if summary_groups:
        summary_offset = column_index(IDLE_SUMMARY_START_COLUMN) - start_column_index
        rows.extend([{'value': '', 'isSpacerCell': True}] for _ in range(3))
        for idle_name, (count, total_seconds, comments) in summary_groups.items():
            rows.append([{'value': '', 'isSpacerCell': True} for _ in range(summary_offset)] + [
                {'value': idle_name, 'plain': True},
                {'value': ', '.join(comments), 'plain': True},
                {'value': '', 'plainNumber': count},
                {'value': '', 'plainSeconds': total_seconds},
                {'value': '', 'plainSeconds': round(total_seconds / count)},
            ])

        # Через 1 порожній рядок — «Загалом» по всіх активностях і «Відміна» по перервах, особистому й пропущеному.
        rows.append([{'value': '', 'isSpacerCell': True}])
        overall_row_number = len(rows) + 1
        for title, names in (('Загалом', None), ('Відміна', IDLE_CANCELLED_NAMES)):
            groups = [group for name, group in summary_groups.items() if names is None or name in names]
            rows.append([{'value': '', 'isSpacerCell': True} for _ in range(summary_offset)] + [
                {'value': title, 'plain': True, 'bold': True},
                {'value': '', 'isSpacerCell': True},
                {'value': '', 'plainNumber': sum(group[0] for group in groups), 'bold': True},
                {'value': '', 'plainSeconds': sum(group[1] for group in groups), 'bold': True},
            ])

        # Ще через 1 порожній рядок — розподіл офлайн-активностей за тривалістю (ті самі межі, що й колонки праворуч від таблиці, без «Пропущено»).
        rows.append([{'value': '', 'isSpacerCell': True}])
        for label, (_, _, lower_bound, upper_bound, _) in zip(IDLE_DISTRIBUTION_LABELS, IDLE_DURATION_BUCKETS):
            bucket_durations = [
                row[duration_column].get('durationSeconds') for row in data_rows
                if (name_column is None or row[name_column].get('value') != MISSED_IDLE_NAME)
                and row[duration_column].get('durationSeconds') is not None
                and row[duration_column]['durationSeconds'] >= lower_bound
                and (upper_bound is None or row[duration_column]['durationSeconds'] <= upper_bound)
            ] if duration_column is not None else []
            rows.append([{'value': '', 'isSpacerCell': True} for _ in range(summary_offset)] + [
                {'value': label, 'plain': True},
                {'value': '', 'isSpacerCell': True},
                {'value': '', 'plainNumber': len(bucket_durations)},
                {'value': '', 'plainSeconds': sum(bucket_durations)},
                {'value': '', 'plainSeconds': round(sum(bucket_durations) / len(bucket_durations))} if bucket_durations else {'value': '', 'isSpacerCell': True},
            ])

        # Одразу під розподілом — два жирні рядки для звірки: підсумок колонки «Час -» основної таблиці і «Загалом» з детальної таблиці.
        total_letter = column_letter(column_index(IDLE_SUMMARY_START_COLUMN) + 3)
        pause_total_reference = f'{PAUSE_COLUMN}{primary_total_row}' if primary_total_row else None
        for title, formula in (
            ('час офлайн для звірки', pause_total_reference),
            ('час офлайн для звірки з детальної таблиці', f'{total_letter}{overall_row_number}'),
        ):
            rows.append([{'value': '', 'isSpacerCell': True} for _ in range(summary_offset)] + [
                {'value': title, 'plain': True, 'bold': True},
                {'value': '', 'isSpacerCell': True},
                {'value': '', 'isSpacerCell': True},
                {'value': '', 'plainFormula': formula, 'bold': True} if formula else {'value': '', 'isSpacerCell': True},
            ])
        # Праворуч від останнього рядка червоним — різниця між «звіркою» і «детальною таблицею» без знака.
        if pause_total_reference:
            check_cell = f'{total_letter}{len(rows) - 1}'
            detail_cell = f'{total_letter}{len(rows)}'
        else:
            check_cell = None
            detail_cell = f'{total_letter}{len(rows)}'
        if check_cell:
            rows[-1].append({'value': '', 'plainFormula': f'ABS({detail_cell}-{check_cell})', 'red': True})

        # Через 1 порожній рядок — час за комп'ютером (підсумок D), загальна активність (підсумок H) і час на завдання (сума колонки «Час» таблиці тасок).
        rows.append([{'value': '', 'isSpacerCell': True}])
        computer_time_cell = f'{total_letter}{len(rows) + 1}'
        activity_time_cell = f'{total_letter}{len(rows) + 2}'
        for title, formula in (
            ('Час за комп-ом', f'D{primary_total_row}' if primary_total_row else None),
            ('Загальна активність час', f'H{primary_total_row}' if primary_total_row else None),
            ('Час на завдання', TASK_TIME_TOTAL_PLACEHOLDER),
        ):
            rows.append([{'value': '', 'isSpacerCell': True} for _ in range(summary_offset)] + [
                {'value': title, 'plain': True},
                {'value': '', 'isSpacerCell': True},
                {'value': '', 'isSpacerCell': True},
                {'value': '', 'plainFormula': formula} if formula else {'value': '', 'isSpacerCell': True},
            ])

        # Одразу під нею жирним — різниця активності й часу за комп'ютером (без знака) і повтор обох значень звірки офлайну.
        difference_cell = f'{total_letter}{len(rows) + 1}'
        report_offline_cell = f'{total_letter}{len(rows) + 2}'
        for title, formula in (
            ('Різниця', f'ABS({activity_time_cell}-{computer_time_cell})' if primary_total_row else None),
            ('Час офлайн зі звіту', check_cell),
            ('Час офлайн для звірки з детальної таблиці', detail_cell),
        ):
            rows.append([{'value': '', 'isSpacerCell': True} for _ in range(summary_offset)] + [
                {'value': title, 'plain': True, 'bold': True},
                {'value': '', 'isSpacerCell': True},
                {'value': '', 'isSpacerCell': True},
                {'value': '', 'plainFormula': formula, 'bold': True} if formula else {'value': '', 'isSpacerCell': True},
            ])
            # Праворуч червоним — розбіжність із рядком вище без знака, лише якщо вона є:
            # «Час офлайн зі звіту» порівнюємо з «Різницею», детальну таблицю — з «Час офлайн зі звіту».
            previous_cell = {'Час офлайн зі звіту': difference_cell, 'Час офлайн для звірки з детальної таблиці': report_offline_cell}.get(title)
            if previous_cell and formula and primary_total_row:
                delta = f'ABS({previous_cell}-{total_letter}{len(rows)})'
                rows[-1].append({'value': '', 'plainFormula': f'IF(ROUND({delta}*86400,0)=0,"",{delta})', 'red': True})

def get_or_create_child(parent, tag_name):
    child = parent.find(f'{{{MAIN_NS}}}{tag_name}')
    if child is None:
        child = ET.SubElement(parent, f'{{{MAIN_NS}}}{tag_name}')
    return child

def ensure_default_fills(styles_root):
    fills = get_or_create_child(styles_root, 'fills')
    while len(fills) < 2:
        fill = ET.SubElement(fills, f'{{{MAIN_NS}}}fill')
        pattern = ET.SubElement(fill, f'{{{MAIN_NS}}}patternFill', {'patternType': 'none' if len(fills) == 0 else 'gray125'})
    fills.attrib['count'] = str(len(fills))
    return fills

def ensure_default_borders(styles_root):
    borders = get_or_create_child(styles_root, 'borders')
    if len(borders) == 0:
        border = ET.SubElement(borders, f'{{{MAIN_NS}}}border')
        for tag_name in ('left', 'right', 'top', 'bottom', 'diagonal'):
            ET.SubElement(border, f'{{{MAIN_NS}}}{tag_name}')
    borders.attrib['count'] = str(len(borders))
    return borders

def set_font_size(font, size):
    size_element = font.find(f'{{{MAIN_NS}}}sz')
    if size_element is None:
        size_element = ET.SubElement(font, f'{{{MAIN_NS}}}sz')
    size_element.attrib['val'] = str(size)

def ensure_default_fonts(styles_root, size=11):
    fonts = get_or_create_child(styles_root, 'fonts')
    if len(fonts) == 0:
        font = ET.SubElement(fonts, f'{{{MAIN_NS}}}font')
        ET.SubElement(font, f'{{{MAIN_NS}}}name', {'val': 'Calibri'})
    for font in fonts.findall(f'{{{MAIN_NS}}}font'):
        set_font_size(font, size)
    fonts.attrib['count'] = str(len(fonts))
    return fonts

def ensure_default_num_fmts(styles_root):
    num_fmts = styles_root.find(f'{{{MAIN_NS}}}numFmts')
    if num_fmts is None:
        num_fmts = ET.Element(f'{{{MAIN_NS}}}numFmts', {'count': '0'})
        styles_root.insert(0, num_fmts)
    num_fmts.attrib['count'] = str(len(num_fmts))
    return num_fmts

def get_or_create_num_fmt_id(styles_root, format_code):
    num_fmts = ensure_default_num_fmts(styles_root)
    for num_fmt in num_fmts.findall(f'{{{MAIN_NS}}}numFmt'):
        if num_fmt.attrib.get('formatCode') == format_code:
            return int(num_fmt.attrib['numFmtId'])

    existing_ids = [int(num_fmt.attrib.get('numFmtId', '163')) for num_fmt in num_fmts.findall(f'{{{MAIN_NS}}}numFmt')]
    next_id = max(existing_ids, default=163) + 1
    ET.SubElement(num_fmts, f'{{{MAIN_NS}}}numFmt', {'numFmtId': str(next_id), 'formatCode': format_code})
    num_fmts.attrib['count'] = str(len(num_fmts))
    return next_id

def ensure_default_cell_xfs(styles_root):
    cell_xfs = get_or_create_child(styles_root, 'cellXfs')
    if len(cell_xfs) == 0:
        ET.SubElement(cell_xfs, f'{{{MAIN_NS}}}xf', {'numFmtId': '0', 'fontId': '0', 'fillId': '0', 'borderId': '0', 'xfId': '0'})
    cell_xfs.attrib['count'] = str(len(cell_xfs))
    return cell_xfs

def create_border_style(styles_root, *, fill_id=0, bold=False, font_color=None, num_fmt_code=None, horizontal_alignment=None, include_border=True, wrap_text=False):
    border_id = 0
    if include_border:
        borders = ensure_default_borders(styles_root)
        border = ET.SubElement(borders, f'{{{MAIN_NS}}}border')
        for side in ('left', 'right', 'top', 'bottom'):
            side_element = ET.SubElement(border, f'{{{MAIN_NS}}}{side}', {'style': 'thin'})
            ET.SubElement(side_element, f'{{{MAIN_NS}}}color', {'auto': '1'})
        ET.SubElement(border, f'{{{MAIN_NS}}}diagonal')
        border_id = len(borders) - 1
        borders.attrib['count'] = str(len(borders))

    font_id = 0
    if bold or font_color:
        fonts = ensure_default_fonts(styles_root)
        font = ET.SubElement(fonts, f'{{{MAIN_NS}}}font')
        if bold:
            ET.SubElement(font, f'{{{MAIN_NS}}}b')
        if font_color:
            ET.SubElement(font, f'{{{MAIN_NS}}}color', {'rgb': font_color})
        ET.SubElement(font, f'{{{MAIN_NS}}}sz', {'val': '10'})
        ET.SubElement(font, f'{{{MAIN_NS}}}name', {'val': 'Calibri'})
        font_id = len(fonts) - 1
        fonts.attrib['count'] = str(len(fonts))

    num_fmt_id = get_or_create_num_fmt_id(styles_root, num_fmt_code) if num_fmt_code else 0

    cell_xfs = ensure_default_cell_xfs(styles_root)
    xf_attributes = {
        'numFmtId': str(num_fmt_id),
        'fontId': str(font_id),
        'fillId': str(fill_id),
        'borderId': str(border_id),
        'xfId': '0',
        'applyBorder': '1' if include_border else '0',
        'applyFill': '1' if fill_id else '0',
    }
    if bold or font_color:
        xf_attributes['applyFont'] = '1'
    if num_fmt_code:
        xf_attributes['applyNumberFormat'] = '1'
    xf = ET.SubElement(cell_xfs, f'{{{MAIN_NS}}}xf', xf_attributes)
    if horizontal_alignment or wrap_text:
        alignment_attributes = {}
        if horizontal_alignment:
            alignment_attributes['horizontal'] = horizontal_alignment
        if wrap_text:
            alignment_attributes['wrapText'] = '1'
        ET.SubElement(xf, f'{{{MAIN_NS}}}alignment', alignment_attributes)
        xf.attrib['applyAlignment'] = '1'
    xf_id = len(cell_xfs) - 1
    cell_xfs.attrib['count'] = str(len(cell_xfs))
    return xf_id

def create_solid_fill(styles_root, rgb):
    fills = ensure_default_fills(styles_root)
    fill = ET.SubElement(fills, f'{{{MAIN_NS}}}fill')
    pattern = ET.SubElement(fill, f'{{{MAIN_NS}}}patternFill', {'patternType': 'solid'})
    ET.SubElement(pattern, f'{{{MAIN_NS}}}fgColor', {'rgb': rgb})
    ET.SubElement(pattern, f'{{{MAIN_NS}}}bgColor', {'indexed': '64'})
    fill_id = len(fills) - 1
    fills.attrib['count'] = str(len(fills))
    return fill_id

def normalize_target_path(target):
    cleaned = target.lstrip('/')
    return cleaned if cleaned.startswith('xl/') else posixpath.normpath(posixpath.join('xl', cleaned))

with tempfile.TemporaryDirectory() as temp_directory:
    with zipfile.ZipFile(source_path, 'r') as source_archive:
        source_archive.extractall(temp_directory)
    shared_strings = load_shared_strings(temp_directory)

    workbook_tree = ET.parse(os.path.join(temp_directory, 'xl', 'workbook.xml'))
    workbook_root = workbook_tree.getroot()
    first_sheet = workbook_root.find(f'{{{MAIN_NS}}}sheets/{{{MAIN_NS}}}sheet')
    if first_sheet is None:
        raise RuntimeError('Could not find a worksheet in the downloaded XLSX file.')

    relationship_id = first_sheet.attrib.get(f'{{{REL_NS}}}id')
    rels_tree = ET.parse(os.path.join(temp_directory, 'xl', '_rels', 'workbook.xml.rels'))
    rels_root = rels_tree.getroot()
    worksheet_target = None
    for relationship in rels_root.findall(f'{{{PKG_REL_NS}}}Relationship'):
        if relationship.attrib.get('Id') == relationship_id:
            worksheet_target = relationship.attrib.get('Target')
            break
    if not worksheet_target:
        raise RuntimeError('Could not resolve the first worksheet relationship in the downloaded XLSX file.')

    worksheet_path = os.path.join(temp_directory, normalize_target_path(worksheet_target))
    worksheet_tree = ET.parse(worksheet_path)
    worksheet_root = worksheet_tree.getroot()
    sheet_data = worksheet_root.find(f'{{{MAIN_NS}}}sheetData')
    if sheet_data is None:
        raise RuntimeError('Could not find sheetData in the downloaded XLSX worksheet.')

    primary_row_count = max((int(row.attrib.get('r', '0')) for row in sheet_data.findall(f'{{{MAIN_NS}}}row')), default=0)
    primary_employee_name = get_cell_text_by_reference(sheet_data, 'E2', shared_strings)

    styles_path = os.path.join(temp_directory, 'xl', 'styles.xml')
    styles_tree = ET.parse(styles_path)
    styles_root = styles_tree.getroot()
    summary_border_style_id = create_border_style(styles_root)
    yellow_fill_id = create_solid_fill(styles_root, 'FFFF00')
    summary_highlight_style_id = create_border_style(styles_root, fill_id=yellow_fill_id)
    # Колонки J–R зафарбовані світло-червоним повністю, а не лише там, де є час, і з межами клітинок.
    light_red_fill_id = create_solid_fill(styles_root, 'FFFFCCCC')
    primary_delta_duration_style_id = create_border_style(styles_root, fill_id=light_red_fill_id, num_fmt_code='[h]:mm:ss', horizontal_alignment='center')
    primary_delta_total_style_id = create_border_style(styles_root, fill_id=light_red_fill_id, bold=True, num_fmt_code='[h]:mm:ss', horizontal_alignment='center')
    # Колонка «Час -» без заливки й рамок.
    gap_duration_style_id = create_border_style(styles_root, num_fmt_code='[h]:mm:ss', horizontal_alignment='center', include_border=False)
    gap_total_style_id = create_border_style(styles_root, bold=True, num_fmt_code='[h]:mm:ss', horizontal_alignment='center', include_border=False)
    primary_column_d_style_id = create_border_style(styles_root, num_fmt_code='[h]:mm:ss', horizontal_alignment='center', include_border=False)
    primary_column_d_total_style_id = create_border_style(styles_root, bold=True, num_fmt_code='[h]:mm:ss', horizontal_alignment='center', include_border=False)
    primary_time_style_id = create_border_style(styles_root, num_fmt_code='hh:mm:ss', horizontal_alignment='center', include_border=False)
    primary_time_total_style_id = create_border_style(styles_root, bold=True, num_fmt_code='[h]:mm:ss', horizontal_alignment='center', include_border=False)
    task_table_header_style_id = create_border_style(styles_root, fill_id=yellow_fill_id, horizontal_alignment='left')
    task_table_cell_style_id = create_border_style(styles_root)
    # Колонки розподілу офлайн-активностей: без рамок; назви колонок — Light Yellow 3, клітинки з часом — Light Gray 3.
    light_yellow_3_fill_id = create_solid_fill(styles_root, 'FFFFF2CC')
    light_gray_3_fill_id = create_solid_fill(styles_root, 'FFF3F3F3')
    idle_bucket_plain_style_id = create_border_style(styles_root, horizontal_alignment='center', include_border=False)
    idle_bucket_header_style_id = create_border_style(styles_root, fill_id=light_yellow_3_fill_id, horizontal_alignment='center', include_border=False)
    idle_bucket_style_id = create_border_style(styles_root, fill_id=light_gray_3_fill_id, num_fmt_code='[h]:mm:ss', horizontal_alignment='center', include_border=False)
    idle_duration_style_id = create_border_style(styles_root, num_fmt_code='[hh]:mm:ss', horizontal_alignment='left')
    idle_bucket_total_style_id =create_border_style(styles_root, bold=True, num_fmt_code='[h]:mm:ss', horizontal_alignment='center', include_border=False)
    idle_summary_duration_style_id = create_border_style(styles_root, num_fmt_code='[h]:mm:ss', include_border=False)
    idle_summary_bold_style_id = create_border_style(styles_root, bold=True, include_border=False)
    idle_summary_red_style_id = create_border_style(styles_root, bold=True, font_color='FFFF0000', num_fmt_code='[h]:mm:ss', include_border=False)
    idle_summary_bold_duration_style_id = create_border_style(styles_root, bold=True, num_fmt_code='[h]:mm:ss', include_border=False)

    header_row_element = ensure_row(sheet_data, 1)
    primary_header_style_id = get_cell_style_id(header_row_element, 'H1')
    upsert_inline_string(header_row_element, 'I1', 'Завдання', style_id=primary_header_style_id)
    # Заголовок «Час -» — як у H1, але без рамки.
    header_cell_xfs = ensure_default_cell_xfs(styles_root)
    header_source_index = int(primary_header_style_id) if primary_header_style_id is not None else 0
    header_source_xf = header_cell_xfs[header_source_index] if header_source_index < len(header_cell_xfs) else header_cell_xfs[0]
    gap_header_xf = copy.deepcopy(header_source_xf)
    gap_header_xf.attrib['borderId'] = '0'
    gap_header_xf.attrib['applyBorder'] = '0'
    header_cell_xfs.append(gap_header_xf)
    header_cell_xfs.attrib['count'] = str(len(header_cell_xfs))
    gap_header_style_id = len(header_cell_xfs) - 1
    upsert_inline_string(header_row_element, 'J1', 'Час на завдання', style_id=primary_header_style_id)
    upsert_inline_string(header_row_element, f'{PAUSE_COLUMN}1', 'Час -', style_id=gap_header_style_id)
    upsert_inline_string(header_row_element, 'L1', '1хв', style_id=primary_header_style_id)
    upsert_inline_string(header_row_element, 'M1', '2хв', style_id=primary_header_style_id)
    upsert_inline_string(header_row_element, 'N1', '3хв', style_id=primary_header_style_id)
    upsert_inline_string(header_row_element, 'O1', '4хв', style_id=primary_header_style_id)
    upsert_inline_string(header_row_element, 'P1', '5хв', style_id=primary_header_style_id)
    upsert_inline_string(header_row_element, 'Q1', 'Пропущене', style_id=primary_header_style_id)
    upsert_inline_string(header_row_element, 'R1', 'Неточність явар', style_id=primary_header_style_id)

    for row_number in range(2, primary_row_count + 1):
        row_element = ensure_row(sheet_data, row_number)
        convert_existing_cell_to_time(row_element, f'D{row_number}', shared_strings, primary_column_d_style_id)
        convert_existing_cell_to_time(row_element, f'G{row_number}', shared_strings, primary_time_style_id)
        convert_existing_cell_to_time(row_element, f'H{row_number}', shared_strings, primary_time_style_id)

    # (колонка, нижня межа в секундах, верхня межа включно або None)
    duration_bucket_columns = [
        ('L', 30, 119),
        ('M', 120, 179),
        ('N', 180, 239),
        ('O', 240, 299),
        ('P', 300, None),
    ]
    # Короткі паузи (1–29 с) не рахуються ніде, зокрема й у «Час -».
    missed_gap_column = 'Q'
    # Шви по 1–29 с між записами YAware усередині довгих пауз (не перерва в роботі).
    inaccuracy_column = 'R'
    min_pause_seconds = 30

    # Паузи з колонки P як (назва, початок, кінець) у секундах — дублюються в таблицю офлайн-активностей.
    generated_idle_rows = []

    activity_timeline = load_activity_timeline(sheet_data, primary_row_count) if primary_row_count >= 2 else None
    if activity_timeline is None:
        print('ACTIVITY_TIMELINE_MISMATCH')
    else:
        windows, events = activity_timeline
        for offset, window in enumerate(windows):
            row_element = ensure_row(sheet_data, offset + 2)
            upsert_numeric_cell(row_element, f'G{offset + 2}', window['start'] / 86400, style_id=primary_time_style_id)
            upsert_numeric_cell(row_element, f'H{offset + 2}', window['end'] / 86400, style_id=primary_time_style_id)

    for row_number in range(3, primary_row_count + 1):
        row_element = ensure_row(sheet_data, row_number)
        if activity_timeline is None:
            gap_expression = f'G{row_number}-H{row_number - 1}'
            upsert_formula_cell(row_element, f'{PAUSE_COLUMN}{row_number}', f'IF(ROUND(({gap_expression})*86400,0)>={min_pause_seconds},{gap_expression},"")', style_id=gap_duration_style_id)
            # Без точної хронології лишається грубий розрахунок по «Час -» (час без секунд).
            for column_letter_value, lower_bound, upper_bound in duration_bucket_columns:
                seconds_expression = f'ROUND(N({PAUSE_COLUMN}{row_number})*86400,0)'
                condition = f'{seconds_expression}>={lower_bound}'
                if upper_bound is not None:
                    condition = f'AND({condition},{seconds_expression}<={upper_bound})'
                upsert_formula_cell(row_element, f'{column_letter_value}{row_number}', f'IF({condition},{PAUSE_COLUMN}{row_number},"")', style_id=primary_delta_duration_style_id)
            continue

        # Пауза між рядками: коротша за 30 с не рахується ніде (навіть у «Час -»); довша — офлайн-активність у L–P,
        # проміжки без жодних подій — у «Пропущене».
        gap_start = windows[row_number - 3]['end']
        gap_end = windows[row_number - 2]['start']
        gap_seconds = gap_end - gap_start
        if gap_seconds < min_pause_seconds:
            remove_existing_cell(row_element, f'{PAUSE_COLUMN}{row_number}')
            continue
        upsert_numeric_cell(row_element, f'{PAUSE_COLUMN}{row_number}', gap_seconds / 86400, style_id=gap_duration_style_id)
        offline_seconds, uncovered_gaps = split_gap(gap_start, gap_end, events)
        for column_letter_value, lower_bound, upper_bound in duration_bucket_columns:
            if offline_seconds >= lower_bound and (upper_bound is None or offline_seconds <= upper_bound):
                upsert_numeric_cell(row_element, f'{column_letter_value}{row_number}', offline_seconds / 86400, style_id=primary_delta_duration_style_id)
        missed_gaps = [(start, end) for start, end in uncovered_gaps if end - start >= min_pause_seconds]
        missed_seconds = sum(end - start for start, end in missed_gaps)
        if missed_seconds:
            upsert_numeric_cell(row_element, f'{missed_gap_column}{row_number}', missed_seconds / 86400, style_id=primary_delta_duration_style_id)
            generated_idle_rows.extend((MISSED_IDLE_NAME, start, end) for start, end in missed_gaps)
        inaccuracy_seconds = sum(end - start for start, end in uncovered_gaps if end - start < min_pause_seconds)
        if inaccuracy_seconds:
            upsert_numeric_cell(row_element, f'{inaccuracy_column}{row_number}', inaccuracy_seconds / 86400, style_id=primary_delta_duration_style_id)

    if primary_row_count >= 2:
        total_row_number = primary_row_count + 1
        total_row_element = ensure_row(sheet_data, total_row_number)
        upsert_formula_cell(total_row_element, f'D{total_row_number}', f'SUM(D2:D{primary_row_count})', style_id=primary_column_d_total_style_id)
        upsert_formula_cell(total_row_element, f'H{total_row_number}', f'MAX(H2:H{primary_row_count})-MIN(G2:G{primary_row_count})', style_id=primary_time_total_style_id)
        if primary_row_count >= 3:
            upsert_formula_cell(total_row_element, f'{PAUSE_COLUMN}{total_row_number}', f'SUM({PAUSE_COLUMN}3:{PAUSE_COLUMN}{primary_row_count})', style_id=gap_total_style_id)
            for column_letter_value in [bucket[0] for bucket in duration_bucket_columns] + [missed_gap_column, inaccuracy_column]:
                upsert_formula_cell(
                    total_row_element,
                    f'{column_letter_value}{total_row_number}',
                    f'SUM({column_letter_value}3:{column_letter_value}{primary_row_count})',
                    style_id=primary_delta_total_style_id,
                )
            # Порожні клітинки L–R теж отримують заливку, щоб колонки були зафарбовані суцільно; «Час -» лишається без заливки.
            for row_number in range(2, total_row_number + 1):
                row_element = ensure_row(sheet_data, row_number)
                for column_offset in range(column_index(PAUSE_COLUMN), column_index(inaccuracy_column) + 1):
                    cell_reference = f'{column_letter(column_offset)}{row_number}'
                    if find_cell(row_element, cell_reference) is None:
                        empty_style_id = gap_duration_style_id if column_offset == column_index(PAUSE_COLUMN) else primary_delta_duration_style_id
                        upsert_inline_string(row_element, cell_reference, '', style_id=empty_style_id)

    build_idle_table(
        secondary_rows,
        generated_idle_rows,
        primary_employee_name,
        primary_row_count + 1 if primary_row_count >= 3 else None,
    )

    task_time_total_cell = None
    for row_number, row_values in enumerate(secondary_rows, start=1):
        row_element = ensure_row(sheet_data, row_number)
        for offset, cell_data in enumerate(row_values):
            cell_reference = f'{column_letter(start_column_index + offset)}{row_number}'
            if 'seconds' in cell_data:
                upsert_numeric_cell(row_element, cell_reference, cell_data['seconds'] / 86400, style_id=idle_bucket_style_id)
                continue
            if 'durationSeconds' in cell_data:
                upsert_numeric_cell(row_element, cell_reference, cell_data['durationSeconds'] / 86400, style_id=idle_duration_style_id)
                continue
            if 'formula' in cell_data:
                upsert_formula_cell(row_element, cell_reference, cell_data['formula'], style_id=idle_bucket_total_style_id)
                continue
            if cell_data.get('isSpacerCell'):
                continue
            if cell_data.get('plain'):
                if cell_data.get('value'):
                    upsert_inline_string(row_element, cell_reference, str(cell_data['value']), style_id=idle_summary_bold_style_id if cell_data.get('bold') else None)
                continue
            if 'plainNumber' in cell_data:
                upsert_numeric_cell(row_element, cell_reference, cell_data['plainNumber'], style_id=idle_summary_bold_style_id if cell_data.get('bold') else None)
                continue
            if cell_data.get('plainFormula') == TASK_TIME_TOTAL_PLACEHOLDER:
                task_time_total_cell = (row_element, cell_reference)
                continue
            if 'plainFormula' in cell_data:
                upsert_formula_cell(row_element, cell_reference, cell_data['plainFormula'], style_id=idle_summary_red_style_id if cell_data.get('red') else idle_summary_bold_duration_style_id if cell_data.get('bold') else idle_summary_duration_style_id)
                continue
            if 'plainSeconds' in cell_data:
                upsert_numeric_cell(row_element, cell_reference, cell_data['plainSeconds'] / 86400, style_id=idle_summary_bold_duration_style_id if cell_data.get('bold') else idle_summary_duration_style_id)
                continue
            if 'idleStyle' in cell_data:
                upsert_inline_string(row_element, cell_reference, str(cell_data.get('value', '')), style_id=idle_bucket_header_style_id if cell_data['idleStyle'] == 'header' else idle_bucket_plain_style_id)
                continue
            cell_value = str(cell_data.get('value', ''))
            upsert_inline_string(row_element, cell_reference, cell_value)
            style_id = summary_border_style_id
            summary_total_cell_reference = f"{column_letter(start_column_index + len(secondary_rows[0]) - 1)}2" if secondary_rows and secondary_rows[0] else None
            if cell_reference == summary_total_cell_reference:
                style_id = summary_highlight_style_id
            elif cell_data.get('isSectionTitle'):
                style_id = summary_highlight_style_id
            for cell in row_element.findall(f'{{{MAIN_NS}}}c'):
                if cell.attrib.get('r') == cell_reference:
                    cell.attrib['s'] = str(style_id)
                    break

    if primary_employee_name:
        summary_employee_cell_reference = f'{column_letter(start_column_index + 1)}2'
        summary_row_element = ensure_row(sheet_data, 2)
        upsert_inline_string(summary_row_element, summary_employee_cell_reference, primary_employee_name, style_id=summary_border_style_id)

    for row_offset, row_values in enumerate(task_table_rows):
        row_number = task_table_start_row_index + row_offset + 1
        row_element = ensure_row(sheet_data, row_number)
        for offset, cell_data in enumerate(row_values):
            cell_value = str(cell_data.get('value', ''))
            cell_reference = f'{column_letter(task_table_start_column_index + offset)}{row_number}'
            upsert_inline_string(row_element, cell_reference, cell_value)
            style_id = task_table_header_style_id if cell_data.get('isTaskTableHeader') else task_table_cell_style_id
            for cell in row_element.findall(f'{{{MAIN_NS}}}c'):
                if cell.attrib.get('r') == cell_reference:
                    cell.attrib['s'] = str(style_id)
                    break

    # Коментар до таски — довгий текст, тож його колонка вдвічі ширша за звичайну.
    comment_column_index = task_table_start_column_index + 2
    set_column_width(worksheet_root, comment_column_index, get_column_width(worksheet_root, comment_column_index) * 2)

    # --- Розподіл шкали часу по тасках Trello (колонка I) + табличка тасок ---
    if trello_tasks and primary_row_count >= 2:
        tasks = []
        for task_data in trello_tasks:
            tasks.append({
                'name': str(task_data.get('name', '')),
                'comment': str(task_data.get('comment', '')),
                'start': trello_time_fraction(task_data.get('start'), report_date_iso, None),
                'due': trello_time_fraction(task_data.get('due'), report_date_iso, None),
                'duration': 0.0,
            })
        tasks.sort(key=lambda task: task['start'] if task['start'] is not None else (task['due'] if task['due'] is not None else 0.0))

        # Невідому межу добудовуємо із сусідньої таски: інакше таска без start або due
        # поглинула б увесь день. Спершу start (зліва направо), потім due.
        for index, task in enumerate(tasks):
            if task['start'] is not None:
                continue
            previous = tasks[index - 1] if index > 0 else None
            if previous is None:
                task['start'] = 0.0
            elif previous['due'] is not None:
                task['start'] = previous['due']
            else:
                task['start'] = previous['start']

        for index, task in enumerate(tasks):
            if task['due'] is None:
                following = tasks[index + 1] if index + 1 < len(tasks) else None
                task['due'] = following['start'] if following is not None else task['start']
            task['due'] = max(task['due'], task['start'])

        # Вікно таски — [start, due] плюс grace на хвіст роботи (due рідко ставлять
        # посекундно). Grace не залазить у наступну таску.
        for index, task in enumerate(tasks):
            following = tasks[index + 1] if index + 1 < len(tasks) else None
            next_start = following['start'] if following is not None else 1.0
            task['window_end'] = min(task['due'] + task_due_grace, max(next_start, task['due']))

        def row_owner_index(row_start, row_end):
            # Рядок активності належить тасці з найбільшим перекриттям; якщо він не
            # потрапляє в жодне вікно — це час поза тасками (-1).
            # Час у рядках — з точністю до хвилини, тож дія на кілька секунд має
            # start == end. Така точка на стику двох тасок (вікно попередньої
            # закінчується там, де починається наступна) потрапляє в обидва вікна —
            # віддаємо її пізнішій тасці: дія, що почалась у момент старту таски,
            # належить їй. Інакше після перерви ці рядки ставали окремим блоком
            # 00:00:00 попередньої таски, відірваним від блоку наступної.
            point_row = row_end <= row_start
            best_index = -1
            best_overlap = 0.0
            for index, task in enumerate(tasks):
                if not point_row:
                    overlap = min(row_end, task['window_end']) - max(row_start, task['start'])
                else:
                    overlap = 1.0 if task['start'] <= row_start <= task['window_end'] else 0.0
                if overlap > best_overlap or (point_row and overlap > 0 and overlap == best_overlap):
                    best_index = index
                    best_overlap = overlap
            return best_index

        rows_info = []
        for row_number in range(2, primary_row_count + 1):
            row_element = ensure_row(sheet_data, row_number)
            row_start = get_numeric_cell_value(row_element, f'G{row_number}')
            if row_start is None:
                continue
            row_end = get_numeric_cell_value(row_element, f'H{row_number}')
            if row_end is None or row_end < row_start:
                row_end = row_start
            rows_info.append((row_number, row_start, row_end, row_owner_index(row_start, row_end)))

        segments = []
        for row_number, row_start, row_end, task_index in rows_info:
            previous = segments[-1] if segments else None
            if (
                previous is not None
                and previous['task_index'] == task_index
                and row_number == previous['last_row'] + 1
                and row_start - previous['end'] <= task_gap_split
            ):
                previous['last_row'] = row_number
                previous['end'] = max(previous['end'], row_end)
            else:
                segments.append({
                    'task_index': task_index,
                    'first_row': row_number,
                    'last_row': row_number,
                    'start': row_start,
                    'end': row_end,
                })

        # Додаткова перевірка залишку поза тасками: короткий блок (до
        # task_outside_merge) дописуємо до попередньої таски, інакше табличка
        # обростає хвилинними рядками «Поза тасками». Якщо попередньої таски ще
        # не було — беремо найближчу наступну; довгий блок лишається окремо.
        for segment in segments:
            segment['owner_index'] = segment['task_index']
            segment['absorbed'] = False

        for segment_index, segment in enumerate(segments):
            if segment['task_index'] >= 0:
                continue
            if segment['end'] - segment['start'] > task_outside_merge:
                continue
            owner_index = -1
            for previous in reversed(segments[:segment_index]):
                if previous['owner_index'] >= 0:
                    owner_index = previous['owner_index']
                    break
            if owner_index < 0:
                for following in segments[segment_index + 1:]:
                    if following['task_index'] >= 0:
                        owner_index = following['task_index']
                        break
            if owner_index >= 0:
                segment['owner_index'] = owner_index
                segment['absorbed'] = True

        amber_fill_id = create_solid_fill(styles_root, 'FFFFF2CC')
        grey_fill_id = create_solid_fill(styles_root, 'FFF2F2F2')
        task_block_plain_style_id = create_border_style(styles_root, num_fmt_code='hh:mm:ss', include_border=False)
        task_block_filled_style_id = create_border_style(styles_root, fill_id=amber_fill_id, num_fmt_code='hh:mm:ss', include_border=False)
        task_block_outside_style_id = create_border_style(styles_root, fill_id=grey_fill_id, num_fmt_code='hh:mm:ss', include_border=False)
        # Назва таски (колонка I) — з переносом тексту і в шаховому порядку з її часом у J:
        # де час залитий, назва біла, і навпаки.
        task_block_name_plain_style_id = create_border_style(styles_root, include_border=False, wrap_text=True)
        task_block_name_filled_style_id = create_border_style(styles_root, fill_id=amber_fill_id, include_border=False, wrap_text=True)
        task_block_name_style_ids = {
            task_block_plain_style_id: task_block_name_filled_style_id,
            task_block_filled_style_id: task_block_name_plain_style_id,
            task_block_outside_style_id: task_block_name_plain_style_id,
        }

        outside_duration = 0.0
        merge_references = []
        for segment_index, segment in enumerate(segments):
            segment_duration = max(segment['end'] - segment['start'], 0.0)
            owner_index = segment['owner_index']
            if owner_index < 0:
                block_style_id = task_block_outside_style_id
                block_name = 'Поза тасками'
                block_duration = segment_duration
                outside_duration += segment_duration
            else:
                task = tasks[owner_index]
                block_style_id = task_block_filled_style_id if segment_index % 2 else task_block_plain_style_id
                block_name = task['name']
                if segment['absorbed']:
                    # Блок цілком поза вікном таски, але короткий — зараховуємо його
                    # повністю тій тасці, з якої працівник щойно вийшов.
                    block_duration = segment_duration
                else:
                    # Блок обрізається по вікну таски: довгий рядок активності не має
                    # витягувати таску за її due.
                    block_duration = max(min(segment['end'], task['window_end']) - max(segment['start'], task['start']), 0.0)
                    leftover = max(segment_duration - block_duration, 0.0)
                    if leftover <= task_outside_merge:
                        # Хвіст, що виліз за вікно, теж короткий — лишаємо його тасці.
                        block_duration = segment_duration
                    else:
                        outside_duration += leftover
                task['duration'] += block_duration

            # Блок таски: назва в I, час у J — обидві колонки з'єднані на всю висоту блоку.
            block_name_style_id = task_block_name_style_ids[block_style_id]
            first_row_element = ensure_row(sheet_data, segment['first_row'])
            upsert_inline_string(first_row_element, f'I{segment["first_row"]}', block_name, style_id=block_name_style_id)
            upsert_numeric_cell(first_row_element, f'J{segment["first_row"]}', block_duration, style_id=block_style_id)
            for row_number in range(segment['first_row'] + 1, segment['last_row'] + 1):
                row_element = ensure_row(sheet_data, row_number)
                upsert_styled_blank_cell(row_element, f'I{row_number}', block_name_style_id)
                upsert_styled_blank_cell(row_element, f'J{row_number}', block_style_id)
            if segment['last_row'] > segment['first_row']:
                merge_references.append(f'I{segment["first_row"]}:I{segment["last_row"]}')
                merge_references.append(f'J{segment["first_row"]}:J{segment["last_row"]}')

        # Під колонкою I, у рядку підсумків основної таблиці, — сума часу блоків із J.
        task_sum_row_number = primary_row_count + 1
        upsert_formula_cell(
            ensure_row(sheet_data, task_sum_row_number),
            f'I{task_sum_row_number}',
            f'SUM(J2:J{primary_row_count})',
            style_id=primary_time_total_style_id,
        )

        if merge_references:
            merge_cells = worksheet_root.find(f'{{{MAIN_NS}}}mergeCells')
            if merge_cells is None:
                merge_cells = ET.Element(f'{{{MAIN_NS}}}mergeCells')
                worksheet_children = list(worksheet_root)
                worksheet_root.insert(worksheet_children.index(sheet_data) + 1, merge_cells)
            for reference in merge_references:
                ET.SubElement(merge_cells, f'{{{MAIN_NS}}}mergeCell', {'ref': reference})
            merge_cells.attrib['count'] = str(len(merge_cells))

        # Час, не покритий жодною таскою і завеликий, щоб приклеїтись до сусідньої,
        # показуємо окремим рядком — щоб «Час разом» лишався рівним активному часу
        # дня, а не тихо дописувався останній тасці.
        # Менш як пів секунди — це похибка float від різниць часток доби, а не
        # робочий час: такий рядок показав би 00:00:00. Бекенду віддаємо рівно те
        # саме число, що потрапило у файл, щоб звіт не блокувався через похибку.
        outside_seconds_total = int(round(outside_duration * 86400)) if outside_duration >= 0.5 / 86400.0 else 0

        if outside_duration >= 0.5 / 86400.0:
            tasks.append({
                'name': 'Поза тасками',
                'comment': '',
                'duration': outside_duration,
            })

        # Табличка тасок: Назва таски / Коментар / Час + рядок «Час разом».
        task_wrapped_text_style_id = create_border_style(styles_root, wrap_text=True)
        task_time_style_id = create_border_style(styles_root, num_fmt_code='hh:mm:ss')
        task_total_label_style_id = create_border_style(styles_root, fill_id=yellow_fill_id, horizontal_alignment='left')
        task_total_value_style_id = create_border_style(styles_root, fill_id=yellow_fill_id, num_fmt_code='hh:mm:ss')

        name_column = column_letter(task_table_start_column_index + 1)
        comment_column = column_letter(task_table_start_column_index + 2)
        time_column = column_letter(task_table_start_column_index + 3)
        first_task_row = task_table_start_row_index + 2
        total_row_number = max(task_table_start_row_index + len(task_table_rows), first_task_row + len(tasks))

        for offset, task in enumerate(tasks):
            row_number = first_task_row + offset
            row_element = ensure_row(sheet_data, row_number)
            upsert_inline_string(row_element, f'{name_column}{row_number}', task['name'], style_id=task_wrapped_text_style_id)
            if task['comment']:
                upsert_inline_string(row_element, f'{comment_column}{row_number}', task['comment'], style_id=task_wrapped_text_style_id)
            upsert_numeric_cell(row_element, f'{time_column}{row_number}', task['duration'], style_id=task_time_style_id)

        # Рядок «Час разом» зафарбовується цілком — включно з порожніми клітинками.
        total_row_element = ensure_row(sheet_data, total_row_number)
        first_table_column_index = task_table_start_column_index
        last_table_column_index = task_table_start_column_index + 3
        for column_index_value in range(first_table_column_index, last_table_column_index + 1):
            upsert_styled_blank_cell(total_row_element, f'{column_letter(column_index_value)}{total_row_number}', task_total_label_style_id)
        upsert_inline_string(total_row_element, f'{name_column}{total_row_number}', 'Час разом', style_id=task_total_label_style_id)
        upsert_formula_cell(
            total_row_element,
            f'{time_column}{total_row_number}',
            f'SUM({time_column}{first_task_row}:{time_column}{total_row_number - 1})',
            style_id=task_total_value_style_id,
        )
        if task_time_total_cell is not None:
            task_time_row_element, task_time_reference = task_time_total_cell
            upsert_formula_cell(task_time_row_element, task_time_reference, f'{time_column}{total_row_number}', style_id=idle_summary_duration_style_id)

    # Усі числові значення (числа, формули й текст із самих цифр, як-от «01:23:45») — по центру колонки.
    def is_numeric_text(text):
        text = (text or '').strip()
        return any(ch.isdigit() for ch in text) and all(ch.isdigit() or ch in ' :.,%+-' for ch in text)

    def cell_text(cell):
        cell_type = cell.attrib.get('t')
        if cell_type == 'inlineStr':
            return ''.join(t.text or '' for t in cell.iter(f'{{{MAIN_NS}}}t'))
        value_element = cell.find(f'{{{MAIN_NS}}}v')
        if cell_type == 's' and value_element is not None and value_element.text is not None:
            index = int(value_element.text)
            return shared_strings[index] if index < len(shared_strings) else ''
        return value_element.text if value_element is not None else ''

    def is_numeric_cell(cell):
        cell_type = cell.attrib.get('t')
        if cell.find(f'{{{MAIN_NS}}}f') is not None:
            return True
        if cell_type in (None, 'n'):
            return cell.find(f'{{{MAIN_NS}}}v') is not None
        if cell_type in ('inlineStr', 's', 'str'):
            return is_numeric_text(cell_text(cell))
        return False

    center_cell_xfs = ensure_default_cell_xfs(styles_root)
    centered_style_ids = {}

    def centered_style_id(style_id):
        if style_id not in centered_style_ids:
            source_xf = center_cell_xfs[style_id] if style_id < len(center_cell_xfs) else center_cell_xfs[0]
            source_alignment = source_xf.find(f'{{{MAIN_NS}}}alignment')
            if source_alignment is not None and source_alignment.attrib.get('horizontal') == 'center':
                centered_style_ids[style_id] = style_id
            else:
                xf = ET.SubElement(center_cell_xfs, source_xf.tag, dict(source_xf.attrib))
                alignment_attributes = dict(source_alignment.attrib) if source_alignment is not None else {}
                alignment_attributes['horizontal'] = 'center'
                ET.SubElement(xf, f'{{{MAIN_NS}}}alignment', alignment_attributes)
                for child in source_xf:
                    if child.tag != f'{{{MAIN_NS}}}alignment':
                        xf.append(copy.deepcopy(child))
                xf.attrib['applyAlignment'] = '1'
                centered_style_ids[style_id] = len(center_cell_xfs) - 1
                center_cell_xfs.attrib['count'] = str(len(center_cell_xfs))
        return centered_style_ids[style_id]

    for cell in sheet_data.iter(f'{{{MAIN_NS}}}c'):
        if is_numeric_cell(cell):
            cell.attrib['s'] = str(centered_style_id(int(cell.attrib.get('s', '0'))))

    dimension = worksheet_root.find(f'{{{MAIN_NS}}}dimension')
    if dimension is not None:
        current_ref = dimension.attrib.get('ref', 'A1')
        start_ref, _, end_ref = current_ref.partition(':')
        end_ref = end_ref or start_ref
        _, end_row = split_cell_reference(end_ref)
        end_column = column_index(split_cell_reference(end_ref)[0])
        widest_secondary_row = max((len(row) for row in secondary_rows), default=1)
        widest_task_table_row = max((len(row) for row in task_table_rows), default=1)
        final_end_column = max(end_column, start_column_index + widest_secondary_row - 1, task_table_start_column_index + widest_task_table_row - 1)
        final_end_row = max(end_row, len(secondary_rows), primary_row_count + 1 if primary_row_count >= 3 else primary_row_count, task_table_start_row_index + len(task_table_rows))
        dimension.attrib['ref'] = f'{start_ref}:{column_letter(final_end_column)}{final_end_row}'

    worksheet_tree.write(worksheet_path, encoding='utf-8', xml_declaration=True)
    styles_tree.write(styles_path, encoding='utf-8', xml_declaration=True)

    if os.path.exists(output_path):
        os.remove(output_path)

    with zipfile.ZipFile(output_path, 'w', zipfile.ZIP_DEFLATED) as target_archive:
        for root, _, files in os.walk(temp_directory):
            for file_name in files:
                absolute_path = os.path.join(root, file_name)
                archive_name = os.path.relpath(absolute_path, temp_directory).replace(os.sep, '/')
                target_archive.write(absolute_path, archive_name)

if task_coverage_path:
    with open(task_coverage_path, 'w', encoding='utf-8') as coverage_file:
        json.dump({'outside_seconds': outside_seconds_total}, coverage_file)
`;

  // Скрипт завеликий для аргументу командного рядка Windows (ліміт 32767 симв.),
  // тому запускаємо його з тимчасового файлу.
  const pythonScriptPath = path.join(outputDirectory, `.yaware-combine-${Date.now()}.py`);
  await fs.writeFile(pythonScriptPath, pythonScript, 'utf8');
  // Хронологія завелика для змінної середовища, тому передаємо її файлом.
  const activityTimelinePath = path.join(outputDirectory, `.yaware-timeline-${Date.now()}.json`);
  await fs.writeFile(activityTimelinePath, JSON.stringify(activityTimeline), 'utf8');

  let pythonOutput = '';
  try {
    pythonOutput = await runCombinePythonScript(pythonScriptPath, combinedExcelPath, downloadedFilePath, secondaryRows, apiReportDate, activityTimelinePath);
  } finally {
    await fs.rm(pythonScriptPath, { force: true }).catch(() => null);
    await fs.rm(activityTimelinePath, { force: true }).catch(() => null);
  }

  if (String(pythonOutput).includes('ACTIVITY_TIMELINE_MISMATCH')) {
    reportWarning('точний час дій з API не збігся зі звітом, паузи пораховано без секунд, «Пропущене» не заповнено.');
  }

  return combinedExcelPath;
}

async function runCombinePythonScript(pythonScriptPath, combinedExcelPath, downloadedFilePath, secondaryRows, apiReportDate, activityTimelinePath) {
  const coveragePath = path.join(path.dirname(combinedExcelPath), `.yaware-coverage-${Date.now()}.json`);

  try {
    const { stdout } = await execFileAsync(pythonPath, [pythonScriptPath], {
      env: {
        ...process.env,
        TASK_COVERAGE_PATH: coveragePath,
        ACTIVITY_TIMELINE_PATH: activityTimelinePath,
        REPORT_PERIOD: apiReportDate,
        OUTPUT_XLSX_PATH: combinedExcelPath,
        SOURCE_XLSX_PATH: downloadedFilePath,
        SECONDARY_ROWS_JSON: JSON.stringify(secondaryRows.map((row) => row.map((cell) => ({
          value: cell.value ?? '',
          isSectionTitle: Boolean(cell.isSectionTitle),
          // Порожній відступ між таблицями — без рамки.
          isSpacerCell: Boolean(cell.isSpacerCell),
        })))),
        TASK_TABLE_ROWS_JSON: JSON.stringify(buildTaskTableRows().map((row) => row.map((cell) => ({
          value: cell.value ?? '',
          isTaskTableHeader: Boolean(cell.isTaskTableHeader),
        })))),
        SUMMARY_START_COLUMN_INDEX: String(SUMMARY_START_COLUMN_INDEX),
        TASK_TABLE_START_COLUMN_INDEX: String(TASK_TABLE_START_COLUMN_INDEX),
        TASK_TABLE_START_ROW_INDEX: String(TASK_TABLE_START_ROW_INDEX),
        TRELLO_TASKS_JSON: JSON.stringify(TRELLO_TASKS.map((task) => ({
          name: String(task.name ?? ''),
          comment: String(task.comment ?? ''),
          start: String(task.start ?? ''),
          due: String(task.due ?? ''),
        }))),
        REPORT_DATE_ISO: apiReportDate,
      },
      maxBuffer: 10 * 1024 * 1024,
    });

    outsideTasksSeconds = await readTaskCoverage(coveragePath);
    return stdout;
  } finally {
    await fs.rm(coveragePath, { force: true }).catch(() => null);
  }
}

/**
 * Час поза тасками з файлу, який залишив Python. -1 у файлі і будь-яка проблема
 * з читанням дають null: краще віддати звіт, ніж заблокувати його через збій
 * власного ж обміну даними.
 */
async function readTaskCoverage(coveragePath) {
  try {
    const parsed = JSON.parse(await fs.readFile(coveragePath, 'utf8'));
    const seconds = Number(parsed?.outside_seconds);

    return Number.isFinite(seconds) && seconds >= 0 ? seconds : null;
  } catch {
    return null;
  }
}

const EMPTY_DAY_MESSAGE = 'За обрану дату в Yaware немає даних активності, тому звіт не сформовано. Оберіть інший день.';

// Порожній день — остаточна відповідь Yaware, а не збій: повтор не допоможе.
function emptyDayError() {
  const error = new Error(EMPTY_DAY_MESSAGE);
  error.code = 'EMPTY_DAY';
  return error;
}

async function saveCombinedExcel(summaryPayload, dayReportPayload, downloadedFilePath, outputDirectory, apiReportDate, reportEmployeeName = '', idleActivitiesRows = null, activityTimeline = null) {
  // Формат — за вмістом, а не за іменем: без локалі (LANG) Chromium не розбирає
  // ім'я з Content-Disposition і зберігає файл як «download» без розширення,
  // і справжній xlsx ішов би в HTML-гілку як «порожній день».
  const fileHeader = await fs.readFile(downloadedFilePath);
  const isZipArchive = fileHeader.length >= 4 && fileHeader[0] === 0x50 && fileHeader[1] === 0x4b;

  if (isZipArchive) {
    return saveCombinedXlsx(summaryPayload, dayReportPayload, downloadedFilePath, outputDirectory, apiReportDate, reportEmployeeName, idleActivitiesRows, activityTimeline);
  }

  // У день без активності Yaware віддає файл з розширенням .xlsx, який насправді
  // не є zip-архівом — Python-скрипт падав би на zipfile.BadZipFile.
  if (path.extname(downloadedFilePath).toLowerCase() === '.xlsx') {
    throw emptyDayError();
  }

  return saveCombinedHtmlExcel(summaryPayload, dayReportPayload, downloadedFilePath, outputDirectory, apiReportDate, reportEmployeeName, idleActivitiesRows);
}

/**
 * Стежить за XHR/fetch сторінки від моменту створення. settle() чекає, поки
 * сторінка довантажить те, що запустили дії: після останнього запиту минуло
 * quietMs і нічого не в польоті. Замінює фіксовані паузи після networkidle —
 * той після першого затишшя сторінки резолвиться миттєво і нічого не чекав.
 *
 * Якщо жоден запит так і не стартував, settle() чекає fallbackMs — стільки,
 * скільки раніше тривала фіксована пауза. Запит, що висить довше за
 * STUCK_REQUEST_MS (довге опитування), тишу не блокує; maxMs — загальна стеля.
 */
const STUCK_REQUEST_MS = 5_000;

function trackDataRequests(page) {
  const inFlight = new Map();
  let lastActivityAt = 0;
  let seenRequest = false;

  // Лише запити самого Yaware (сайт і GraphQL data-api-3.yaware.com): аналітика
  // сторінки (Amplitude, GA, LinkedIn) шле XHR постійно і не має відношення до даних звіту.
  const isDataRequest = (request) => ['xhr', 'fetch'].includes(request.resourceType())
    && /(^|\.)yaware\.com(\.ua)?$/.test(new URL(request.url()).hostname);
  const onRequest = (request) => {
    if (!isDataRequest(request)) {
      return;
    }
    seenRequest = true;
    lastActivityAt = Date.now();
    inFlight.set(request, lastActivityAt);
    logTiming(`  xhr ${request.method()} ${request.url().slice(0, 160)}`);
  };
  const onDone = (request) => {
    if (inFlight.delete(request)) {
      lastActivityAt = Date.now();
    }
  };

  page.on('request', onRequest);
  page.on('requestfinished', onDone);
  page.on('requestfailed', onDone);

  return {
    async settle({ quietMs = 700, fallbackMs = 3_000, maxMs = 15_000 } = {}) {
      const settleStartedAt = Date.now();
      try {
        for (;;) {
          const now = Date.now();
          if (now - settleStartedAt >= maxMs) {
            logTiming('  settle: стеля очікування');
            return;
          }
          if (!seenRequest) {
            if (now - settleStartedAt >= fallbackMs) {
              return;
            }
          } else {
            const activeCount = [...inFlight.values()].filter((startedAt) => now - startedAt < STUCK_REQUEST_MS).length;
            const quietSince = Math.max(lastActivityAt, settleStartedAt);
            if (activeCount === 0 && now - quietSince >= quietMs) {
              return;
            }
          }
          await new Promise((resolve) => setTimeout(resolve, 100));
        }
      } finally {
        page.off('request', onRequest);
        page.off('requestfinished', onDone);
        page.off('requestfailed', onDone);
      }
    },
  };
}

// Strip "Name, email@host" → "Name" and return a lowercase word-set.
function idleNameWordSet(s) {
  const withoutEmail = (s || '').replace(/,\s*\S+@\S+/g, '').trim();
  const words = withoutEmail.toLowerCase().replace(/\s+/g, ' ').split(' ').filter(Boolean);
  return new Set(words);
}

// Two display names match when every word of the smaller set appears in the larger.
function idleNamesMatch(a, b) {
  const wa = idleNameWordSet(a);
  const wb = idleNameWordSet(b);
  if (wa.size === 0 || wb.size === 0) return false;
  const [small, large] = wa.size <= wb.size ? [wa, wb] : [wb, wa];
  return [...small].every((w) => large.has(w));
}

// Резервне зіставлення по email з клітинки "Працівник" ("Імʼя, email@host") —
// стійке до проблем з кодуванням кириличних імен.
function idleRowMatchesEmployee(cellValue, employeeName, employeeEmail) {
  if (idleNamesMatch(cellValue, employeeName)) {
    return true;
  }

  const normalizedEmail = String(employeeEmail || '').trim().toLowerCase();
  if (!normalizedEmail) {
    return false;
  }

  const cellEmail = String(cellValue || '').match(/\S+@\S+/);
  return Boolean(cellEmail && cellEmail[0].toLowerCase() === normalizedEmail);
}

async function setIdleActivitiesUserFilter(page, targetName) {
  const selectBtn = page.locator('.ywr-report-filter__select .ywr-select-btn').first();

  const btnFound = await selectBtn.waitFor({ state: 'visible', timeout: 30_000 }).then(() => true).catch(() => false);
  if (!btnFound) return;

  if (idleNameWordSet(targetName).size === 0) return; // No usable target — skip

  // Open the dropdown to read the real checkbox states
  await selectBtn.click();
  const menu = page.locator('.ywr-select-menu').first();
  await menu.waitFor({ state: 'visible', timeout: 10_000 });

  // Exclude the "Вибрати все" (Select All) header option
  const options = await page.evaluate(() =>
    [...document.querySelectorAll('.ywr-select-list__option:not(.ywr-select-list__option--head)')].map((li) => ({
      title: li.getAttribute('title') || '',
      text: (li.querySelector('.ywr-select-option__text')?.textContent || '').trim(),
      checked: li.querySelector('input[type="checkbox"]')?.checked || false,
    }))
  );

  const checkedOptions = options.filter((o) => o.checked);
  const targetOption = options.find((o) => idleNamesMatch(o.title || o.text, targetName));

  if (process.env.YAWARE_IDLE_DEBUG === 'true') {
    console.error('[idle-debug] filter target:', targetName);
    console.error('[idle-debug] filter options:', JSON.stringify(options));
    console.error('[idle-debug] matched option:', JSON.stringify(targetOption ?? null));
  }

  if (!targetOption) {
    // No option matches target name — close without changes
    await page.locator('body').click({ position: { x: 0, y: 0 } }).catch(() => null);
    return;
  }

  const isAlreadyCorrect = checkedOptions.length === 1
    && checkedOptions[0].title === targetOption.title;

  if (isAlreadyCorrect) {
    await page.locator('body').click({ position: { x: 0, y: 0 } }).catch(() => null);
    await page.waitForTimeout(300);
    return;
  }

  const requests = trackDataRequests(page);

  // Strategy: check target FIRST (keeps at least 1 selection, menu stays open),
  // then uncheck all other options.
  if (!targetOption.checked) {
    const targetLiFirst = menu.locator(`li[title="${targetOption.title}"]`).first();
    if ((await targetLiFirst.count()) > 0) {
      await targetLiFirst.locator('input[type="checkbox"]').click();
      await page.waitForTimeout(200);
    }
  }

  // Uncheck all other checked employee options (skip "Вибрати все" header)
  const employeeCheckboxes = menu.locator(
    '.ywr-select-list__option:not(.ywr-select-list__option--head) input[type="checkbox"]'
  );
  const count = await employeeCheckboxes.count();
  for (let i = 0; i < count; i++) {
    const cb = employeeCheckboxes.nth(i);
    if (!(await cb.isChecked())) continue;
    const parentTitle = await cb.evaluate((el) => el.closest('li[title]')?.getAttribute('title') ?? '');
    if (parentTitle === targetOption.title) continue; // keep the target
    await cb.click();
    await page.waitForTimeout(80);
  }

  await page.locator('body').click({ position: { x: 0, y: 0 } }).catch(() => null);
  await requests.settle();
}

async function setIdleActivitiesDate(page, reportDate) {
  const [targetDay, targetMonth, targetYear] = reportDate.split('.').map(Number);
  const requests = trackDataRequests(page);

  const datePickerWrapper = page.locator('.date-picker-wrapper').first();
  await datePickerWrapper.waitFor({ state: 'visible', timeout: 30_000 });

  const prevBtn = page.locator('.date-picker__btn-prev').first();
  const nextBtn = page.locator('.date-picker__btn-next').first();

  // Read current date: try Vue instance first, then input value, then fall back to today
  const currentInfo = await page.evaluate(() => {
    const parseDateStr = (val) => {
      if (!val) return null;
      const m1 = val.trim().match(/^(\d{2})\.(\d{2})\.(\d{4})$/);
      if (m1) return { year: +m1[3], month: +m1[2], day: +m1[1] };
      const m2 = val.trim().match(/^(\d{4})-(\d{2})-(\d{2})$/);
      if (m2) return { year: +m2[1], month: +m2[2], day: +m2[3] };
      return null;
    };

    // Try the date-picker-wrapper Vue component
    const wrapper = document.querySelector('.date-picker-wrapper');
    const wVue = wrapper?.__vue__;
    if (wVue) {
      for (const key of ['value', 'date', 'currentDate', 'modelValue']) {
        const d = wVue[key] ?? wVue.$data?.[key];
        if (d instanceof Date && !isNaN(d)) {
          return { year: d.getFullYear(), month: d.getMonth() + 1, day: d.getDate() };
        }
      }
    }

    // Try the inner El Date Picker Vue component
    const picker = document.querySelector('.date-picker-wrapper .el-date-editor');
    const pVue = picker?.__vue__;
    if (pVue) {
      for (const key of ['value', 'parsedValue', 'date']) {
        const d = pVue[key] ?? pVue.$data?.[key];
        if (d instanceof Date && !isNaN(d)) {
          return { year: d.getFullYear(), month: d.getMonth() + 1, day: d.getDate() };
        }
      }
    }

    // Try the readonly input value
    const input = document.querySelector('.date-picker-wrapper .el-input__inner');
    const parsed = parseDateStr(input?.value);
    if (parsed) return parsed;

    return null;
  });

  // Fall back to today if nothing could be read
  const now = new Date();
  const current = currentInfo ?? { year: now.getFullYear(), month: now.getMonth() + 1, day: now.getDate() };

  const targetTs = Date.UTC(targetYear, targetMonth - 1, targetDay);
  const currentTs = Date.UTC(current.year, current.month - 1, current.day);
  const diffDays = Math.round((targetTs - currentTs) / 86400000);

  if (diffDays !== 0) {
    const btn = diffDays > 0 ? nextBtn : prevBtn;
    const clicks = Math.min(Math.abs(diffDays), 365);
    for (let i = 0; i < clicks; i++) {
      await btn.click();
      await page.waitForTimeout(200);
    }
  }

  await requests.settle({ fallbackMs: diffDays === 0 ? 0 : 2_000 });
}

async function downloadIdleActivitiesXls(page, reportDate, employeeName) {
  const pageLoadRequests = trackDataRequests(page);
  await page.goto(IDLE_ACTIVITIES_URL, { waitUntil: 'domcontentloaded' });
  await page.waitForLoadState('networkidle').catch(() => null);
  await pageLoadRequests.settle({ quietMs: 1_000, fallbackMs: 4_000 });

  logTiming('офлайн-звіт: сторінка відкрита');
  await setIdleActivitiesDate(page, reportDate);
  logTiming('офлайн-звіт: дату виставлено');
  await setIdleActivitiesUserFilter(page, employeeName);
  logTiming('офлайн-звіт: працівника вибрано');

  const exportBtn = page
      .locator('button[title="Експорт в xls"]')
      .or(page.locator('.yaware-grid-toolbar__button--xls'))
      .first();

  await exportBtn.waitFor({ state: 'visible', timeout: 30_000 });

  const downloadPromise = page.waitForEvent('download', { timeout: 60_000 }).catch(() => null);
  await exportBtn.click();

  const download = await downloadPromise;
  if (!download) {
    return null;
  }

  const suggestedFilename = download.suggestedFilename();
  const tempPath = path.join(downloadDirectory, `${TEMP_DOWNLOAD_PREFIX}idle-${suggestedFilename}`);
  await download.saveAs(tempPath);
  return tempPath;
}

async function parseIdleActivitiesXls(filePath) {
  const buffer = await fs.readFile(filePath);
  const contentUtf8 = buffer.toString('utf8');

  if (contentUtf8.includes('<table')) {
    return extractWorksheetTableRows(contentUtf8);
  }

  const contentLatin1 = buffer.toString('latin1');
  if (contentLatin1.includes('<table')) {
    return extractWorksheetTableRows(contentLatin1);
  }

  // Binary XLSX format — extract rows via Python
  const pythonScript = `
import json, os, xml.etree.ElementTree as ET, zipfile

MAIN_NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main'
REL_NS = 'http://schemas.openxmlformats.org/package/2006/relationships'
OFF_REL_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships'
source_path = os.environ['IDLE_XLS_PATH']

def load_shared_strings(archive):
    try:
        with archive.open('xl/sharedStrings.xml') as f:
            root = ET.parse(f).getroot()
        result = []
        for si in root.findall(f'{{{MAIN_NS}}}si'):
            parts = []
            t = si.find(f'{{{MAIN_NS}}}t')
            if t is not None and t.text:
                parts.append(t.text)
            for rt in si.findall(f'.//{{{MAIN_NS}}}r/{{{MAIN_NS}}}t'):
                if rt.text:
                    parts.append(rt.text)
            result.append(''.join(parts))
        return result
    except Exception:
        return []

def get_cell_text(cell, shared):
    t = cell.attrib.get('t', '')
    v = cell.find(f'{{{MAIN_NS}}}v')
    if t == 's':
        idx = int(v.text) if v is not None and v.text else 0
        return shared[idx] if 0 <= idx < len(shared) else ''
    if t == 'inlineStr':
        node = cell.find(f'{{{MAIN_NS}}}is/{{{MAIN_NS}}}t')
        return (node.text or '') if node is not None else ''
    return (v.text or '') if v is not None else ''

def col_idx(ref):
    letters = ''.join(c for c in ref if c.isalpha())
    n = 0
    for c in letters:
        n = n * 26 + (ord(c.upper()) - 64)
    return n - 1

with zipfile.ZipFile(source_path) as archive:
    shared = load_shared_strings(archive)
    with archive.open('xl/workbook.xml') as f:
        wb_root = ET.parse(f).getroot()
    first_sheet = wb_root.find(f'{{{MAIN_NS}}}sheets/{{{MAIN_NS}}}sheet')
    r_id = first_sheet.attrib.get(f'{{{OFF_REL_NS}}}id')
    with archive.open('xl/_rels/workbook.xml.rels') as f:
        rels_root = ET.parse(f).getroot()
    ws_target = next(
        (r.attrib['Target'] for r in rels_root.findall(f'{{{REL_NS}}}Relationship') if r.attrib.get('Id') == r_id),
        None
    )
    ws_path = ws_target if ws_target and ws_target.startswith('xl/') else f'xl/{ws_target}'
    with archive.open(ws_path) as f:
        ws_root = ET.parse(f).getroot()
    sheet_data = ws_root.find(f'{{{MAIN_NS}}}sheetData')
    rows = []
    for row_el in sheet_data.findall(f'{{{MAIN_NS}}}row'):
        cells = {}
        for cell in row_el.findall(f'{{{MAIN_NS}}}c'):
            ref = cell.attrib.get('r', '')
            if ref:
                cells[col_idx(ref)] = get_cell_text(cell, shared)
        if cells:
            max_col = max(cells)
            rows.append([cells.get(i, '') for i in range(max_col + 1)])
    print(json.dumps(rows, ensure_ascii=False))
`;

  const { stdout } = await execFileAsync(pythonPath, ['-c', pythonScript], {
    env: {
      ...process.env,
      IDLE_XLS_PATH: filePath,
      // Без цього stdout Python на Windows — cp1251, і кирилиця в іменах ламається.
      PYTHONIOENCODING: 'utf-8',
    },
    maxBuffer: 10 * 1024 * 1024,
  });

  const rawRows = JSON.parse(stdout.trim());
  return rawRows.map((row) => row.map((value) => ({ value: String(value ?? ''), type: 'td' })));
}

if (WORKER_MODE && (!ENV_YAWARE_EMAIL || !ENV_YAWARE_PASSWORD || !ENV_YAWARE_DATE)) {
  console.error('Worker-режим вимагає env-змінні YAWARE_EMAIL, YAWARE_PASSWORD та YAWARE_DATE.');
  process.exit(2);
}

const savedCredentials = await loadSavedCredentials();
const {
  email: INITIAL_YAWARE_EMAIL,
  password: INITIAL_YAWARE_PASSWORD,
  reportDate: REPORT_DATE,
  apiReportDate: API_REPORT_DATE,
  shouldPersistCredentials: SHOULD_PERSIST_CREDENTIALS,
} = await promptRuntimeConfig(savedCredentials);

let yawareEmail = INITIAL_YAWARE_EMAIL;
let yawarePassword = INITIAL_YAWARE_PASSWORD;

await fs.mkdir(downloadDirectory, { recursive: true });

updateProgress(5, 'Запуск браузера');

const browser = await chromium.launch({
  headless: YAWARE_HEADLESS !== 'false',
  // OpenVZ-контейнери (наш VPS) не дають Chromium створити пісочницю —
  // на Linux вимикаємо її; YAWARE_CHROMIUM_SANDBOX=true повертає назад.
  chromiumSandbox: platform === 'win32' || process.env.YAWARE_CHROMIUM_SANDBOX === 'true',
});

const context = await browser.newContext({
  acceptDownloads: true,
  downloadsPath: downloadDirectory,
});

// Аналітика й реклама на сторінках Yaware не потрібні для звіту, але без
// кінця шлють запити: networkidle після входу через них чекав повний таймаут
// (30 с замість ~6). Обриваємо їх на рівні браузера.
const TRACKER_HOST_PATTERN = /(^|\.)(amplitude\.com|google-analytics\.com|googletagmanager\.com|doubleclick\.net|linkedin\.com|licdn\.com|facebook\.(com|net)|hotjar\.(com|io)|clarity\.ms|yandex\.(ru|net)|bing\.com)$/;
const blockedTrackerHosts = new Set();
await context.route((url) => TRACKER_HOST_PATTERN.test(url.hostname), (route) => {
  const { hostname } = new URL(route.request().url());
  if (!blockedTrackerHosts.has(hostname)) {
    blockedTrackerHosts.add(hostname);
    logTiming(`  трекер заблоковано: ${hostname}`);
  }
  return route.abort();
});

const page = await context.newPage();

async function performLogin(page, email, password) {
  const emailSelectors = [
    'input[type="email"]',
    'input[name="email"]',
    'input[name="login"]',
    'input[name="username"]',
    '#email',
    '#login',
  ];

  const passwordSelectors = [
    'input[type="password"]',
    'input[name="password"]',
    '#password',
  ];

  let emailFilled = false;
  for (const selector of emailSelectors) {
    const field = page.locator(selector).first();
    if (await field.count()) {
      await field.fill(email);
      emailFilled = true;
      break;
    }
  }

  if (!emailFilled) {
    throw new Error('Could not find the login/email input on the Yaware login page.');
  }

  let passwordFilled = false;
  for (const selector of passwordSelectors) {
    const field = page.locator(selector).first();
    if (await field.count()) {
      await field.fill(password);
      passwordFilled = true;
      break;
    }
  }

  if (!passwordFilled) {
    throw new Error('Could not find the password input on the Yaware login page.');
  }

  const loginButton = page.locator('#login-submit').or(page.getByRole('button', { name: 'Вхід у систему' })).first();
  await loginButton.waitFor({ state: 'visible', timeout: 15_000 });
  await Promise.all([
    page.waitForLoadState('networkidle').catch(() => null),
    loginButton.click(),
  ]);

  const invalidLoginMessage = await page.evaluate(() => {
    const errorHints = [
      'невір',
      'неправиль',
      'invalid',
      'incorrect',
      'wrong',
      'парол',
      'password',
      'пошт',
      'email',
      'логін',
    ];

    const elements = [
      ...document.querySelectorAll('.alert, .error, .errors, .help-block, .text-danger, .invalid-feedback, [role="alert"]'),
    ];

    for (const element of elements) {
      const message = element.textContent?.trim();
      const normalizedMessage = message?.toLowerCase();
      if (normalizedMessage && errorHints.some((hint) => normalizedMessage.includes(hint))) {
        return message;
      }
    }

    return '';
  });

  const currentUrl = page.url();
  const stillOnLoginPage = currentUrl.includes('/login') || currentUrl.includes('/signin');
  if (invalidLoginMessage || stillOnLoginPage) {
    const error = new Error(invalidLoginMessage || 'Неправильна пошта або пароль.');
    error.code = 'INVALID_CREDENTIALS';
    throw error;
  }
}

// Сторінка звітів з 2026-10-28 — Vue + Element UI (як офлайн-звіт), а не ExtJS:
// дата — el-date-picker типу daterange, працівник — ywr-select з іменами,
// таблиця — el-table, експорт формує xlsx у браузері з GraphQL data-api-3.
function reportPageChangedError(what, cause) {
  const error = new Error(`На сторінці звітів Yaware не знайдено ${what}. Найімовірніше, Yaware змінив сторінку — скрипт потребує оновлення; рідше причина в тому, що акаунту не надано доступу до звітів.`);
  error.code = 'REPORTS_PAGE_UNAVAILABLE';
  error.cause = cause;
  return error;
}

async function setReportDate(page, reportDate) {
  const rangeEditor = page.locator('.el-range-editor').first();

  try {
    await rangeEditor.waitFor({ state: 'visible', timeout: 30_000 });
  } catch (cause) {
    throw reportPageChangedError('поле вибору дати', cause);
  }

  const [day, month, year] = reportDate.split('.').map(Number);
  const isoDate = `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
  const requests = trackDataRequests(page);
  // Дата застосована, коли сторінка запитала дані саме за неї: внутрішнє
  // значення пікера нормалізується в часовому поясі акаунта і з датою
  // браузера напряму не порівнюється.
  const dateApplied = page.waitForRequest((request) => {
    const body = request.postData() || '';
    return request.url().includes('/graphql/')
      && body.includes(`"fromISO":"${isoDate}"`)
      && body.includes(`"toISO":"${isoDate}"`);
  }, { timeout: 15_000 });
  dateApplied.catch(() => null);
  // Обгортка date-range-picker пробрасує onInput([від, до]) у фільтр звіту —
  // так само, як вибір дати в календарі.
  await page.evaluate(({ y, m, d }) => {
    const picker = document.querySelector('.el-range-editor')?.__vue__;
    if (!picker || typeof picker.onInput !== 'function') {
      throw new Error('Could not find the date range picker component.');
    }
    const date = new Date(y, m - 1, d);
    picker.onInput([date, new Date(date)]);
  }, { y: year, m: month, d: day });

  try {
    await dateApplied;
  } catch (cause) {
    throw reportPageChangedError(`запиту даних за ${reportDate} після вибору дати`, cause);
  }

  await requests.settle();
}

async function setEmployeeFilter(page, employeeName) {
  // Груп і користувачів — два однакові ywr-select; потрібен той, де є працівник.
  const userSelect = page
      .locator('.ywr-report-filter__select')
      .filter({ has: page.locator('.ywr-select-list__option') })
      .last();

  try {
    await userSelect.locator('.ywr-select-btn').waitFor({ state: 'visible', timeout: 30_000 });
  } catch (cause) {
    throw reportPageChangedError('фільтр працівників', cause);
  }

  await userSelect.locator('.ywr-select-btn').click();
  const menu = userSelect.locator('.ywr-select-menu');
  await menu.waitFor({ state: 'visible', timeout: 10_000 });

  const options = await menu.locator('.ywr-select-list__option').evaluateAll((items) => items.map((item) => ({
    title: item.getAttribute('title') || '',
    checked: Boolean(item.querySelector('input[type="checkbox"]')?.checked),
  })));
  // У списку «Ім'я Прізвище», в API — «Прізвище Ім'я»: зіставляємо за словами.
  const matches = options.filter((option) => idleNamesMatch(option.title, employeeName));
  if (matches.length !== 1) {
    await page.locator('body').click({ position: { x: 0, y: 0 } }).catch(() => null);
    throw new Error(matches.length === 0
      ? `У фільтрі працівників Yaware немає «${employeeName}».`
      : `У фільтрі працівників Yaware кілька збігів для «${employeeName}».`);
  }
  const target = matches[0];

  const requests = trackDataRequests(page);
  // Чекбокси сховані стилями — клікаємо підписи. Спершу ціль, щоб вибір
  // ніколи не ставав порожнім, потім знімаємо решту.
  if (!target.checked) {
    await menu.locator(`li[title="${target.title}"]`).first().locator('label').click();
  }
  for (const option of options) {
    if (option.checked && option.title !== target.title) {
      await menu.locator(`li[title="${option.title}"]`).first().locator('label').click();
    }
  }

  await page.waitForFunction((targetTitle) => {
    const checked = [...document.querySelectorAll('.ywr-select-list__option')]
        .filter((item) => item.querySelector('input[type="checkbox"]')?.checked)
        .map((item) => item.getAttribute('title'));
    return checked.length === 1 && checked[0] === targetTitle;
  }, target.title, { timeout: 10_000 });

  await page.locator('body').click({ position: { x: 0, y: 0 } }).catch(() => null);
  await requests.settle();
}

async function filterByFirstAction(page) {
  const firstActionHeader = page.locator('th', { has: page.locator('.column-content', { hasText: 'Перша дія' }) }).first();

  try {
    await firstActionHeader.waitFor({ state: 'visible', timeout: 30_000 });
  } catch (cause) {
    throw reportPageChangedError('колонку «Перша дія»', cause);
  }

  // el-table перемикає сортування по колу: ascending → descending → без сортування.
  for (let attempt = 0; attempt < 3; attempt += 1) {
    if (((await firstActionHeader.getAttribute('class')) || '').split(/\s+/).includes('ascending')) {
      return;
    }
    const requests = trackDataRequests(page);
    await firstActionHeader.locator('.cell').click();
    await requests.settle();
  }
}

try {
  updateProgress(12, 'Відкриття сторінки входу');
  let isLoggedIn = false;
  while (!isLoggedIn) {
    await page.goto(LOGIN_URL, { waitUntil: 'domcontentloaded' });

    try {
      await performLogin(page, yawareEmail, yawarePassword);
      isLoggedIn = true;
    } catch (error) {
      if (error?.code !== 'INVALID_CREDENTIALS' || WORKER_MODE) {
        throw error;
      }

      clearProgressBar();
      output.write('\n\x1b[31mНеправильна пошта або пароль\x1b[0m\n');
      const retriedCredentials = await promptCredentialsRetry();
      yawareEmail = retriedCredentials.email;
      yawarePassword = retriedCredentials.password;
    }
  }

  updateProgress(28, 'Вхід виконано');

  // Режим перевірки логіну: підтверджуємо креди, повертаємо дані працівника і виходимо.
  if (WORKER_MODE && process.env.YAWARE_CHECK_LOGIN === 'true') {
    let checkEmployee = null;
    try {
      checkEmployee = await fetchEmployeeByEmail(TARGET_EMPLOYEE_EMAIL || yawareEmail);
    } catch {
      // Логін валідний, навіть якщо API не знайшло профіль — employee лишиться null.
    }
    output.write(`${JSON.stringify({ status: 'ok', check: 'login', employee: checkEmployee })}\n`);
    await context.close();
    await browser.close();
    process.exit(0);
  }

  const employee = await fetchEmployeeByEmail(TARGET_EMPLOYEE_EMAIL || yawareEmail);
  const employeeId = employee.id;
  updateProgress(40, 'Отримано ID працівника');

  // Дані GraphQL не залежать від фільтрів сторінки — тягнемо їх, поки браузер
  // налаштовує фільтри й вивантажує Excel. Помилку перехопить await нижче;
  // порожній catch лише не дає Node вбити процес як «необроблену» відмову.
  const reportPayloadsPromise = Promise.all([
    fetchSummaryByPeriod(API_REPORT_DATE, employeeId),
    fetchReportByDay(API_REPORT_DATE, employeeId),
  ]);
  reportPayloadsPromise.catch(() => null);
  const activityTimelinePromise = fetchActivityTimeline(API_REPORT_DATE, employeeId).catch((timelineError) => {
    reportWarning(`не вдалось отримати точний час дій: ${timelineError.message}`);
    return null;
  });

  await page.goto(REPORT_URL, { waitUntil: 'domcontentloaded' });
  updateProgress(52, 'Налаштування фільтрів звіту');

  await setReportDate(page, REPORT_DATE);
  logTiming('дату виставлено');
  await setEmployeeFilter(page, employee.name);
  logTiming('працівника вибрано');
  await filterByFirstAction(page);
  updateProgress(65, 'Фільтри застосовано');

  const exportButton = page
      .locator('button[title="Експорт в xls"]')
      .or(page.locator('.yaware-grid-toolbar__button--xls'))
      .first();

  await exportButton.waitFor({ state: 'visible', timeout: 30_000 });

  const downloadPromise = page.waitForEvent('download', { timeout: 60_000 }).catch(() => null);
  await exportButton.click();
  updateProgress(75, 'Очікування завантаження файлу');

  const download = await downloadPromise;
  if (download) {
    const suggestedFilename = download.suggestedFilename();
    const temporarySourcePath = path.join(downloadDirectory, `${TEMP_DOWNLOAD_PREFIX}${suggestedFilename}`);
    await download.saveAs(temporarySourcePath);
    updateProgress(85, 'Файл завантажено, формується підсумковий звіт');

    let idleXlsPath = null;
    try {
      const [summaryPayload, dayReportPayload] = await reportPayloadsPromise;
      updateProgress(88, 'Завантаження звіту офлайн активності');
      let idleActivitiesRows = null;
      try {
        // Prefer the employee display name from the primary report (E2 cell) over the API name,
        // as that is the exact name Yaware uses in its own dropdowns.
        let idleEmployeeName = employee.name;
        try {
          const primaryBuf = await fs.readFile(temporarySourcePath);
          const primaryUtf8 = primaryBuf.toString('utf8');
          const primaryLatin1 = primaryBuf.toString('latin1');
          const primaryContent = primaryUtf8.includes('<table') ? primaryUtf8 : primaryLatin1;
          if (primaryContent.includes('<table')) {
            const primaryRows = extractWorksheetTableRows(primaryContent);
            const nameFromE2 = getPrimaryCellValue(primaryRows, 2, 4);
            if (nameFromE2) idleEmployeeName = nameFromE2;
          }
        } catch (_nameErr) {}
        // Лише після основного вивантаження і в тій самій вкладці: фільтр
        // користувачів у Yaware спільний для сесії, і паралельна вкладка з
        // офлайн-звітом підміняла його основному — Excel виходив по всіх
        // працівниках (перевірено заміром на проді 2026-10-12).
        idleXlsPath = await downloadIdleActivitiesXls(page, REPORT_DATE, idleEmployeeName);
        if (idleXlsPath) {
          const allIdleRows = await parseIdleActivitiesXls(idleXlsPath);
          if (process.env.YAWARE_IDLE_DEBUG === 'true') {
            console.error('[idle-debug] target name:', idleEmployeeName);
            console.error('[idle-debug] rows count:', allIdleRows.length);
            console.error('[idle-debug] col0/col1 per row:', JSON.stringify(allIdleRows.map((r) => [r[0]?.value ?? '', r[1]?.value ?? ''])));
          }
          if (!allIdleRows || allIdleRows.length === 0) {
            reportWarning('звіт офлайн активності завантажено, але таблиця виявилась порожньою.');
          } else if (idleEmployeeName && idleNameWordSet(idleEmployeeName).size > 0) {
            // Filter to only the target employee (column 1 = "Працівник")
            const [headerRow, ...dataRows] = allIdleRows;
            const idleTargetEmail = TARGET_EMPLOYEE_EMAIL || yawareEmail;
            const filteredRows = dataRows.filter((row) => idleRowMatchesEmployee(row[1]?.value || '', idleEmployeeName, idleTargetEmail));
            idleActivitiesRows = filteredRows.length > 0 ? [headerRow, ...filteredRows] : null;
            if (!idleActivitiesRows) {
              reportWarning('офлайн активності для цього працівника за обрану дату відсутні.');
            }
          } else {
            idleActivitiesRows = allIdleRows;
          }
        } else {
          reportWarning('звіт офлайн активності не завантажено (файл не отримано від браузера).');
        }
      } catch (idleErr) {
        reportWarning(`не вдалось завантажити звіт офлайн активності: ${idleErr.message}`);
      }
      const activityTimeline = await activityTimelinePromise;
      const combinedExcelPath = await saveCombinedExcel(summaryPayload, dayReportPayload, temporarySourcePath, downloadDirectory, API_REPORT_DATE, employee.name, idleActivitiesRows, activityTimeline);
      if (SHOULD_PERSIST_CREDENTIALS) {
        await saveCredentials(yawareEmail, yawarePassword);
      }
      let historyDataPath = null;
      if (WORKER_MODE) {
        try {
          const historyData = buildHistoryData(summaryPayload, dayReportPayload, API_REPORT_DATE, idleActivitiesRows);
          historyDataPath = path.join(downloadDirectory, `history-data-${API_REPORT_DATE}.json`);
          await fs.writeFile(historyDataPath, JSON.stringify(historyData), 'utf8');
        } catch (historyError) {
          historyDataPath = null;
          reportWarning(`дані для історичної БД не сформовано: ${historyError.message}`);
        }
      }
      finishProgress(`Готово · ${path.basename(combinedExcelPath)}`);
      if (WORKER_MODE) {
        output.write(`${JSON.stringify({
          status: 'ok',
          file: combinedExcelPath,
          dataFile: historyDataPath,
          // Час дня, не покритий тасками (секунди); null — не рахувався.
          outsideSeconds: outsideTasksSeconds,
          employee: { id: employeeId, name: employee.name },
          summary: mapSummaryRow(summaryPayload, API_REPORT_DATE, employee.name),
          warnings: workerWarnings,
        })}\n`);
      } else {
        await openDirectory(path.dirname(combinedExcelPath)).catch((error) => {
          console.warn(`Could not open download directory automatically: ${error.message}`);
        });
      }
    } finally {
      await fs.rm(temporarySourcePath, { force: true }).catch(() => null);
      if (idleXlsPath) {
        await fs.rm(idleXlsPath, { force: true }).catch(() => null);
      }
    }
  } else {
    finishProgress('Завершено без файлу');
    throw new Error('Після натискання кнопки експорту файл не був завантажений протягом 60 секунд.');
  }
} catch (error) {
  if (!WORKER_MODE) {
    throw error;
  }

  // Скріншот сторінки в момент падіння — лишається на диску в теці звіту
  // (storage/app/reports/{id}) для діагностики; у веб-інтерфейс не потрапляє.
  // Для перевірки логіну і неправильних кредів скріншот не потрібен.
  let screenshotPath = null;
  if (process.env.YAWARE_CHECK_LOGIN !== 'true' && error?.code !== 'INVALID_CREDENTIALS') {
    screenshotPath = path.join(downloadDirectory, 'error-screenshot.png');
    try {
      await fs.mkdir(downloadDirectory, { recursive: true });
      await page.screenshot({ path: screenshotPath, fullPage: true });
    } catch {
      screenshotPath = null;
    }
  }

  console.error(error?.stack || String(error));
  output.write(`${JSON.stringify({
    status: 'error',
    message: error?.message || 'Невідома помилка генерації звіту.',
    // Бекенд за кодом вирішує, чи варто пробувати ще раз: неправильні креди
    // чи порожній день повтор не виправить, а мережевий збій — цілком.
    code: typeof error?.code === 'string' ? error.code : null,
    screenshot: screenshotPath,
  })}\n`);
  process.exitCode = 1;
} finally {
  await context.close();
  await browser.close();
}
