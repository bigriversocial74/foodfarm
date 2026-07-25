#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${HOMESTEAD_BASE_URL:-http://127.0.0.1:8080}"
OWNER_EMAIL="${HOMESTEAD_OWNER_EMAIL:-owner@example.test}"
OWNER_PASSWORD="${HOMESTEAD_OWNER_PASSWORD:-CI-Only-Owner-Password-2026!}"
HEALTH_KEY="${HOMESTEAD_HEALTH_KEY:-ci-phase7-health-key-2026-very-long}"
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

planning_page="$(curl -fsS -b "$COOKIE_JAR" "${BASE_URL}/phase7.php")"
printf '%s' "$planning_page" | grep -q 'Planning, Tasks &amp; Automation\|Planning, Tasks & Automation'
csrf="$(printf '%s' "$planning_page" | extract_value csrf_token)"
action_key="$(printf '%s' "$planning_page" | extract_value action_key)"
test -n "$csrf"
test -n "$action_key"

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
  "${BASE_URL}/phase7.php" >/dev/null

planning_page="$(curl -fsS -b "$COOKIE_JAR" "${BASE_URL}/phase7.php")"
printf '%s' "$planning_page" | grep -q "$title"
csrf="$(printf '%s' "$planning_page" | extract_value csrf_token)"
action_key="$(printf '%s' "$planning_page" | extract_value action_key)"

task_id="$(mysql --host="${DB_HOST:-127.0.0.1}" --port="${DB_PORT:-3306}" --user="${DB_USER:-root}" --database="${DB_NAME:-homestead}" --batch --skip-column-names \
  --execute="SELECT id FROM household_tasks WHERE title = '$(printf "%s" "$title" | sed "s/'/''/g")' ORDER BY id DESC LIMIT 1")"
test -n "$task_id"

curl -fsS -L -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
  --data-urlencode "csrf_token=${csrf}" \
  --data-urlencode "action_key=${action_key}" \
  --data-urlencode "action=complete_task" \
  --data-urlencode "task_id=${task_id}" \
  --data-urlencode "completion_notes=Completed through authenticated HTTP certification" \
  "${BASE_URL}/phase7.php" >/dev/null

status="$(mysql --host="${DB_HOST:-127.0.0.1}" --port="${DB_PORT:-3306}" --user="${DB_USER:-root}" --database="${DB_NAME:-homestead}" --batch --skip-column-names \
  --execute="SELECT status FROM household_tasks WHERE id = ${task_id}")"
test "$status" = "completed"

health="$(curl -fsS -H "X-Homestead-Health-Key: ${HEALTH_KEY}" "${BASE_URL}/api/phase7-health.php")"
printf '%s' "$health" | grep -q '"ok":true'
printf '%s' "$health" | grep -q '"phase":7'

echo "Phase 7 HTTP smoke suite passed."
