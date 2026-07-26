// Разова перевірка панелі «Google Таблиця» (форма підключення наявної таблиці).
// Запуск: node ui-verify-link.mjs <baseUrl> <apiToken> <outPath>
import { chromium } from 'playwright-core';

const [baseUrl, token, outPath] = process.argv.slice(2);

const browser = await chromium.launch({ headless: true });
const context = await browser.newContext({ viewport: { width: 1440, height: 1100 }, deviceScaleFactor: 2 });
const page = await context.newPage();
await page.addInitScript((t) => localStorage.setItem('auth_token', t), token);

await page.goto(`${baseUrl}/`, { waitUntil: 'domcontentloaded' });
await page.waitForLoadState('networkidle').catch(() => null);
await page.waitForTimeout(1000);

await page.locator('.strip-row', { hasText: 'Google Таблиця' }).locator('.strip-manage-btn').click();
await page.waitForTimeout(700);
await page.locator('.integrations-strip-wrap').screenshot({ path: outPath });
console.log('OK', outPath);

await browser.close();
