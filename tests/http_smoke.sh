#!/usr/bin/env bash
set -euo pipefail

base_url="${BASE_URL:-http://127.0.0.1:8080}"
cookie_jar="$(mktemp)"
login_page="$(mktemp)"
family_page="$(mktemp)"
recipe_page="$(mktemp)"
logout_page="$(mktemp)"
headers_file="$(mktemp)"
trap 'rm -f "$cookie_jar" "$login_page" "$family_page" "$recipe_page" "$logout_page" "$headers_file"' EXIT

fail() {
  echo "[FAIL] $1" >&2
  exit 1
}
pass() {
  echo "[PASS] $1"
}
status() {
  curl -sS -o /dev/null -w '%{http_code}' "$@"
}

[[ "$(status "$base_url/index.php")" == "200" ]] || fail "public landing page unavailable"
pass "public landing page responds"

curl -sS -D "$headers_file" -o /dev/null "$base_url/login.php"
[[ "$(awk 'NR==1 {print $2}' "$headers_file")" == "200" ]] || fail "login unavailable"
grep -qi '^Content-Security-Policy:' "$headers_file" || fail "CSP header missing"
grep -qi '^X-Content-Type-Options: nosniff' "$headers_file" || fail "nosniff header missing"
grep -qi '^X-Frame-Options: DENY' "$headers_file" || fail "anti-framing header missing"
grep -qi '^Cache-Control:' "$headers_file" || true
pass "login page responds with security headers"

for route in phase2.php phase3.php phase4.php phase5.php starter-kit-lifecycle.php account.php; do
  [[ "$(status "$base_url/$route")" == "303" ]] || fail "protected route $route did not redirect"
done
pass "protected routes redirect unauthenticated requests"

[[ "$(status "$base_url/api/phase5-health.php")" == "404" ]] || fail "health endpoint disclosed without authorization"
pass "health endpoint hides from unauthorized requests"

health_body="$(curl -sS -H "X-Homestead-Health-Key: ${HOMESTEAD_HEALTH_KEY}" "$base_url/api/phase5-health.php")"
if ! grep -q '"ok": true' <<<"$health_body"; then
  echo "$health_body" >&2
  fail "authorized health check did not pass"
fi
pass "authorized health endpoint passes"

