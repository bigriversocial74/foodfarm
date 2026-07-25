#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${HOMESTEAD_BASE_URL:-http://127.0.0.1:8080}"
OWNER_EMAIL="${HOMESTEAD_OWNER_EMAIL:-owner@example.test}"
OWNER_PASSWORD="${HOMESTEAD_OWNER_PASSWORD:-CI-Only-Owner-Password-2026!}"
HEALTH_KEY="${HOMESTEAD_HEALTH_KEY:-ci-phase8-health-key-2026-very-long}"
COOKIE_JAR="$(mktemp)"
trap 'rm -f "$COOKIE_JAR"' EXIT

fail() {
  echo "[FAIL] $1" >&2
  exit 1
}

pass() {
  echo "[PASS] $1"
}

extract_value() {
  local name="$1"
  local html="$2"
  sed -n -E "/name=\"${name}\" value=\"[^\"]*\"/ { s/.*name=\"${name}\" value=\"([^\"]*)\".*/\1/; p; q; }" <<<"$html"
}

login_page="$(curl -fsS -c "$COOKIE_JAR" "${BASE_URL}/login.php")" || fail "login page unavailable"
login_csrf="$(extract_value csrf_token "$login_page")"
[[ "$login_csrf" =~ ^[a-f0-9]{64}$ ]] || fail "login CSRF token missing"

curl -fsS -L -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
  --data-urlencode "csrf_token=${login_csrf}" \
  --data-urlencode "email=${OWNER_EMAIL}" \
  --data-urlencode "password=${OWNER_PASSWORD}" \
  "${BASE_URL}/login.php" >/dev/null || fail "owner login request failed"
pass "owner login succeeds"

forecast_page="$(curl -fsS -b "$COOKIE_JAR" "${BASE_URL}/phase8.php")" || fail "Phase 8 workspace unavailable"
grep -Eq 'Forecasting, Seasons &amp; Self-Sufficiency|Forecasting, Seasons & Self-Sufficiency' <<<"$forecast_page" || fail "Phase 8 workspace title missing"
csrf="$(extract_value csrf_token "$forecast_page")"
action_key="$(extract_value action_key "$forecast_page")"
[[ "$csrf" =~ ^[a-f0-9]{64}$ ]] || fail "Phase 8 CSRF token missing"
[[ "$action_key" =~ ^[a-f0-9]{64}$ ]] || fail "Phase 8 action key missing"
pass "Phase 8 workspace loads with protected action tokens"

curl -fsS -L -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
  --data-urlencode "csrf_token=${csrf}" \
  --data-urlencode "action_key=${action_key}" \
  --data-urlencode "action=save_settings" \
  --data-urlencode "horizon_days=120" \
  --data-urlencode "history_days=90" \
  --data-urlencode "target_self_sufficiency_percent=40" \
  --data-urlencode "target_buffer_days=30" \
  "${BASE_URL}/phase8.php" >/dev/null || fail "Phase 8 settings update failed"
pass "Phase 8 settings update succeeds"

forecast_page="$(curl -fsS -b "$COOKIE_JAR" "${BASE_URL}/phase8.php")" || fail "Phase 8 workspace unavailable after settings update"
csrf="$(extract_value csrf_token "$forecast_page")"
action_key="$(extract_value action_key "$forecast_page")"
[[ "$csrf" =~ ^[a-f0-9]{64}$ ]] || fail "refreshed Phase 8 CSRF token missing"
[[ "$action_key" =~ ^[a-f0-9]{64}$ ]] || fail "refreshed Phase 8 action key missing"
today="$(date -u '+%Y-%m-%d')"

curl -fsS -L -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
  --data-urlencode "csrf_token=${csrf}" \
  --data-urlencode "action_key=${action_key}" \
  --data-urlencode "action=run_forecast" \
  --data-urlencode "as_of_date=${today}" \
  "${BASE_URL}/phase8.php" >/dev/null || fail "Phase 8 forecast request failed"

snapshot_count="$(mysql --host="${DB_HOST:-127.0.0.1}" --port="${DB_PORT:-3306}" --user="${DB_USER:-root}" --database="${DB_NAME:-homestead}" --batch --skip-column-names \
  --execute="SELECT COUNT(*) FROM forecast_snapshots WHERE as_of_date = '${today}' AND status = 'completed'")"
[[ "$snapshot_count" =~ ^[0-9]+$ ]] && (( snapshot_count >= 1 )) || fail "Phase 8 forecast snapshot was not completed"
pass "Phase 8 forecast snapshot persists"

forecast_page="$(curl -fsS -b "$COOKIE_JAR" "${BASE_URL}/phase8.php")" || fail "Phase 8 workspace unavailable after forecast"
grep -q 'Item projections' <<<"$forecast_page" || fail "Phase 8 item projections missing"
grep -q 'Forecast recommendations' <<<"$forecast_page" || fail "Phase 8 recommendations missing"
grep -q 'Snapshot trend' <<<"$forecast_page" || fail "Phase 8 trend view missing"
pass "Phase 8 forecast results render"

health="$(curl -fsS -H "X-Homestead-Health-Key: ${HEALTH_KEY}" "${BASE_URL}/api/phase8-health.php")" || fail "Phase 8 health endpoint request failed"
grep -Eq '"ok"[[:space:]]*:[[:space:]]*true' <<<"$health" || { printf '%s\n' "$health" >&2; fail "Phase 8 health endpoint did not report ok"; }
grep -Eq '"phase"[[:space:]]*:[[:space:]]*8' <<<"$health" || fail "Phase 8 health response omitted phase identifier"
pass "Phase 8 health endpoint passes"

echo "Phase 8 HTTP smoke suite passed."
