#!/usr/bin/env bash
set -Eeuo pipefail
IFS=$'\n\t'
umask 077

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
CONFIG_PATH="${HOMESTEAD_CONFIG:-${ROOT_DIR}/config.php}"
OUTPUT_DIR="${HOMESTEAD_BACKUP_DIR:-}"

usage() {
  cat <<'EOF'
Usage: bin/database-backup.sh --output-dir=/secure/path [--config=/path/config.php]

Creates a compressed, checksummed logical database backup outside the web root.
The backup directory is required and must not be inside the Homestead repository.
EOF
}

for argument in "$@"; do
  case "$argument" in
    --config=*) CONFIG_PATH="${argument#*=}" ;;
    --output-dir=*) OUTPUT_DIR="${argument#*=}" ;;
    --help|-h) usage; exit 0 ;;
    *) echo "Unknown argument: $argument" >&2; usage >&2; exit 2 ;;
  esac
done

[[ -f "$CONFIG_PATH" && -r "$CONFIG_PATH" ]] || { echo "Configuration file is missing or unreadable." >&2; exit 1; }
[[ -n "$OUTPUT_DIR" ]] || { echo "A backup directory is required." >&2; usage >&2; exit 2; }

for command in php gzip sha256sum mktemp; do
  command -v "$command" >/dev/null 2>&1 || { echo "Required command not found: $command" >&2; exit 1; }
done

DUMP_BIN=""
if command -v mariadb-dump >/dev/null 2>&1; then
  DUMP_BIN="$(command -v mariadb-dump)"
elif command -v mysqldump >/dev/null 2>&1; then
  DUMP_BIN="$(command -v mysqldump)"
else
  echo "Neither mariadb-dump nor mysqldump is installed." >&2
  exit 1
fi

mkdir -p "$OUTPUT_DIR"
OUTPUT_DIR="$(cd "$OUTPUT_DIR" && pwd -P)"
case "$OUTPUT_DIR/" in
  "$ROOT_DIR"/*|"$ROOT_DIR"/) echo "Backup directory must be outside the Homestead repository/web root." >&2; exit 1 ;;
esac

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
DB_NAME="${DB_CONFIG[2]}"
DB_USER="${DB_CONFIG[3]}"
DB_PASSWORD="${DB_CONFIG[4]}"
DB_CHARSET="${DB_CONFIG[5]}"

[[ "$DB_NAME" =~ ^[A-Za-z0-9_\$-]{1,64}$ ]] || { echo "Database name is invalid." >&2; exit 1; }
[[ -n "$DB_USER" && -n "$DB_PASSWORD" ]] || { echo "Explicit database credentials are required." >&2; exit 1; }
[[ "$DB_PORT" =~ ^[0-9]+$ ]] || { echo "Database port is invalid." >&2; exit 1; }

DEFAULTS_FILE="$(mktemp)"
PARTIAL_FILE=""
cleanup() {
  rm -f "$DEFAULTS_FILE"
  [[ -z "$PARTIAL_FILE" ]] || rm -f "$PARTIAL_FILE"
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

TIMESTAMP="$(date -u +%Y%m%dT%H%M%SZ)"
BACKUP_FILE="${OUTPUT_DIR}/homestead-${DB_NAME}-${TIMESTAMP}.sql.gz"
PARTIAL_FILE="${BACKUP_FILE}.partial"
CHECKSUM_FILE="${BACKUP_FILE}.sha256"

DUMP_ARGS=(
  "--defaults-extra-file=$DEFAULTS_FILE"
  --single-transaction
  --quick
  --routines
  --triggers
  --events
  --hex-blob
  --default-character-set="$DB_CHARSET"
)
if "$DUMP_BIN" --help 2>/dev/null | grep -q -- '--set-gtid-purged'; then
  DUMP_ARGS+=(--set-gtid-purged=OFF)
fi
if "$DUMP_BIN" --help 2>/dev/null | grep -q -- '--no-tablespaces'; then
  DUMP_ARGS+=(--no-tablespaces)
fi

"$DUMP_BIN" "${DUMP_ARGS[@]}" "$DB_NAME" | gzip -9 > "$PARTIAL_FILE"
gzip -t "$PARTIAL_FILE"
[[ -s "$PARTIAL_FILE" ]] || { echo "Backup output is empty." >&2; exit 1; }
mv "$PARTIAL_FILE" "$BACKUP_FILE"
PARTIAL_FILE=""
(
  cd "$OUTPUT_DIR"
  sha256sum "$(basename "$BACKUP_FILE")" > "$(basename "$CHECKSUM_FILE")"
)
chmod 600 "$BACKUP_FILE" "$CHECKSUM_FILE"

printf 'Backup created: %s\nChecksum: %s\n' "$BACKUP_FILE" "$CHECKSUM_FILE"
