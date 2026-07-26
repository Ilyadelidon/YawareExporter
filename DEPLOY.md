# Деплой на хостинг (Linux VPS)

> **ДЕПЛОЙ ЗАВЕРШЕНО 2026-07-12** — сервіс живе на **https://teamreporter.space**.
> Сервер: HyperHost VPS Старт (OpenVZ, 2 ядра / 3 ГБ), Ubuntu 24.04,
> **185.237.206.98**, SSH тільки за ключем. Код у `/var/www/yaware/`,
> Chromium у `/opt/pw-browsers`, FastPanel-сервіси з образу вимкнено
> (apache2, mysql, fastpanel2*), nginx: `/etc/nginx/sites-enabled/yaware.conf`,
> HTTPS Let's Encrypt з автопродовженням. Домен: nic.ua, NS ns10-12.uadns.com.
> Trello Allowed Origins і Google OAuth redirect URI оновлені на прод-домен.
> Чекліст п.7 пройдено (повний звіт згенеровано на проді).
> Лишився тільки п.8 — Scheduler.

План переносу сервісу з локальної Windows-машини на продакшн. Порядок важливий:
Scheduler (автогенерація звітів) робимо **після** деплою.

## 0. Що купуємо

- **VPS**: Ubuntu 24.04 LTS, мінімум 2 vCPU / **4 ГБ RAM** / 40+ ГБ SSD
  (Chromium для Playwright + PHP-FPM + два queue-воркери; на 2 ГБ буде впритул).
  Панель керування (cPanel/ISPmanager) не потрібна — все ставимо самі через SSH.
- **Домен**: будь-який дешевий; потрібен для HTTPS (Google OAuth callback не
  працює на голому IP, Trello Allowed Origins теж хоче нормальний origin).
  A-запис домену → IP VPS.

## 1. Софт на сервері

```bash
# PHP 8.3 + розширення (sqlite, mbstring, xml, curl, zip, intl, gd)
apt install php8.3-fpm php8.3-sqlite3 php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip php8.3-intl php8.3-gd composer

# nginx + certbot (Let's Encrypt)
apt install nginx certbot python3-certbot-nginx

# Node 22 LTS (NodeSource) — для воркера
# Python 3 + openpyxl — для Excel-постобробки
apt install python3 python3-openpyxl

# Playwright Chromium із системними залежностями (від імені користувача,
# під яким працюють queue-воркери!):
cd /var/www/yaware/app && npx playwright install --with-deps chromium
```

## 2. Структура на сервері

Копіюємо `backend/`, `frontend/` (тільки сирці — `dist` збираємо на місці або
заливаємо готовий), `app/` (worker-скрипт + package.json, `node_modules`
ставимо через `npm ci` на сервері). Портативні `node/`, `python/`, `browsers/`
з Windows-теки **не переносимо** — на сервері системні node/python3 і
Chromium з кешу Playwright.

- Код уже готовий до Linux (зроблено 2026-07-11):
  - `export-yaware-xls.mjs`: python-шлях — `YAWARE_PYTHON_BINARY` або `python3` поза Windows;
  - `WorkerEnvironment::base()` прокидає `HOME`, `XDG_CACHE_HOME`,
    `PLAYWRIGHT_BROWSERS_PATH`, `YAWARE_PYTHON_BINARY`.

## 3. Laravel `.env` (прод)

- Почати з шаблона: `cp .env.example .env`. `backend/.env.example` — канонічний
  список змінних (сам `.env` у git не потрапляє); якщо додали нову змінну в
  `config/`, додайте її і в шаблон, інакше на наступному деплої її пропустять
- `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://<домен>`
- `DB_CONNECTION=sqlite` (файл лишається; бекапити `database/database.sqlite`)
- Перенести секрети: `TRELLO_API_KEY`, `TRELLO_TEMPLATE_BOARD_ID`,
  `GOOGLE_OAUTH_CLIENT_ID`/`GOOGLE_OAUTH_CLIENT_SECRET`,
  `TELEGRAM_BOT_TOKEN`/`TELEGRAM_BOT_USERNAME`/`TELEGRAM_WEBHOOK_SECRET`
  (див. п. 7а) — з локального `.env`
- `YAWARE_NODE_BINARY=node`, `YAWARE_WORKER_SCRIPT`/`YAWARE_WORKER_CWD` —
  дефолти підходять, якщо структура тек збережена (`app/` поруч із `backend/`)
