#!/usr/bin/env bash
#
# Production smoke test (PROJECT_RULES.md §28 "Production smoke test").
#
# Run this against any deployed instance right after a deploy, before
# announcing it live:
#
#   scripts/smoke-test.sh https://shop.example.com
#   scripts/smoke-test.sh http://localhost/ClothingSite-professional/ClothingSite
#
# Exits non-zero if anything fails, so it is safe to wire into a deploy
# pipeline as a gate (§28 "Deployment... Run smoke tests").
#
# This checks that the site responds correctly; it deliberately does NOT
# place a real order or send real email against a live store — that is what
# the PHPUnit suite's Feature tests already exercise against a throwaway
# database (see tests/bootstrap.php). This script is the outside-in check
# that the deployed instance is actually serving traffic correctly.

set -u

BASE="${1:?Usage: scripts/smoke-test.sh <base-url>}"
BASE="${BASE%/}"

PASS=0
FAIL=0

check() {
    local description="$1"
    local path="$2"
    local expected="$3"

    local code
    code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 "$BASE/$path")

    if [ "$code" = "$expected" ]; then
        echo "  OK   $description ($code)"
        PASS=$((PASS + 1))
    else
        echo "  FAIL $description — expected $expected, got $code"
        FAIL=$((FAIL + 1))
    fi
}

echo "Smoke testing $BASE"
echo ""

echo "Public pages:"
check "Home page"          "index.php"    200
check "Shop"                "shop.php"     200
check "About"                "about.php"    200
check "Blog"                  "blog.php"     200
check "Contact"                "contact.php"  200
check "Login page"              "login.php"    200
check "Register page"            "register.php" 200
check "Forgot password page"      "forgot-password.php" 200

echo ""
echo "Security gates (must reject, not silently allow):"
check "Cart action refuses GET"    "cartaction.php" 405
check "Wishlist action refuses GET" "wishaction.php" 405
check "Admin dashboard requires login (redirect)" "admin/seller.php" 302
check "Checkout requires login (redirect)" "checkout.php" 302

echo ""
echo "Health endpoint:"
HEALTH_BODY=$(curl -s --max-time 10 "$BASE/health.php")
HEALTH_CODE=$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 "$BASE/health.php")
if [ "$HEALTH_CODE" = "200" ]; then
    echo "  OK   health.php reports healthy"
    PASS=$((PASS + 1))
else
    echo "  FAIL health.php -> HTTP $HEALTH_CODE"
    echo "       $HEALTH_BODY"
    FAIL=$((FAIL + 1))
fi

echo ""
echo "Result: $PASS passed, $FAIL failed"

if [ "$FAIL" -gt 0 ]; then
    exit 1
fi

exit 0