curl -sS -c "$cookie_jar" "$base_url/login.php" > "$login_page"
csrf="$(sed -n 's/.*name="csrf_token" value="\([^"]*\)".*/\1/p' "$login_page" | head -n1)"
[[ "$csrf" =~ ^[a-f0-9]{64}$ ]] || fail "login CSRF token missing"

login_headers="$(curl -sS -D - -o /dev/null -b "$cookie_jar" -c "$cookie_jar" \
  --data-urlencode "csrf_token=$csrf" \
  --data-urlencode "email=${HOMESTEAD_OWNER_EMAIL}" \
  --data-urlencode "password=${HOMESTEAD_OWNER_PASSWORD}" \
  "$base_url/login.php")"
grep -qi '^Location: /login.php' <<<"$login_headers" || fail "login did not return to the authenticated login route"
[[ "$(status -b "$cookie_jar" "$base_url/login.php")" == "200" ]] || fail "authenticated login route unavailable"
pass "owner login succeeds"

for route in phase2.php phase3.php phase4.php phase5.php starter-kit-lifecycle.php account.php; do
  [[ "$(status -b "$cookie_jar" "$base_url/$route")" == "200" ]] || fail "authenticated route $route unavailable"
done
pass "authenticated application routes respond"

curl -sS -b "$cookie_jar" -c "$cookie_jar" "$base_url/phase2.php?section=family" > "$family_page"
family_csrf="$(sed -n 's/.*name="csrf_token" value="\([^"]*\)".*/\1/p' "$family_page" | head -n1)"
[[ "$family_csrf" =~ ^[a-f0-9]{64}$ ]] || fail "family member CSRF token missing"
family_name="HTTP Family Member ${RANDOM}-$(date +%s)"
family_headers="$(curl -sS -D - -o /dev/null -b "$cookie_jar" -c "$cookie_jar" \
  --data-urlencode "csrf_token=$family_csrf" \
  --data-urlencode "action=add_member" \
  --data-urlencode "display_name=$family_name" \
  --data-urlencode "age_group=adult" \
  --data-urlencode "role=adult_member" \
  --data-urlencode "serving_multiplier=1" \
  --data-urlencode "dietary_pattern=" \
  --data-urlencode "allergen_notes=" \
  --data-urlencode "activity_level=not_set" \
  --data-urlencode "height_value=" \
  --data-urlencode "height_unit=in" \
  --data-urlencode "weight_value=" \
  --data-urlencode "weight_unit=lb" \
  --data-urlencode "wellness_visibility=private" \
  "$base_url/phase2.php?section=family")"
grep -qi '^Location: /phase2.php?section=family' <<<"$family_headers" || fail "family member creation did not return to the Family route"
curl -sS -b "$cookie_jar" "$base_url/phase2.php?section=family" > "$family_page"
grep -Fq "$family_name" "$family_page" || fail "created family member was not rendered after redirect"
pass "family member creation persists and returns to Family"

curl -sS -b "$cookie_jar" -c "$cookie_jar" "$base_url/phase4.php" > "$recipe_page"
recipe_csrf="$(sed -n 's/.*name="csrf_token" value="\([^"]*\)".*/\1/p' "$recipe_page" | head -n1)"
[[ "$recipe_csrf" =~ ^[a-f0-9]{64}$ ]] || fail "recipe CSRF token missing"
recipe_name="HTTP Kitchen Recipe ${RANDOM}-$(date +%s)"
recipe_headers="$(curl -sS -D - -o /dev/null -b "$cookie_jar" -c "$cookie_jar" \
  --data-urlencode "csrf_token=$recipe_csrf" \
  --data-urlencode "action=create_recipe" \
  --data-urlencode "name=$recipe_name" \
  --data-urlencode "category=HTTP QA" \
  --data-urlencode "servings=4" \
  --data-urlencode "yield_quantity=4" \
  --data-urlencode "yield_unit=servings" \
  --data-urlencode "prep_minutes=5" \
  --data-urlencode "cook_minutes=10" \
  --data-urlencode "rest_minutes=0" \
  --data-urlencode "instructions=Prepare and record the test batch." \
  "$base_url/phase4.php")"
recipe_location="$(awk 'BEGIN{IGNORECASE=1} /^Location:/ {gsub("\r", ""); print $2; exit}' <<<"$recipe_headers")"
[[ "$recipe_location" =~ ^/phase4.php\?recipe=([0-9]+)$ ]] || fail "recipe creation did not return to the selected recipe"
recipe_id="${BASH_REMATCH[1]}"
curl -sS -b "$cookie_jar" -c "$cookie_jar" "$base_url$recipe_location" > "$recipe_page"
grep -Fq "$recipe_name" "$recipe_page" || fail "created recipe was not rendered"
recipe_csrf="$(sed -n 's/.*name="csrf_token" value="\([^"]*\)".*/\1/p' "$recipe_page" | head -n1)"

ingredient_headers="$(curl -sS -D - -o /dev/null -b "$cookie_jar" -c "$cookie_jar" \
  --data-urlencode "csrf_token=$recipe_csrf" \
  --data-urlencode "action=add_ingredient" \
  --data-urlencode "recipe_id=$recipe_id" \
  --data-urlencode "ingredient_name=HTTP Optional Garnish" \
  --data-urlencode "quantity=1" \
  --data-urlencode "unit=each" \
  --data-urlencode "inventory_item_id=" \
  --data-urlencode "optional=1" \
  "$base_url/phase4.php?recipe=$recipe_id")"
grep -qi "^Location: /phase4.php?recipe=$recipe_id" <<<"$ingredient_headers" || fail "ingredient creation did not return to the recipe"
pass "recipe creation and ingredient workflow succeeds"

plan_name="HTTP Weekly Plan ${RANDOM}-$(date +%s)"
plan_start="$(php -r 'echo gmdate("Y-m-d");')"
plan_end="$(php -r 'echo gmdate("Y-m-d", strtotime("+6 days"));')"
plan_headers="$(curl -sS -D - -o /dev/null -b "$cookie_jar" -c "$cookie_jar" \
  --data-urlencode "csrf_token=$recipe_csrf" \
  --data-urlencode "action=create_meal_plan" \
  --data-urlencode "name=$plan_name" \
  --data-urlencode "starts_on=$plan_start" \
  --data-urlencode "ends_on=$plan_end" \
  "$base_url/phase4.php")"
grep -qi '^Location: /phase4.php#meal-planning' <<<"$plan_headers" || fail "meal-plan creation did not return to the planner"
curl -sS -b "$cookie_jar" -c "$cookie_jar" "$base_url/phase4.php?recipe=$recipe_id" > "$recipe_page"
plan_id="$(grep -F "$plan_name" "$recipe_page" | sed -n 's/.*<option value="\([0-9][0-9]*\)">.*/\1/p' | head -n1)"
[[ "$plan_id" =~ ^[0-9]+$ ]] || fail "created meal plan was not available for scheduling"
member_id="$(sed -n 's/.*name="member_ids\[\]" value="\([0-9][0-9]*\)".*/\1/p' "$recipe_page" | head -n1)"
[[ "$member_id" =~ ^[0-9]+$ ]] || fail "meal-planning family member option missing"
meal_headers="$(curl -sS -D - -o /dev/null -b "$cookie_jar" -c "$cookie_jar" \
  --data-urlencode "csrf_token=$recipe_csrf" \
  --data-urlencode "action=add_meal" \
  --data-urlencode "meal_plan_id=$plan_id" \
  --data-urlencode "recipe_id=$recipe_id" \
  --data-urlencode "meal_date=$plan_start" \
  --data-urlencode "meal_type=dinner" \
  --data-urlencode "notes=HTTP scheduled meal" \
  --data-urlencode "member_ids[]=$member_id" \
  "$base_url/phase4.php")"
grep -qi '^Location: /phase4.php#meal-planning' <<<"$meal_headers" || fail "scheduled meal did not return to the planner"
pass "meal plan creation and scheduled meal workflow succeeds"

curl -sS -b "$cookie_jar" -c "$cookie_jar" "$base_url/phase4.php?recipe=$recipe_id" > "$recipe_page"
recipe_script="$(cd "$(dirname "$0")/.." && pwd)/assets/js/homestead-recipes.js"
grep -Fq 'window.crypto.getRandomValues' "$recipe_script" || fail "recipe completion key generation is missing from the browser script"
grep -Fq "completionKey.name = 'completion_key'" "$recipe_script" || fail "recipe completion key is not attached to the browser form"
grep -Fq 'useByDate.required = true' "$recipe_script" || fail "recipe use-by date browser validation is missing"
completion_key="$(php -r 'echo bin2hex(random_bytes(32));')"
[[ "$completion_key" =~ ^[a-f0-9]{64}$ ]] || fail "test recipe completion key generation failed"
use_by="$(php -r 'echo gmdate("Y-m-d", strtotime("+2 days"));')"
completion_headers="$(curl -sS -D - -o /dev/null -b "$cookie_jar" -c "$cookie_jar" \
  --data-urlencode "csrf_token=$recipe_csrf" \
  --data-urlencode "action=complete_recipe" \
  --data-urlencode "recipe_id=$recipe_id" \
  --data-urlencode "completion_key=$completion_key" \
  --data-urlencode "scale_factor=1" \
  --data-urlencode "actual_servings=4" \
  --data-urlencode "storage_method=counter" \
  --data-urlencode "storage_location_id=" \
  --data-urlencode "use_by_date=$use_by" \
  --data-urlencode "reheating_notes=Serve warm." \
  --data-urlencode "intended_member_ids[]=$member_id" \
  --data-urlencode "notes=HTTP completed recipe" \
  "$base_url/phase4.php?recipe=$recipe_id")"
grep -qi '^Location: /phase4.php#prepared-food' <<<"$completion_headers" || fail "recipe completion did not return to prepared food"
curl -sS -b "$cookie_jar" "$base_url/phase4.php" > "$recipe_page"
grep -Fq 'Recipe completed. Ingredients were deducted' "$recipe_page" || fail "completed recipe confirmation was not rendered"
grep -Fq "$recipe_name" "$recipe_page" || fail "prepared food batch was not rendered"
pass "recipe completion creates prepared food through the browser flow"

[[ "$(status -b "$cookie_jar" "$base_url/api/phase5-health.php")" == "200" ]] || fail "platform administrator session could not access health endpoint"
pass "platform administrator session can access health endpoint"

curl -sS -b "$cookie_jar" -c "$cookie_jar" "$base_url/logout.php" > "$logout_page"
logout_csrf="$(sed -n 's/.*name="csrf_token" value="\([^"]*\)".*/\1/p' "$logout_page" | head -n1)"
[[ "$logout_csrf" =~ ^[a-f0-9]{64}$ ]] || fail "logout CSRF token missing"

logout_headers="$(curl -sS -D - -o /dev/null -b "$cookie_jar" -c "$cookie_jar" \
  --data-urlencode "csrf_token=$logout_csrf" "$base_url/logout.php")"
grep -qi '^Location: /login.php' <<<"$logout_headers" || fail "logout did not return to login"
[[ "$(status -b "$cookie_jar" "$base_url/phase2.php")" == "303" ]] || fail "session remained authenticated after logout"
pass "CSRF-protected logout destroys authenticated access"

echo "HTTP smoke suite passed."
