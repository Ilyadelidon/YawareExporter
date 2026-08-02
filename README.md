# Yaware Exporter (TeamReporter)

Автоматизація звітності по робочому часу для невеликої команди. Сервіс збирає
дані з тайм-трекера [Yaware](https://yaware.com.ua/), поєднує їх із тасками
Trello, формує Excel і вивантажує в персональні Google Таблиці. Працівник
відкриває сторінку і за кілька секунд бачить, як пройшов день, без ручної
роботи в Yaware та Excel.

Прод: **https://teamreporter.space**

## Архітектура

Три частини, що лежать поруч у корені репозиторію:

| Тека | Що це | Стек |
|---|---|---|
| `backend/` | REST API, черги, планувальник, інтеграції | Laravel 13, PHP 8.3, Sanctum, SQLite |
| `frontend/` | SPA (окремий застосунок, не Blade) | Vue 3, Vite 6, PrimeVue 4, Pinia |
| `app/` | Воркер вивантаження звітів | Node + Playwright, Python + openpyxl |

Потік генерації звіту:

1. SPA викликає `POST /api/reports` → Laravel ставить джобу `GenerateYawareReport`
   у чергу `default`.
2. Джоба запускає Node-воркер `app/scripts/export-yaware-xls.mjs`. Той логіниться
   в Yaware через Chromium (Playwright), а самі дані тягне з GraphQL API
   `data-api-3.yaware.com`, яким користується вебдодаток Yaware.
3. Python з `openpyxl` доклеює до вивантаженого Excel додаткові аркуші.
4. Готовий файл прив'язується до `Report`, за потреби синхронізується в Google
   Таблицю користувача; у Trello створюються картки, у Telegram іде сповіщення.

Вхід у систему теж асинхронний: невдалий логін ставить у чергу `logins` окрему
Playwright-перевірку облікових даних у Yaware, тому черг дві.

Планувальник (`backend/routes/console.php`) щобудня о 07:00 за Києвом генерує
звіти за попередній робочий день — потрібен системний cron із
`php artisan schedule:run`.

## Можливості

- Звіти по робочому часу з Yaware — на вимогу або за розкладом
- Історія, статистика активностей, Табель (лише для адміністратора)
- AI-аналіз дня — Claude або DeepSeek звіряє активності з посадою й тасками та
  виписує незрозумілі сайти (лише для адміністратора)
- Trello: персональні токени, вибір або створення дошки з інтерфейсу
- Google Sheets: персональна таблиця — створити нову або підключити наявну
- Telegram-бот [@TeamReporter\_Bot](https://t.me/TeamReporter_Bot) — сповіщення
- Ролі: працівник бачить себе, адміністратор — усю команду

## Локальний запуск

Потрібні PHP 8.3+, Composer, Node 22+, Python 3 з `openpyxl`.

```powershell
# backend
cd backend
composer install
copy .env.example .env
php artisan key:generate
php artisan migrate

# воркер
cd ..\app
npm ci
npx playwright install chromium

# frontend
cd ..\frontend
npm ci
```

Заповніть у `backend/.env` секрети інтеграцій (`TRELLO_API_KEY`,
`GOOGLE_OAUTH_CLIENT_ID` / `GOOGLE_OAUTH_CLIENT_SECRET`, `TELEGRAM_BOT_TOKEN`,
`ANTHROPIC_API_KEY` та/або `DEEPSEEK_API_KEY`) — без них відповідні розділи
просто будуть неактивні.

Далі одна команда піднімає все — API, обидва воркери черг і Vite:

```powershell
.\start-dev.ps1
```

API стане на `http://localhost:8000`, SPA — на `http://localhost:5173`
(браузер відкриється сам). Зупинка — закрити відповідні вікна.

Тести бекенду:

```powershell
cd backend
php artisan test
```

## Секрети

У репозиторії **немає** робочих ключів — `.gitignore` виключає `backend/.env`,
`.yaware-credentials.json`, `backend/storage/app/google/oauth-token.json` і
SQLite-бази. Перед клонуванням на нову машину врахуйте:

- `APP_KEY` треба **переносити**, а не генерувати заново: касти `encrypted`
  шифрують `users.trello_token` і `employees.yaware_password` — з новим ключем
  вони не розшифруються.
- Портативні `node/`, `python/`, `browsers/` з Windows-теки в git не потрапляють
  і на сервер не переносяться — там системні пакети та Chromium з кешу Playwright.

## Документація

- [`PRODUCT.md`](PRODUCT.md) — аудиторія, призначення, принципи продукту
- [`DESIGN.md`](DESIGN.md) — візуальна система фронтенду (палітра, типографіка, компоненти)
- [`DEPLOY.md`](DEPLOY.md) — розгортання на Linux VPS: nginx, HTTPS, воркери, cron