- Скопіювати `backend/storage/app/google/oauth-token.json` (refresh-токен
  адмінського Google-акаунта illadelidon95@gmail.com)
- `php artisan key:generate` НЕ запускати, а перенести локальний `APP_KEY` —
  інакше не розшифруються `users.trello_token`, `employees.yaware_password`
  та кеш перевірок логіну (усі касти 'encrypted')

## 4. nginx

- SPA: віддавати `frontend/dist` як статику, fallback на `index.html`
- API: `location /api` (+ `/google`, `/sanctum`, `/login` веб-роути) →
  PHP-FPM через `backend/public/index.php`
- `client_max_body_size` підняти (звіти-xlsx), таймаут proxy ≥ 60 c
- `certbot --nginx -d <домен>` — HTTPS

## 5. Демони (systemd)

Два queue-воркери — ті самі параметри, що в `start-dev.ps1`:

| unit | команда |
|---|---|
| yaware-queue-logins | `php artisan queue:work --queue=logins --timeout=180 --tries=1 --sleep=1` |
| yaware-queue-default | `php artisan queue:work --queue=default --timeout=700 --tries=1 --sleep=3` |

`Restart=always`, `User=www-data` (той самий користувач, під яким ставили
Chromium). Після деплою нового коду — `systemctl restart` обох.

## 6. Зовнішні сервіси — перемкнути на прод-домен

- **Trello** (https://trello.com/power-ups/admin): додати `https://<домен>`
  в Allowed Origins API-ключа
- **Google Cloud Console**: у OAuth-клієнта додати redirect URI
  `https://<домен>/google/callback`; consent screen уже в Production
- `SANCTUM_STATEFUL_DOMAINS`/`SESSION_DOMAIN`/CORS у Laravel — прод-домен

## 7. Перевірка після деплою (чекліст)

1. Логін у SPA (перевірка Yaware-логіну через чергу `logins` — 202 + polling)
2. Підключення Trello через popup (потребує правильного Allowed Origins)
3. Створення персональної Google-таблиці з UI
4. Повна генерація звіту: Excel → вкладка дня + місячний аркуш у Sheets,
   таски Trello в колонці I
5. Вкладка «Історія»
6. Помилковий логін → людське повідомлення, скріншот у теці звіту

## 7а. Telegram-бот сповіщень (зроблено 2026-07-12)

- Бот @TeamReporter_Bot (створений через @BotFather). У `backend/.env`:
  `TELEGRAM_BOT_TOKEN`, `TELEGRAM_BOT_USERNAME` (без @),
  `TELEGRAM_WEBHOOK_SECRET` (довільний, `openssl rand -hex 32`).
- Після зміни env: `php artisan config:clear` + рестарт queue-воркерів,
  далі разово `php artisan telegram:set-webhook` (реєструє
  `APP_URL/api/telegram/webhook`; при зміні домену повторити).
- Працівники підключаються самі: картка «Telegram» у блоці інтеграцій →
  «Підключити» → Start у боті. Сповіщення: звіт готовий / тасок у Trello
  за день немає / генерація впала.
- Локально бот не налаштовується (вебхук один і дивиться на прод) — блок
  показує «Не налаштовано», сповіщення тихо пропускаються.

## 8. Scheduler (зроблено 2026-07-12)

- `reports:generate-daily` — ставить у чергу звіти за попередній будній день
  (у понеділок — за пʼятницю) для активних працівників з повними інтеграціями
  (креди Yaware + Trello + Google-таблиця); решта пропускається із записом у
  лог. Failed-звіт за цю дату перезапускається, completed — ні.
  Ручний запуск за довільну дату: `php artisan reports:generate-daily --date=Y-m-d`.
- Розклад у `routes/console.php`: будні 07:00 Europe/Kyiv, вивід команди
  пишеться в `storage/logs/scheduler.log`.
- Потрібен cron під `www-data` (той самий користувач, що й queue-воркери,
  інакше SQLite/логи зміняють власника):

  ```
  * * * * * cd /var/www/yaware/backend && php artisan schedule:run >> /dev/null 2>&1
  ```

  Ставиться через `crontab -u www-data -e`. Перевірка: `php artisan schedule:list`
  (має показати `0 4 * * 1-5` — 07:00 Києва в UTC) і разовий
  `sudo -u www-data php artisan reports:generate-daily --date=<вчора>`.
