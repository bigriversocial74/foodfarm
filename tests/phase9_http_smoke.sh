#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${HOMESTEAD_BASE_URL:-http://127.0.0.1:8080}"
OWNER_EMAIL="${HOMESTEAD_OWNER_EMAIL:-owner@example.test}"
OWNER_PASSWORD="${HOMESTEAD_OWNER_PASSWORD:-CI-Only-Owner-Password-2026!}"
HEALTH_KEY="${HOMESTEAD_HEALTH_KEY:-ci-phase9-health-key-2026-very-long}"
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

finance_page="$(curl -fsS -b "$COOKIE_JAR" "${BASE_URL}/phase9.php")" || fail "Phase 9 workspace unavailable"
grep -Eq 'Cost, Waste &amp; Savings Intelligence|Cost, Waste & Savings Intelligence' <<<"$finance_page" || fail "Phase 9 workspace title missing"
csrf="$(extract_value csrf_token "$finance_page")"
action_key="$(extract_value action_key "$finance_page")"
[[ "$csrf" =~ ^[a-f0-9]{64}$ ]] || fail "Phase 9 CSRF token missing"
[[ "$action_key" =~ ^[a-f0-9]{64}$ ]] || fail "Phase 9 action key missing"
pass "Phase 9 workspace loads with protected action tokens"

curl -fsS -L -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
  --data-urlencode "csrf_token=${csrf}" \
  --data-urlencode "action_key=${action_key}" \
  --data-urlencode "action=save_settings" \
  --data-urlencode "monthly_budget=650" \
  --data-urlencode "waste_target_percent=4" \
  --data-urlencode "savings_target_amount=125" \
  --data-urlencode "price_increase_alert_percent=15" \
  "${BASE_URL}/phase9.php" >/dev/null || fail "Phase 9 settings update failed"
pass "Phase 9 settings update succeeds"

finance_page="$(curl -fsS -b "$COOKIE_JAR" "${BASE_URL}/phase9.php")" || fail "Phase 9 workspace unavailable after settings update"
csrf="$(extract_value csrf_token "$finance_page")"
action_key="$(extract_value action_key "$finance_page")"
[[ "$csrf" =~ ^[a-f0-9]{64}$ ]] || fail "refreshed Phase 9 CSRF token missing"
[[ "$action_key" =~ ^[a-f0-9]{64}$ ]] || fail "refreshed Phase 9 action key missing"
month="$(date -u '+%Y-%m')"
month_start="${month}-01"

curl -fsS -L -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
  --data-urlencode "csrf_token=${csrf}" \
  --data-urlencode "action_key=${action_key}" \
  --data-urlencode "action=run_finance_snapshot" \
  --data-urlencode "month=${month}" \
  "${BASE_URL}/phase9.php" >/dev/null || fail "Phase 9 monthly snapshot request failed"

snapshot_count="$(mysql --host="${DB_HOST:-127.0.0.1}" --port="${DB_PORT:-3306}" --user="${DB_USER:-root}" --database="${DB_NAME:-homestead}" --batch --skip-column-names \
  --execute="SELECT COUNT(*) FROM household_finance_snapshots WHERE month_start = '${month_start}' AND status = 'completed'")"
[[ "$snapshot_count" =~ ^[0-9]+$ ]] && (( snapshot_count >= 1 )) || fail "Phase 9 monthly snapshot was not completed"
pass "Phase 9 monthly snapshot persists"

finance_page="$(curl -fsS -b "$COOKIE_JAR" "${BASE_URL}/phase9.php")" || fail "Phase 9 workspace unavailable after snapshot"
grep -q 'Cost and waste recommendations' <<<"$finance_page" || fail "Phase 9 recommendations missing"
grep -q 'Recent purchases' <<<"$finance_page" || fail "Phase 9 purchase history missing"
grep -q 'Supplier and package-cost comparison' <<<"$finance_page" || fail "Phase 9 supplier comparison missing"
grep -q 'Monthly trend history' <<<"$finance_page" || fail "Phase 9 trend history missing"
pass "Phase 9 financial results render"

health="$(curl -fsS -H "X-Homestead-Health-Key: ${HEALTH_KEY}" "${BASE_URL}/api/phase9-health.php")" || fail "Phase 9 health endpoint request failed"
grep -Eq '"ok"[[:space:]]*:[[:space:]]*true' <<<"$health" || { printf '%s\n' "$health" >&2; fail "Phase 9 health endpoint did not report ok"; }
grep -Eq '"phase"[[:space:]]*:[[:space:]]*9' <<<"$health" || fail "Phase 9 health response omitted phase identifier"
pass "Phase 9 health endpoint passes"

echo "Phase 9 HTTP smoke suite passed."