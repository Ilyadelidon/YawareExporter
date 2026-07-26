// Мобільний аудит верстки: скріншоти всіх сторінок SPA у мобільному в'юпорті.
// Запуск: node mobile-audit.mjs <baseUrl> <apiToken> <outDir> [width] [height]
import { chromium } from 'playwright-core';
import path from 'node:path';

const [baseUrl, token, outDir, widthArg, heightArg] = process.argv.slice(2);
const width = Number(widthArg || 390);
const height = Number(heightArg || 844);

const browser = await chromium.launch({ headless: true });

async function newPage(withAuth) {
  const context = await browser.newContext({
    viewport: { width, height },
    deviceScaleFactor: 2,
    isMobile: true,
    hasTouch: true,
  });
  const page = await context.newPage();
  if (withAuth) {
    await page.addInitScript((t) => localStorage.setItem('auth_token', t), token);
  }
  return { context, page };
}

async function shoot(page, name) {
  await page.waitForLoadState('networkidle').catch(() => null);
  await page.waitForTimeout(700);
  await page.screenshot({ path: path.join(outDir, `${name}-${width}.png`), fullPage: true });
  console.log(`OK ${name}-${width}.png`);
}

// 1. Логін (без токена)
{
  const { context, page } = await newPage(false);
  await page.goto(`${baseUrl}/login`, { waitUntil: 'domcontentloaded' });
  await shoot(page, 'login');
  await context.close();
}

// Решта сторінок — під адміном
const { context, page } = await newPage(true);

await page.goto(`${baseUrl}/`, { waitUntil: 'domcontentloaded' });
await shoot(page, 'reports-today');

// Звіт за 09.07 (є completed-звіт з даними): відкриваємо датапікер і обираємо 9-те
try {
  await page.locator('.field-pill input').first().click();
  await page.locator('.p-datepicker-panel td:not(.p-datepicker-other-month) span', { hasText: /^9$/ }).first().click();
  await page.waitForTimeout(2000);
  await shoot(page, 'reports-done');
} catch (error) {
  console.log(`SKIP reports-done: ${error.message}`);
}

for (const [route, name] of [['/history', 'history'], ['/timesheet', 'timesheet'], ['/employees', 'employees']]) {
  await page.goto(`${baseUrl}${route}`, { waitUntil: 'domcontentloaded' });
  await shoot(page, name);
}

await context.close();
await browser.close();
