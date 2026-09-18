#!/bin/bash
# Admin API full test script – happy path + selected error scenarios
# Based on docs/management_api_test_plan.md
# DB connection (valid): host 192.168.8.114, user vsergiu, pass parola123, database test

set -e
BASE_URL="${BASE_URL:-http://localhost:8888}"
ADMIN_KEY="${ADMIN_KEY:-myverysecuresecret}"
API_NAME="admin-test-api-$$"
# Will be set after create
API_ID=""

CURL_OPTS=(-s -w "\n%{http_code}" -H "accept: application/json" -H "X-Admin-API-Key: $ADMIN_KEY")

log() { echo "[TEST] $*"; }
req() {
  local code
  code=$(curl "${CURL_OPTS[@]}" "$@" | tail -n1)
  echo "$code"
}

# --- 1. APIs ---
log "1. POST /admin/apis – create API (name=$API_NAME)"
RESP=$(curl -s -w "\n%{http_code}" -X POST "$BASE_URL/admin/apis" \
  -H "accept: application/json" -H "X-Admin-API-Key: $ADMIN_KEY" -H "Content-Type: application/json" \
  -d "{
  \"name\": \"$API_NAME\",
  \"description\": \"Full test plan API\",
  \"contact\": { \"name\": \"Tester\", \"email\": \"test@example.com\", \"phone\": \"\" }
}")
CODE=$(echo "$RESP" | tail -n1)
BODY=$(echo "$RESP" | sed '$d')
if [ "$CODE" != "201" ]; then echo "Expected 201, got $CODE"; echo "$BODY"; exit 1; fi
API_ID=$(echo "$BODY" | sed -n 's/.*"id":"\([^"]*\)".*/\1/p')
if [ -z "$API_ID" ]; then echo "No id in response"; echo "$BODY"; exit 1; fi
log "Created apiId=$API_ID"
echo ""

log "1b. POST /admin/apis – validation failure (missing name)"
CODE=$(req -X POST "$BASE_URL/admin/apis" -H "Content-Type: application/json" -d '{"description":"x"}')
if [ "$CODE" != "400" ]; then echo "Expected 400, got $CODE"; exit 1; fi
log "OK – 400 for missing name"
echo ""

log "2. GET /admin/apis – list"
CODE=$(req "$BASE_URL/admin/apis?limit=50")
if [ "$CODE" != "200" ]; then echo "Expected 200, got $CODE"; exit 1; fi
log "OK – list 200"
echo ""

log "3. GET /admin/apis/$API_ID – get"
CODE=$(req "$BASE_URL/admin/apis/$API_ID")
if [ "$CODE" != "200" ]; then echo "Expected 200, got $CODE"; exit 1; fi
log "OK – get 200"
echo ""

log "3b. GET /admin/apis/nonexistent-id-12345 – not found"
CODE=$(req "$BASE_URL/admin/apis/nonexistent-id-12345")
if [ "$CODE" != "404" ]; then echo "Expected 404, got $CODE"; exit 1; fi
log "OK – 404 for nonexistent"
echo ""

# --- 2. Connection (valid DB) ---
log "4. PUT /admin/apis/$API_ID/connection – set connection (192.168.8.114, test, vsergiu)"
CODE=$(req -X PUT "$BASE_URL/admin/apis/$API_ID/connection" -H "Content-Type: application/json" \
  -d '{"driver":"mysql","host":"192.168.8.114","port":3306,"database":"test","username":"vsergiu","password":"parola123","ssl":{"mode":"preferred"}}')
if [ "$CODE" != "200" ]; then echo "Expected 200, got $CODE"; exit 1; fi
log "OK – connection set 200"
echo ""

log "5. POST /admin/apis/$API_ID/connection:test – test connection"
RESP=$(curl -s "$BASE_URL/admin/apis/$API_ID/connection:test" \
  -X POST -H "accept: application/json" -H "X-Admin-API-Key: $ADMIN_KEY" -d '')
if echo "$RESP" | grep -q '"status":"ok"'; then
  log "OK – connection test status=ok"
else
  log "WARN – connection test response: $RESP"
fi
echo ""

log "6. GET /admin/apis/$API_ID/connection – get connection (no password)"
CODE=$(req "$BASE_URL/admin/apis/$API_ID/connection")
if [ "$CODE" != "200" ]; then echo "Expected 200, got $CODE"; exit 1; fi
log "OK – get connection 200"
echo ""

# --- 3. Network policies ---
log "7. PUT /admin/apis/$API_ID/policies/config-network"
CODE=$(req -X PUT "$BASE_URL/admin/apis/$API_ID/policies/config-network" -H "Content-Type: application/json" \
  -d '{"defaultAction":"deny","rules":[{"action":"allow","cidr":"192.168.0.0/16","description":"LAN"}]}')
if [ "$CODE" != "200" ]; then echo "Expected 200, got $CODE"; exit 1; fi
log "OK – config-network 200"
echo ""

log "8. PUT /admin/apis/$API_ID/policies/data-network"
CODE=$(req -X PUT "$BASE_URL/admin/apis/$API_ID/policies/data-network" -H "Content-Type: application/json" \
  -d '{"defaultAction":"allow","rules":[]}')
if [ "$CODE" != "200" ]; then echo "Expected 200, got $CODE"; exit 1; fi
log "OK – data-network 200"
echo ""

# --- 4. Schema ---
log "9. POST /admin/apis/$API_ID/schema:introspect"
CODE=$(req -X POST "$BASE_URL/admin/apis/$API_ID/schema:introspect" -H "Content-Type: application/json" -d '{"mode":"full","includeViews":true}')
if [ "$CODE" != "200" ]; then echo "Expected 200, got $CODE"; exit 1; fi
log "OK – introspect 200"
echo ""

