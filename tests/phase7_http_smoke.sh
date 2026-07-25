#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${HOMESTEAD_BASE_URL:-http://127.0.0.1:8080}"
OWNER_EMAIL="${HOMESTEAD_OWNER_EMAIL:-owner@example.test}"
OWNER_PASSWORD="${HOMESTEAD_OWNER_PASSWORD:-CI-Only-Owner-Password-2026!}"
HEALTH_KEY="${HOMESTEAD_HEALTH_KEY:-ci-phase7-health-key-2026-very-long}"
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

login_result="$(curl -fsS -L -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
  --data-urlencode "csrf_token=${login_csrf}" \
  --data-urlencode "email=${OWNER_EMAIL}" \
  --data-urlencode "password=${OWNER_PASSWORD}" \
  "${BASE_URL}/login.php")" || fail "owner login request failed"
grep -Eq 'Household Access|Household Dashboard|Welcome' <<<"$login_result" || fail "owner login did not reach an authenticated page"
pass "owner login succeeds"

planning_page="$(curl -fsS -b "$COOKIE_JAR" "${BASE_URL}/phase7.php")" || fail "Phase 7 workspace unavailable"
grep -Eq 'Planning, Tasks &amp; Automation|Planning, Tasks & Automation' <<<"$planning_page" || fail "Phase 7 workspace title missing"
csrf="$(extract_value csrf_token "$planning_page")"
action_key="$(extract_value action_key "$planning_page")"
[[ "$csrf" =~ ^[a-f0-9]{64}$ ]] || fail "Phase 7 CSRF token missing"
[[ "$action_key" =~ ^[a-f0-9]{64}$ ]] || fail "Phase 7 action key missing"
pass "Phase 7 workspace loads with protected action tokens"

title="HTTP Phase 7 task $(date +%s)-${RANDOM}"
due_at="$(date -u -d '+1 day' '+%Y-%m-%dT%H:%M')"
curl -fsS -L -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
  --data-urlencode "csrf_token=${csrf}" \
  --data-urlencode "action_key=${action_key}" \
  --data-urlencode "action=create_task" \
  --data-urlencode "title=${title}" \
  --data-urlencode "description=Authenticated Phase 7 HTTP workflow" \
  --data-urlencode "due_at=${due_at}" \
  --data-urlencode "priority=high" \
  --data-urlencode "estimated_minutes=20" \
  "${BASE_URL}/phase7.php" >/dev/null || fail "Phase 7 task creation request failed"

planning_page="$(curl -fsS -b "$COOKIE_JAR" "${BASE_URL}/phase7.php")" || fail "Phase 7 workspace unavailable after task creation"
grep -Fq "$title" <<<"$planning_page" || fail "created Phase 7 task was not visible"
csrf="$(extract_value csrf_token "$planning_page")"
action_key="$(extract_value action_key "$planning_page")"
[[ "$csrf" =~ ^[a-f0-9]{64}$ ]] || fail "refreshed Phase 7 CSRF token missing"
[[ "$action_key" =~ ^[a-f0-9]{64}$ ]] || fail "refreshed Phase 7 action key missing"

task_id="$(mysql --host="${DB_HOST:-127.0.0.1}" --port="${DB_PORT:-3306}" --user="${DB_USER:-root}" --database="${DB_NAME:-homestead}" --batch --skip-column-names \
  --execute="SELECT id FROM household_tasks WHERE title = '$(printf "%s" "$title" | sed "s/'/''/g")' ORDER BY id DESC LIMIT 1")"
[[ "$task_id" =~ ^[0-9]+$ ]] || fail "created Phase 7 task was not persisted"
pass "Phase 7 task creation persists"

curl -fsS -L -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
  --data-urlencode "csrf_token=${csrf}" \
  --data-urlencode "action_key=${action_key}" \
  --data-urlencode "action=complete_task" \
  --data-urlencode "task_id=${task_id}" \
  --data-urlencode "completion_notes=Completed through authenticated HTTP certification" \
  "${BASE_URL}/phase7.php" >/dev/null || fail "Phase 7 task completion request failed"

status="$(mysql --host="${DB_HOST:-127.0.0.1}" --port="${DB_PORT:-3306}" --user="${DB_USER:-root}" --database="${DB_NAME:-homestead}" --batch --skip-column-names \
  --execute="SELECT status FROM household_tasks WHERE id = ${task_id}")"
[[ "$status" == "completed" ]] || fail "Phase 7 task status is '${status}', expected completed"
pass "Phase 7 task completion persists"

health="$(curl -fsS -H "X-Homestead-Health-Key: ${HEALTH_KEY}" "${BASE_URL}/api/phase7-health.php")" || fail "Phase 7 health endpoint request failed"
grep -Eq '"ok"[[:space:]]*:[[:space:]]*true' <<<"$health" || { printf '%s\n' "$health" >&2; fail "Phase 7 health endpoint did not report ok"; }
grep -Eq '"phase"[[:space:]]*:[[:space:]]*7' <<<"$health" || fail "Phase 7 health response omitted phase identifier"
pass "Phase 7 health endpoint passes"

echo "Phase 7 HTTP smoke suite passed."
