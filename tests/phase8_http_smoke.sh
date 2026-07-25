#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${HOMESTEAD_BASE_URL:-http://127.0.0.1:8080}"
OWNER_EMAIL="${HOMESTEAD_OWNER_EMAIL:-owner@example.test}"
OWNER_PASSWORD="${HOMESTEAD_OWNER_PASSWORD:-CI-Only-Owner-Password-2026!}"
HEALTH_KEY="${HOMESTEAD_HEALTH_KEY:-ci-phase8-health-key-2026-very-long}"
COOKIE_JAR="$(mktemp)"
trap 'rm -f "$COOKIE_JAR"' EXIT

extract_value() {
  local name="$1"
  grep -o "name=\"${name}\" value=\"[^\"]*\"" | head -n1 | sed -E 's/.*value="([^"]*)"/\1/'
}

login_page="$(curl -fsS -c "$COOKIE_JAR" "${BASE_URL}/login.php")"
login_csrf="$(printf '%s' "$login_page" | extract_value csrf_token)"
test -n "$login_csrf"

curl -fsS -L -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
  --data-urlencode "csrf_token=${login_csrf}" \
  --data-urlencode "email=${OWNER_EMAIL}" \
  --data-urlencode "password=${OWNER_PASSWORD}" \
  "${BASE_URL}/login.php" >/dev/null

forecast_page="$(curl -fsS -b "$COOKIE_JAR" "${BASE_URL}/phase8.php")"
printf '%s' "$forecast_page" | grep -q 'Forecasting, Seasons &amp; Self-Sufficiency\|Forecasting, Seasons & Self-Sufficiency'
csrf="$(printf '%s' "$forecast_page" | extract_value csrf_token)"
action_key="$(printf '%s' "$forecast_page" | extract_value action_key)"
test -n "$csrf"
test -n "$action_key"

curl -fsS -L -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
  --data-urlencode "csrf_token=${csrf}" \
  --data-urlencode "action_key=${action_key}" \
  --data-urlencode "action=save_settings" \
  --data-urlencode "horizon_days=120" \
  --data-urlencode "history_days=90" \
  --data-urlencode "target_self_sufficiency_percent=40" \
  --data-urlencode "target_buffer_days=30" \
  "${BASE_URL}/phase8.php" >/dev/null

forecast_page="$(curl -fsS -b "$COOKIE_JAR" "${BASE_URL}/phase8.php")"
csrf="$(printf '%s' "$forecast_page" | extract_value csrf_token)"
action_key="$(printf '%s' "$forecast_page" | extract_value action_key)"
today="$(date -u '+%Y-%m-%d')"

curl -fsS -L -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
  --data-urlencode "csrf_token=${csrf}" \
  --data-urlencode "action_key=${action_key}" \
  --data-urlencode "action=run_forecast" \
  --data-urlencode "as_of_date=${today}" \
  "${BASE_URL}/phase8.php" >/dev/null

snapshot_count="$(mysql --host="${DB_HOST:-127.0.0.1}" --port="${DB_PORT:-3306}" --user="${DB_USER:-root}" --database="${DB_NAME:-homestead}" --batch --skip-column-names \
  --execute="SELECT COUNT(*) FROM forecast_snapshots WHERE as_of_date = '${today}' AND status = 'completed'")"
test "$snapshot_count" -ge 1

forecast_page="$(curl -fsS -b "$COOKIE_JAR" "${BASE_URL}/phase8.php")"
printf '%s' "$forecast_page" | grep -q 'Item projections'
printf '%s' "$forecast_page" | grep -q 'Forecast recommendations'
printf '%s' "$forecast_page" | grep -q 'Snapshot trend'

health="$(curl -fsS -H "X-Homestead-Health-Key: ${HEALTH_KEY}" "${BASE_URL}/api/phase8-health.php")"
printf '%s' "$health" | grep -q '"ok":true'
printf '%s' "$health" | grep -q '"phase":8'

echo "Phase 8 HTTP smoke suite passed."
