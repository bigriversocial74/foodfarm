#!/usr/bin/env bash
set -euo pipefail

base_url="${BASE_URL:-http://127.0.0.1:8080}"
cookie_jar="$(mktemp)"
login_page="$(mktemp)"
trap 'rm -f "$cookie_jar" "$login_page"' EXIT

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

[[ "$(status "$base_url/phase6.php")" == "303" ]] || fail "phase6 did not protect unauthenticated access"
pass "phase6 protects unauthenticated access"

health_body="$(curl -sS -H "X-Homestead-Health-Key: ${HOMESTEAD_HEALTH_KEY}" "$base_url/api/phase6-health.php")"
grep -q '"ok": true' <<<"$health_body" || fail "phase6 health check failed"
pass "phase6 keyed health check passes"

curl -sS -c "$cookie_jar" "$base_url/login.php" > "$login_page"
csrf="$(sed -n 's/.*name="csrf_token" value="\([^"]*\)".*/\1/p' "$login_page" | head -n1)"
[[ "$csrf" =~ ^[a-f0-9]{64}$ ]] || fail "login CSRF token missing"

login_headers="$(curl -sS -D - -o /dev/null -b "$cookie_jar" -c "$cookie_jar" \
  --data-urlencode "csrf_token=$csrf" \
  --data-urlencode "email=${HOMESTEAD_OWNER_EMAIL}" \
  --data-urlencode "password=${HOMESTEAD_OWNER_PASSWORD}" \
  "$base_url/login.php")"
grep -qi '^Location: /phase3.php' <<<"$login_headers" || fail "owner login failed"
pass "owner login succeeds"

[[ "$(status -b "$cookie_jar" "$base_url/phase6.php")" == "200" ]] || fail "authenticated phase6 route unavailable"
pass "authenticated phase6 route responds"

[[ "$(status -b "$cookie_jar" "$base_url/api/phase6-health.php")" == "200" ]] || fail "platform administrator health access failed"
pass "platform administrator can access phase6 health"

echo "Phase 6 HTTP smoke suite passed."
