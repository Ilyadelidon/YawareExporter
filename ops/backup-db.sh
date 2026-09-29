#!/usr/bin/env bash
#
# Бекап бази TeamReporter: MySQL (з 2026-09-29) або SQLite — за DB_CONNECTION
# у backend/.env. Гілка SQLite лишається на випадок відкату переїзду.
#
# Запускається cron'ом ПІД www-data — не під root: для SQLite в режимі WAL будь-яке
# відкриття створює/чіпає `database.sqlite-shm`; зроблене від root воно лишить
# файл із власником root, і queue-воркери втратять доступ до бази (та сама
# пастка, що і з artisan — див. DEPLOY.md, розділ 5а). Для MySQL www-data просто
# має читати backend/.env, звідки береться пароль.
#
# Що робить:
#   1. знімає узгоджену копію: MySQL — `mysqldump --single-transaction` (знімок
#      без блокування таблиць на працюючому сервісі); SQLite — `sqlite3 .backup`
#      (просте cp на WAL не є коректним бекапом: свіжі транзакції лежать у -wal);
#   2. перевіряє копію: дамп MySQL має закінчуватись рядком «Dump completed»
#      (обірваний дамп виглядає цілком правдоподібно), SQLite — PRAGMA
#      integrity_check. Бекап, який не відновлюється, гірший за відсутній, бо
#      на нього розраховують;
#   3. стискає і лишає останні BACKUP_KEEP копій;
#   4. якщо задано BACKUP_REMOTE — відвозить копію поза сервер;
#   5. пише позначку для `ops:healthcheck`: без неї бекап, що тихо перестав
#      робитись, помітили б лише тоді, коли він знадобиться.
#
# Налаштування — змінними оточення (значення нижче підходять для прод-структури
# з DEPLOY.md):
#   APP_DIR       корінь backend/           (/var/www/yaware/backend)
#   BACKUP_DIR    куди класти копії         (/var/backups/teamreporter)
#   BACKUP_KEEP   скільки копій тримати     (14)
#   BACKUP_REMOTE ціль rsync поза сервером  (порожнє — копія лишається локальною)
#
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/yaware/backend}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/teamreporter}"
BACKUP_KEEP="${BACKUP_KEEP:-14}"
BACKUP_REMOTE="${BACKUP_REMOTE:-}"

ENV_FILE="$APP_DIR/.env"
MARKER="$APP_DIR/storage/app/ops-backup.json"
STAMP="$(date +%F-%H%M)"

fail() {
    echo "Бекап не виконано: $1" >&2
    exit 1
}

# Значення змінної з .env без лапок. Сам .env не source'имо: це не bash-файл,
# і пароль зі спецсимволами там цілком законний.
env_value() {
    sed -n "s/^$1=//p" "$ENV_FILE" | tail -n 1 | sed -e 's/^"\(.*\)"$/\1/' -e "s/^'\(.*\)'\$/\1/"
}

[ -r "$ENV_FILE" ] || fail "не читається $ENV_FILE."
DB_CONNECTION="$(env_value DB_CONNECTION)"

mkdir -p "$BACKUP_DIR"

case "$DB_CONNECTION" in
mysql)
    command -v mysqldump >/dev/null || fail 'не встановлено mysqldump (apt install mysql-client).'
    TARGET="$BACKUP_DIR/teamreporter-$STAMP.sql"

    # Пароль — через тимчасовий option-файл, а не аргументом: аргументи видно
    # будь-кому в `ps`.
    creds="$(mktemp)"
    trap 'rm -f "$creds"' EXIT
    chmod 600 "$creds"
    cat > "$creds" <<CNF
[client]
host=$(env_value DB_HOST)
port=$(env_value DB_PORT)
user=$(env_value DB_USERNAME)
password=$(env_value DB_PASSWORD)
CNF
    database="$(env_value DB_DATABASE)"

    # --single-transaction: узгоджений знімок InnoDB без блокування таблиць.
    # --no-tablespaces: інакше mysqldump вимагає глобального привілею PROCESS.
    mysqldump --defaults-extra-file="$creds" --single-transaction --no-tablespaces \
        --default-character-set=utf8mb4 "$database" > "$TARGET" \
        || { rm -f "$TARGET"; fail 'mysqldump завершився помилкою.'; }

    if ! tail -n 1 "$TARGET" | grep -q 'Dump completed'; then
        rm -f "$TARGET"
        fail 'дамп обірваний: немає завершального рядка «Dump completed».'
    fi

    # Перевіряємо, що в базі є дані, а не порожня схема: нуль звітів — ознака,
    # що знімали не ту базу.
    reports="$(mysql --defaults-extra-file="$creds" -N -e 'SELECT count(*) FROM reports' "$database" 2>/dev/null || echo '?')"
    ;;
sqlite | '')
    DB_PATH="$APP_DIR/database/database.sqlite"
    TARGET="$BACKUP_DIR/teamreporter-$STAMP.sqlite"

    command -v sqlite3 >/dev/null || fail 'не встановлено sqlite3 (apt install sqlite3).'
    [ -f "$DB_PATH" ] || fail "бази немає за шляхом $DB_PATH."

    # .backup, а не cp: узгоджену копію можна знімати на працюючому сервісі.
    sqlite3 "$DB_PATH" ".backup '$TARGET'" || fail 'sqlite3 .backup завершився помилкою.'

    # Копію відкриваємо й перевіряємо ДО того, як покладатись на неї. Це і є та
    # сама «перевірка відновлення», тільки щоденна й без ручної роботи.
    integrity="$(sqlite3 "$TARGET" 'PRAGMA integrity_check;' 2>&1 || true)"
    if [ "$integrity" != 'ok' ]; then
        rm -f "$TARGET"
        fail "копія не пройшла integrity_check ($integrity)."
    fi

    # Заразом переконуємось, що в копії є дані, а не порожня схема.
    reports="$(sqlite3 "$TARGET" 'SELECT count(*) FROM reports;' 2>/dev/null || echo '?')"
    ;;
*)
    fail "невідомий DB_CONNECTION=$DB_CONNECTION."
    ;;
esac

gzip -f "$TARGET"
TARGET="$TARGET.gz"
bytes="$(stat -c %s "$TARGET")"

# Ротація: лишаємо останні BACKUP_KEEP копій (дампи MySQL і копії SQLite разом).
ls -1t "$BACKUP_DIR"/teamreporter-*.gz 2>/dev/null \
    | tail -n "+$((BACKUP_KEEP + 1))" \
    | xargs -r rm -f

# Копія поза сервером. Локальні копії на тому ж диску рятують від помилкового
# DROP чи невдалої міграції, але не від втрати самого VPS.
remote='false'
if [ -n "$BACKUP_REMOTE" ]; then
    if rsync -e 'ssh -o BatchMode=yes' "$TARGET" "$BACKUP_REMOTE"; then
        remote='true'
    else
        fail 'копію зроблено, але відвезти за BACKUP_REMOTE не вдалось.'
    fi
fi

# Позначка для ops:healthcheck. Пишемо в storage/app поруч з ops-state.json —
# монітор читає її і б'є на сполох, якщо бекап застарів.
cat > "$MARKER" <<JSON
{
    "at": "$(date --iso-8601=seconds)",
    "path": "$TARGET",
    "bytes": $bytes,
    "reports": "$reports",
    "remote": $remote
}
JSON

echo "Бекап готовий: $TARGET ($bytes байт, звітів у копії: $reports, поза сервером: $remote)."
