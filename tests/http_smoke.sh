#!/usr/bin/env bash
set -euo pipefail

base_url="${BASE_URL:-http://127.0.0.1:8080}"
cookie_jar="$(mktemp)"
login_page="$(mktemp)"
family_page="$(mktemp)"
logout_page="$(mktemp)"
headers_file="$(mktemp)"
trap 'rm -f "$cookie_jar" "$login_page" "$family_page" "$logout_page" "$headers_file"' EXIT

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
