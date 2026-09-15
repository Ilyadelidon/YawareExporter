#!/usr/bin/env bash
#
# Бекап бази TeamReporter (SQLite у режимі WAL).
#
# Запускається cron'ом ПІД www-data — не під root. База в режимі WAL, і будь-яке
# відкриття створює/чіпає `database.sqlite-shm`; зроблене від root воно лишить
# файл із власником root, і queue-воркери втратять доступ до бази (та сама
# пастка, що і з artisan — див. DEPLOY.md, розділ 5а).
#
# Що робить:
#   1. знімає узгоджену копію через `sqlite3 .backup` (просте cp на WAL не є
#      коректним бекапом: свіжі транзакції лежать у -wal);
#   2. перевіряє копію `PRAGMA integrity_check` — бекап, який не відкривається,
#      гірший за відсутній, бо на нього розраховують;
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

DB_PATH="$APP_DIR/database/database.sqlite"
MARKER="$APP_DIR/storage/app/ops-backup.json"
STAMP="$(date +%F-%H%M)"
TARGET="$BACKUP_DIR/teamreporter-$STAMP.sqlite"

fail() {
    echo "Бекап не виконано: $1" >&2
    exit 1
}

command -v sqlite3 >/dev/null || fail 'не встановлено sqlite3 (apt install sqlite3).'
[ -f "$DB_PATH" ] || fail "бази немає за шляхом $DB_PATH."

mkdir -p "$BACKUP_DIR"

# .backup, а не cp: узгоджену копію можна знімати на працюючому сервісі.
sqlite3 "$DB_PATH" ".backup '$TARGET'" || fail 'sqlite3 .backup завершився помилкою.'

# Копію відкриваємо й перевіряємо ДО того, як покладатись на неї. Це і є та
# сама «перевірка відновлення», тільки щоденна й без ручної роботи.
integrity="$(sqlite3 "$TARGET" 'PRAGMA integrity_check;' 2>&1 || true)"
if [ "$integrity" != 'ok' ]; then
    rm -f "$TARGET"
    fail "копія не пройшла integrity_check ($integrity)."
fi

# Заразом переконуємось, що в копії є дані, а не порожня схема: база з нульовою
# кількістю звітів — ознака, що знімали не ту базу.
reports="$(sqlite3 "$TARGET" 'SELECT count(*) FROM reports;' 2>/dev/null || echo '?')"

gzip -f "$TARGET"
TARGET="$TARGET.gz"
bytes="$(stat -c %s "$TARGET")"

# Ротація: лишаємо останні BACKUP_KEEP копій.
ls -1t "$BACKUP_DIR"/teamreporter-*.sqlite.gz 2>/dev/null \
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
