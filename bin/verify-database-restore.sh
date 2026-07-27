#!/usr/bin/env bash
set -Eeuo pipefail
IFS=$'\n\t'
umask 077

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
CONFIG_PATH="${HOMESTEAD_CONFIG:-${ROOT_DIR}/config.php}"
BACKUP_FILE=""
TARGET_DATABASE=""
KEEP_DATABASE=0
CONFIRMED=0

usage() {
  cat <<'EOF'
Usage: bin/verify-database-restore.sh --backup=/path/file.sql.gz \
  --target-database=homestead_restore_test --confirm-disposable \
  [--config=/path/config.php] [--keep-database]

Drops and recreates only a disposable database whose name begins with
"homestead_restore_", imports the backup, checks representative tables, and
removes the disposable database unless --keep-database is supplied.
EOF
}

for argument in "$@"; do
  case "$argument" in
    --config=*) CONFIG_PATH="${argument#*=}" ;;
    --backup=*) BACKUP_FILE="${argument#*=}" ;;
    --target-database=*) TARGET_DATABASE="${argument#*=}" ;;
    --confirm-disposable) CONFIRMED=1 ;;
    --keep-database) KEEP_DATABASE=1 ;;
    --help|-h) usage; exit 0 ;;
    *) echo "Unknown argument: $argument" >&2; usage >&2; exit 2 ;;
  esac
done

[[ -f "$CONFIG_PATH" && -r "$CONFIG_PATH" ]] || { echo "Configuration file is missing or unreadable." >&2; exit 1; }
[[ -f "$BACKUP_FILE" && -r "$BACKUP_FILE" ]] || { echo "Backup file is missing or unreadable." >&2; exit 1; }
[[ "$TARGET_DATABASE" =~ ^homestead_restore_[A-Za-z0-9_\$-]{1,42}$ ]] || { echo "Target database must begin with homestead_restore_." >&2; exit 2; }
[[ $CONFIRMED -eq 1 ]] || { echo "--confirm-disposable is required because the target database will be dropped." >&2; exit 2; }

for command in php gzip sha256sum mktemp mysql; do
  command -v "$command" >/dev/null 2>&1 || { echo "Required command not found: $command" >&2; exit 1; }
done

gzip -t "$BACKUP_FILE"
if [[ -f "${BACKUP_FILE}.sha256" ]]; then
  (cd "$(dirname "$BACKUP_FILE")" && sha256sum -c "$(basename "${BACKUP_FILE}.sha256")")
fi

mapfile -d '' -t DB_CONFIG < <(php -r '
$c = require $argv[1];
if (!is_array($c) || !is_array($c["database"] ?? null)) { fwrite(STDERR, "Invalid configuration.\n"); exit(1); }
$d = $c["database"];
$values = [
  (string)($d["host"] ?? "127.0.0.1"),
  (string)($d["port"] ?? "3306"),
  (string)($d["name"] ?? ""),
  (string)($d["user"] ?? ""),
  (string)($d["password"] ?? ""),
  (string)($d["charset"] ?? "utf8mb4"),
];
foreach ($values as $value) { fwrite(STDOUT, $value . "\0"); }
' "$CONFIG_PATH")

[[ ${#DB_CONFIG[@]} -eq 6 ]] || { echo "Could not read database configuration." >&2; exit 1; }
DB_HOST="${DB_CONFIG[0]}"
DB_PORT="${DB_CONFIG[1]}"
SOURCE_DATABASE="${DB_CONFIG[2]}"
DB_USER="${DB_CONFIG[3]}"
DB_PASSWORD="${DB_CONFIG[4]}"
DB_CHARSET="${DB_CONFIG[5]}"

[[ "$TARGET_DATABASE" != "$SOURCE_DATABASE" ]] || { echo "Refusing to overwrite the configured Homestead database." >&2; exit 1; }
[[ -n "$DB_USER" && -n "$DB_PASSWORD" ]] || { echo "Explicit database credentials are required." >&2; exit 1; }

DEFAULTS_FILE="$(mktemp)"
cleanup() {
  if [[ $KEEP_DATABASE -eq 0 && -f "$DEFAULTS_FILE" ]]; then
    mysql --defaults-extra-file="$DEFAULTS_FILE" -e "DROP DATABASE IF EXISTS \`$TARGET_DATABASE\`;" >/dev/null 2>&1 || true
  fi
  rm -f "$DEFAULTS_FILE"
}
trap cleanup EXIT HUP INT TERM
chmod 600 "$DEFAULTS_FILE"
cat > "$DEFAULTS_FILE" <<EOF
[client]
host=$DB_HOST
port=$DB_PORT
user=$DB_USER
password=$DB_PASSWORD
default-character-set=$DB_CHARSET
EOF

DB_COLLATION="utf8mb4_unicode_ci"
if [[ "$DB_CHARSET" == "utf8" ]]; then
  DB_COLLATION="utf8_unicode_ci"
fi
mysql --defaults-extra-file="$DEFAULTS_FILE" -e "DROP DATABASE IF EXISTS \`$TARGET_DATABASE\`; CREATE DATABASE \`$TARGET_DATABASE\` CHARACTER SET $DB_CHARSET COLLATE $DB_COLLATION;"
gzip -dc "$BACKUP_FILE" | mysql --defaults-extra-file="$DEFAULTS_FILE" "$TARGET_DATABASE"

REQUIRED_TABLES=(households users household_members inventory_items food_ledger_events recipes garden_zones authentication_events household_tasks household_notifications)
for table in "${REQUIRED_TABLES[@]}"; do
  count="$(mysql --defaults-extra-file="$DEFAULTS_FILE" --batch --skip-column-names -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$TARGET_DATABASE' AND table_name='$table';")"
  [[ "$count" == "1" ]] || { echo "Restore verification failed: missing table $table" >&2; exit 1; }
done

mysql --defaults-extra-file="$DEFAULTS_FILE" --batch --skip-column-names "$TARGET_DATABASE" -e \
  "SELECT CONCAT('households=', COUNT(*)) FROM households; SELECT CONCAT('users=', COUNT(*)) FROM users; SELECT CONCAT('ledger_events=', COUNT(*)) FROM food_ledger_events;"

if [[ $KEEP_DATABASE -eq 1 ]]; then
  echo "Restore verified. Disposable database retained: $TARGET_DATABASE"
else
  echo "Restore verified. Disposable database will be removed."
fi
