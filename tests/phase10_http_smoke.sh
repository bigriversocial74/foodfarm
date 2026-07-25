#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${HOMESTEAD_BASE_URL:-http://127.0.0.1:8080}"
OWNER_EMAIL="${HOMESTEAD_OWNER_EMAIL:-owner@example.test}"
OWNER_PASSWORD="${HOMESTEAD_OWNER_PASSWORD:-CI-Only-Owner-Password-2026!}"
HEALTH_KEY="${HOMESTEAD_HEALTH_KEY:-ci-phase10-health-key-2026-very-long}"
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

nutrition_page="$(curl -fsS -b "$COOKIE_JAR" "${BASE_URL}/phase10.php")" || fail "Phase 10 workspace unavailable"
grep -Eq 'Nutrition, Dietary Planning (&amp;|&) Wellness' <<<"$nutrition_page" || fail "Phase 10 workspace title missing"
grep -q 'Planning support, not clinical guidance' <<<"$nutrition_page" || fail "Phase 10 planning safety boundary missing"
csrf="$(extract_value csrf_token "$nutrition_page")"
action_key="$(extract_value action_key "$nutrition_page")"
[[ "$csrf" =~ ^[a-f0-9]{64}$ ]] || fail "Phase 10 CSRF token missing"
[[ "$action_key" =~ ^[a-f0-9]{64}$ ]] || fail "Phase 10 action key missing"
pass "Phase 10 workspace loads with protected action tokens"

curl -fsS -L -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
  --data-urlencode "csrf_token=${csrf}" \
  --data-urlencode "action_key=${action_key}" \
  --data-urlencode "action=save_settings" \
  --data-urlencode "assessment_window_days=7" \
  --data-urlencode "minimum_recipe_variety=2" \
  --data-urlencode "minimum_data_completeness_percent=80" \
  --data-urlencode "show_optional_targets=1" \
  "${BASE_URL}/phase10.php" >/dev/null || fail "Phase 10 settings update failed"
pass "Phase 10 settings update succeeds"

nutrition_page="$(curl -fsS -b "$COOKIE_JAR" "${BASE_URL}/phase10.php")" || fail "Phase 10 workspace unavailable after settings update"
csrf="$(extract_value csrf_token "$nutrition_page")"
action_key="$(extract_value action_key "$nutrition_page")"
[[ "$csrf" =~ ^[a-f0-9]{64}$ ]] || fail "refreshed Phase 10 CSRF token missing"
[[ "$action_key" =~ ^[a-f0-9]{64}$ ]] || fail "refreshed Phase 10 action key missing"

recipe_id="$(mysql --host="${DB_HOST:-127.0.0.1}" --port="${DB_PORT:-3306}" --user="${DB_USER:-root}" --database="${DB_NAME:-homestead}" --batch --skip-column-names \
  --execute='SELECT id FROM recipes ORDER BY id DESC LIMIT 1')"
[[ "$recipe_id" =~ ^[0-9]+$ ]] || fail "Phase 10 integration recipe missing"

today="$(date -u '+%Y-%m-%d')"
curl -fsS -L -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
  --data-urlencode "csrf_token=${csrf}" \
  --data-urlencode "action_key=${action_key}" \
  --data-urlencode "action=calculate_recipe_nutrition" \
  --data-urlencode "recipe_id=${recipe_id}" \
  --data-urlencode "as_of_date=${today}" \
  "${BASE_URL}/phase10.php" >/dev/null || fail "Phase 10 recipe nutrition request failed"
pass "Phase 10 recipe nutrition calculation succeeds"

nutrition_page="$(curl -fsS -b "$COOKIE_JAR" "${BASE_URL}/phase10.php")" || fail "Phase 10 workspace unavailable after recipe calculation"
csrf="$(extract_value csrf_token "$nutrition_page")"
action_key="$(extract_value action_key "$nutrition_page")"
[[ "$csrf" =~ ^[a-f0-9]{64}$ ]] || fail "second refreshed Phase 10 CSRF token missing"
[[ "$action_key" =~ ^[a-f0-9]{64}$ ]] || fail "second refreshed Phase 10 action key missing"

meal_plan_id="$(mysql --host="${DB_HOST:-127.0.0.1}" --port="${DB_PORT:-3306}" --user="${DB_USER:-root}" --database="${DB_NAME:-homestead}" --batch --skip-column-names \
  --execute='SELECT id FROM meal_plans ORDER BY id DESC LIMIT 1')"
[[ "$meal_plan_id" =~ ^[0-9]+$ ]] || fail "Phase 10 integration meal plan missing"

curl -fsS -L -b "$COOKIE_JAR" -c "$COOKIE_JAR" \
  --data-urlencode "csrf_token=${csrf}" \
  --data-urlencode "action_key=${action_key}" \
  --data-urlencode "action=run_meal_assessment" \
  --data-urlencode "meal_plan_id=${meal_plan_id}" \
  "${BASE_URL}/phase10.php" >/dev/null || fail "Phase 10 meal assessment request failed"

assessment_count="$(mysql --host="${DB_HOST:-127.0.0.1}" --port="${DB_PORT:-3306}" --user="${DB_USER:-root}" --database="${DB_NAME:-homestead}" --batch --skip-column-names \
  --execute="SELECT COUNT(*) FROM meal_nutrition_assessments WHERE meal_plan_id = ${meal_plan_id} AND status = 'completed'")"
[[ "$assessment_count" =~ ^[0-9]+$ ]] && (( assessment_count >= 1 )) || fail "Phase 10 assessment was not completed"
pass "Phase 10 meal-plan assessment persists"

nutrition_page="$(curl -fsS -b "$COOKIE_JAR" "${BASE_URL}/phase10.php")" || fail "Phase 10 workspace unavailable after assessment"
grep -q 'Latest household plan' <<<"$nutrition_page" || fail "Phase 10 assessment results missing"
grep -q 'Nutrition recommendations' <<<"$nutrition_page" || fail "Phase 10 recommendations section missing"
grep -q 'Recipe intelligence' <<<"$nutrition_page" || fail "Phase 10 recipe intelligence missing"
pass "Phase 10 assessment results render"

health="$(curl -fsS -H "X-Homestead-Health-Key: ${HEALTH_KEY}" "${BASE_URL}/api/phase10-health.php")" || fail "Phase 10 health endpoint request failed"
grep -Eq '"ok"[[:space:]]*:[[:space:]]*true' <<<"$health" || { printf '%s\n' "$health" >&2; fail "Phase 10 health endpoint did not report ok"; }
grep -Eq '"phase"[[:space:]]*:[[:space:]]*10' <<<"$health" || fail "Phase 10 health response omitted phase identifier"
pass "Phase 10 health endpoint passes"

echo "Phase 10 HTTP smoke suite passed."