log "10. GET /admin/apis/$API_ID/schema/introspected"
CODE=$(req "$BASE_URL/admin/apis/$API_ID/schema/introspected")
if [ "$CODE" != "200" ]; then echo "Expected 200, got $CODE"; exit 1; fi
log "OK – introspected 200"
echo ""

log "11. GET /admin/apis/$API_ID/schema/overrides"
CODE=$(req "$BASE_URL/admin/apis/$API_ID/schema/overrides")
if [ "$CODE" != "200" ]; then echo "Expected 200, got $CODE"; exit 1; fi
log "OK – get overrides 200"
echo ""

log "12. PUT /admin/apis/$API_ID/schema/overrides"
CODE=$(req -X PUT "$BASE_URL/admin/apis/$API_ID/schema/overrides" -H "Content-Type: application/json" -d '{"hiddenEntities":[],"hiddenFields":{},"renames":{},"manualRelations":[],"hooks":{}}')
if [ "$CODE" != "200" ]; then echo "Expected 200, got $CODE"; exit 1; fi
log "OK – put overrides 200"
echo ""

log "13. GET /admin/apis/$API_ID/schema/effective"
CODE=$(req "$BASE_URL/admin/apis/$API_ID/schema/effective?includeWarnings=true")
if [ "$CODE" != "200" ]; then echo "Expected 200, got $CODE"; exit 1; fi
log "OK – effective 200"
echo ""

log "14. POST /admin/apis/$API_ID/schema:rebuild"
CODE=$(req -X POST "$BASE_URL/admin/apis/$API_ID/schema:rebuild")
if [ "$CODE" != "200" ]; then echo "Expected 200, got $CODE"; exit 1; fi
log "OK – rebuild 200"
echo ""

log "15. POST /admin/apis/$API_ID/schema:preview"
CODE=$(req -X POST "$BASE_URL/admin/apis/$API_ID/schema:preview" -H "Content-Type: application/json" -d '{"hiddenEntities":[],"hiddenFields":{},"renames":{},"manualRelations":[],"hooks":{}}')
if [ "$CODE" != "200" ]; then echo "Expected 200, got $CODE"; exit 1; fi
log "OK – preview 200"
echo ""

# --- 5. Hooks ---
log "16. GET /admin/apis/$API_ID/hooks"
CODE=$(req "$BASE_URL/admin/apis/$API_ID/hooks")
if [ "$CODE" != "200" ]; then echo "Expected 200, got $CODE"; exit 1; fi
log "OK – list hooks 200"
echo ""

log "17. PUT /admin/apis/$API_ID/hooks"
CODE=$(req -X PUT "$BASE_URL/admin/apis/$API_ID/hooks" -H "Content-Type: application/json" \
  -d '{"items":[]}')
if [ "$CODE" != "200" ]; then echo "Expected 200, got $CODE"; exit 1; fi
log "OK – replace hooks 200"
echo ""

# --- 6. Lifecycle ---
log "18. POST /admin/apis/$API_ID:validate"
CODE=$(req -X POST "$BASE_URL/admin/apis/$API_ID:validate")
if [ "$CODE" != "200" ]; then echo "Expected 200, got $CODE"; exit 1; fi
log "OK – validate 200"
echo ""

log "19. POST /admin/apis/$API_ID:activate"
CODE=$(req -X POST "$BASE_URL/admin/apis/$API_ID:activate")
if [ "$CODE" != "200" ] && [ "$CODE" != "409" ]; then echo "Expected 200 or 409, got $CODE"; exit 1; fi
log "OK – activate $CODE"
echo ""

log "20. GET /admin/apis/$API_ID/policies/auth"
CODE=$(req "$BASE_URL/admin/apis/$API_ID/policies/auth")
if [ "$CODE" != "200" ]; then echo "Expected 200, got $CODE"; exit 1; fi
log "OK – get auth policy 200"
echo ""

log "21. PATCH /admin/apis/$API_ID – update metadata"
CODE=$(req -X PATCH "$BASE_URL/admin/apis/$API_ID" -H "Content-Type: application/json" \
  -d '{"description":"Updated by full test","contact":{"name":"Tester2","email":"t2@example.com"}}')
if [ "$CODE" != "200" ]; then echo "Expected 200, got $CODE"; exit 1; fi
log "OK – patch 200"
echo ""

log "22. POST /admin/apis/$API_ID:deactivate"
CODE=$(req -X POST "$BASE_URL/admin/apis/$API_ID:deactivate")
if [ "$CODE" != "200" ] && [ "$CODE" != "409" ]; then echo "Expected 200 or 409, got $CODE"; exit 1; fi
log "OK – deactivate $CODE"
echo ""

# --- Auth: no key ---
log "23. GET /admin/apis (no auth) – expect 401"
CODE=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/admin/apis" -H "accept: application/json")
if [ "$CODE" != "401" ]; then echo "Expected 401, got $CODE"; exit 1; fi
log "OK – 401 without key"
echo ""

# --- Teardown ---
log "24. DELETE /admin/apis/$API_ID"
CODE=$(req -X DELETE "$BASE_URL/admin/apis/$API_ID?force=false")
if [ "$CODE" != "204" ] && [ "$CODE" != "409" ]; then echo "Expected 204 or 409, got $CODE"; exit 1; fi
log "OK – delete $CODE"
echo ""

log "All scenarios completed. See docs/management_api_test_plan.md for full scenario list."
