#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${HOMESTEAD_BASE_URL:-http://127.0.0.1:8080}"
OWNER_EMAIL="${HOMESTEAD_OWNER_EMAIL:-owner@example.test}"
OWNER_PASSWORD="${HOMESTEAD_OWNER_PASSWORD:-CI-Only-Owner-Password-2026!}"
HEALTH_KEY="${HOMESTEAD_HEALTH_KEY:-ci-phase11-health-key-2026-very-long}"
COOKIE_JAR="$(mktemp)"
trap 'rm -f "$COOKIE_JAR"' EXIT

fail() { echo "[FAIL] $1" >&2; exit 1; }
pass() { echo "[PASS] $1"; }
extract_value() {
  local name="$1" html="$2"
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

page="$(curl -fsS -b "$COOKIE_JAR" "${BASE_URL}/phase11.php")" || fail "Phase 11 workspace unavailable"
grep -q 'Alerts, Notifications &amp; Shared Calendar' <<<"$page" || fail "Phase 11 title missing"
grep -q 'In-app first, adapter-ready outside the app' <<<"$page" || fail "Phase 11 delivery boundary missing"
csrf="$(extract_value csrf_token "$page")"
action_key="$(extract_value action_key "$page")"
[[ "$csrf" =~ ^[a-f0-9]{64}$ ]] || fail "Phase 11 CSRF token missing"
[[ "$action_key" =~ ^[a-f0-9]{64}$ ]] || fail "Phase 11 action key missing"
pass "Phase 11 workspace loads with protected action tokens"

today="$(date -u '+%Y-%m-%d')"
curl -fsS -L -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
  --data-urlencode "csrf_token=${csrf}" \
  --data-urlencode "action_key=${action_key}" \
  --data-urlencode "action=run_sync" \
  --data-urlencode "as_of_date=${today}" \
  "${BASE_URL}/phase11.php" >/dev/null || fail "Phase 11 sync request failed"

notification_count="$(mysql --host="${DB_HOST:-127.0.0.1}" --port="${DB_PORT:-3306}" --user="${DB_USER:-root}" --database="${DB_NAME:-homestead}" --batch --skip-column-names --execute='SELECT COUNT(*) FROM household_notifications')"
[[ "$notification_count" =~ ^[0-9]+$ ]] && (( notification_count >= 1 )) || fail "Phase 11 notifications were not generated"
pass "Phase 11 sync generates notifications"

page="$(curl -fsS -b "$COOKIE_JAR" "${BASE_URL}/phase11.php")" || fail "Phase 11 workspace unavailable after sync"
grep -q 'Household notifications' <<<"$page" || fail "Phase 11 inbox missing"
grep -q 'Upcoming household events' <<<"$page" || fail "Phase 11 calendar missing"
csrf="$(extract_value csrf_token "$page")"
action_key="$(extract_value action_key "$page")"
[[ "$csrf" =~ ^[a-f0-9]{64}$ ]] || fail "refreshed Phase 11 CSRF token missing"
[[ "$action_key" =~ ^[a-f0-9]{64}$ ]] || fail "refreshed Phase 11 action key missing"

curl -fsS -L -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
  --data-urlencode "csrf_token=${csrf}" \
  --data-urlencode "action_key=${action_key}" \
  --data-urlencode "action=generate_digest" \
  --data-urlencode "cadence=daily" \
  --data-urlencode "as_of_date=${today}" \
  "${BASE_URL}/phase11.php" >/dev/null || fail "Phase 11 digest request failed"

digest_count="$(mysql --host="${DB_HOST:-127.0.0.1}" --port="${DB_PORT:-3306}" --user="${DB_USER:-root}" --database="${DB_NAME:-homestead}" --batch --skip-column-names --execute='SELECT COUNT(*) FROM notification_digest_runs')"
[[ "$digest_count" =~ ^[0-9]+$ ]] && (( digest_count >= 1 )) || fail "Phase 11 digest was not generated"
pass "Phase 11 digest persists"

calendar="$(curl -fsS -b "$COOKIE_JAR" "${BASE_URL}/phase11-calendar.php")" || fail "Phase 11 ICS export unavailable"
grep -q 'BEGIN:VCALENDAR' <<<"$calendar" || fail "Phase 11 ICS header missing"
grep -q 'BEGIN:VEVENT' <<<"$calendar" || fail "Phase 11 ICS events missing"
pass "Phase 11 shared calendar exports ICS"

health="$(curl -fsS -H "X-Homestead-Health-Key: ${HEALTH_KEY}" "${BASE_URL}/api/phase11-health.php")" || fail "Phase 11 health endpoint request failed"
grep -Eq '"ok"[[:space:]]*:[[:space:]]*true' <<<"$health" || { printf '%s\n' "$health" >&2; fail "Phase 11 health endpoint did not report ok"; }
grep -Eq '"phase"[[:space:]]*:[[:space:]]*11' <<<"$health" || fail "Phase 11 health response omitted phase identifier"
pass "Phase 11 health endpoint passes"

echo "Phase 11 HTTP smoke suite passed."
