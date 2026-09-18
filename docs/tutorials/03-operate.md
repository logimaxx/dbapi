# Tutorial 3 — Operate

Authenticate clients, provision APIs, and apply security policies. Optional notes for multi-API hosting and webhooks.

**Prerequisites:** [Data plane](02-data-plane.md)  
**Reference:** [Management API](../management_api.md) · [Docker deployment](../docker_deployment.md)

```bash
export BASE=http://localhost:8888
export MGMT_KEY=myverysecuresecret
```

---

## Authentication (dbAuth)

| `policies/auth.mode` | Behavior |
|---------------------|----------|
| `none` | No JWT (local Compose default) |
| `dbAuth` | Login via SQL → JWT Bearer |

### Enable dbAuth

```bash
curl -sS -X PUT "$BASE/mgmt/v1/apis/default/policies/auth" \
  -H 'Content-Type: application/json' \
  -H "X-Management-Key: $MGMT_KEY" \
  -d '{
    "mode": "dbAuth",
    "dbAuth": {
      "validity": 3600,
      "loginMethods": {
        "password": {
          "sql": "SELECT username AS unm, role FROM app_users WHERE username='\''[[login]]'\'' AND password='\''[[password]]'\''"
        },
        "pin": {
          "sql": "SELECT username AS unm, role FROM app_users WHERE pin='\''[[pin]]'\''",
          "validity": 900
        }
      }
    }
  }'
```

Placeholders `[[login]]`, `[[password]]`, `[[pin]]` map to form fields. SELECT columns become JWT claims.

### Discover, login, call

```bash
curl -sS "$BASE/v1/auth/login" | jq .

TOKEN=$(curl -sS -X POST "$BASE/v1/auth/login/password" \
  -H 'Content-Type: application/x-www-form-urlencoded' \
  -d 'login=testuser&password=testpass' | jq -r .access_token)

curl -sS "$BASE/v1/data/customers?page[limit]=1" \
  -H "Authorization: Bearer $TOKEN" | jq .
```

Login is always **form-urlencoded** to `POST .../auth/login/{method}`. Optional `refresh_validity` enables `POST .../auth/refresh` (token rotation). Validate with `GET .../auth/session` (**204** / **401**).

Restore open access when done: `PUT .../policies/auth` with `{"mode":"none"}`.

| Mode | Discover / login prefix |
|------|-------------------------|
| Single | `/v1/auth/...` |
| Multi | `/v1/apis/{apiId}/auth/...` |

---

## Provisioning an API

```text
POST /mgmt/v1/apis          → draft
PUT  .../connection         → credentials
POST .../connection:test
POST .../schema:introspect
POST .../schema:rebuild     → structure.php + openapi.json
PUT  .../policies/*         → network + auth
POST ...:validate
POST ...:activate           → data plane live
```

Until **activate**, data routes return **409 API not active**.

### Stepped flow (example: `tutorial`)

```bash
curl -sS -X POST "$BASE/mgmt/v1/apis" \
  -H 'Content-Type: application/json' \
  -H "X-Management-Key: $MGMT_KEY" \
  -d '{"name":"tutorial","description":"Tutorial API"}' | tee /tmp/create-api.json | jq .

export API_KEY=$(jq -r '.managementCredential.secret' /tmp/create-api.json)
# Secret is shown once — store it.

curl -sS -X PUT "$BASE/mgmt/v1/apis/tutorial/connection" \
  -H 'Content-Type: application/json' \
  -H "X-Api-Config-Key: $API_KEY" \
  -d '{
    "driver": "mysql",
    "host": "mysql",
    "port": 3306,
    "database": "myapp",
    "username": "user",
    "password": "password"
  }'

curl -sS -X POST "$BASE/mgmt/v1/apis/tutorial/connection:test" \
  -H "X-Api-Config-Key: $API_KEY" | jq .

curl -sS -X POST "$BASE/mgmt/v1/apis/tutorial/schema:introspect" \
  -H "X-Api-Config-Key: $API_KEY" | jq .
curl -sS -X POST "$BASE/mgmt/v1/apis/tutorial/schema:rebuild" \
  -H "X-Api-Config-Key: $API_KEY" | jq '{ entityCount, warnings }'

curl -sS -X PUT "$BASE/mgmt/v1/apis/tutorial/policies/data-network" \
  -H 'Content-Type: application/json' \
  -H "X-Api-Config-Key: $API_KEY" \
  -d '{"defaultAction":"deny","rules":[{"cidr":"0.0.0.0/0","action":"allow"}]}'

curl -sS -X PUT "$BASE/mgmt/v1/apis/tutorial/policies/auth" \
  -H 'Content-Type: application/json' \
  -H "X-Api-Config-Key: $API_KEY" \
  -d '{"mode":"none"}'

curl -sS -X POST "$BASE/mgmt/v1/apis/tutorial:validate" \
  -H "X-Api-Config-Key: $API_KEY" | jq .
curl -sS -X POST "$BASE/mgmt/v1/apis/tutorial:activate" \
  -H "X-Api-Config-Key: $API_KEY" | jq '{ name, status }'

curl -sS "$BASE/v1/apis/tutorial/data/customers?page[limit]=2" | jq .
```

### Quick-create (dev / CI)

```bash
curl -sS -X POST "$BASE/mgmt/v1/apis?provision=immediate" \
  -H 'Content-Type: application/json' \
  -H "X-Management-Key: $MGMT_KEY" \
  -d '{
    "name": "quickdemo",
    "connection": {
      "driver": "mysql",
      "host": "mysql",
      "port": 3306,
      "database": "myapp",
      "username": "user",
      "password": "password"
    }
  }' | jq .
```

| Header | Scope |
|--------|-------|
| `X-Management-Key` | Instance — create/delete any API |
| `X-Api-Config-Key` | Per-API — connection, schema, policies |

Deactivate: `POST .../{id}:deactivate`. Delete: `DELETE .../{id}?force=true`.

---

## Security policies

Layers on each data-plane request:

```text
1. IP ACL (data network)
2. Path rules (URL + method + optional JWT claim)
3. Table access (public / private / scoped)
4. mandatoryFilter / mandatoryAssign
5. Field-level ACL
```

### Data network (IP)

```bash
curl -sS -X PUT "$BASE/mgmt/v1/apis/default/policies/data-network" \
  -H 'Content-Type: application/json' \
  -H "X-Management-Key: $MGMT_KEY" \
  -d '{
    "defaultAction": "deny",
    "rules": [
      { "cidr": "127.0.0.1/32", "action": "allow" },
      { "cidr": "172.16.0.0/12", "action": "allow" }
    ]
  }'
```

Config network (`policies/config-network`) separately restricts who may call the Management API.

### Path rules and table access

Path rules match patterns and methods (optionally `when` on JWT claims). Table overrides via schema:

```bash
curl -sS -X PATCH "$BASE/mgmt/v1/apis/default/schema/overrides" \
  -H 'Content-Type: application/json' \
  -H "X-Management-Key: $MGMT_KEY" \
  -d '{
    "orders": {
      "access": "private",
      "mandatoryFilter": "customer_id={{userId}}",
      "mandatoryAssign": { "customer_id": "{{userId}}" }
    }
  }'

curl -sS -X POST "$BASE/mgmt/v1/apis/default/schema:rebuild" \
  -H "X-Management-Key: $MGMT_KEY" | jq .warnings
```

`filterBypassRoles` on the auth policy lets listed roles skip mandatory filter/assign. Field ACLs hide or restrict columns via the same overrides + rebuild. Details: [Management API](../management_api.md).

| Symptom | Check |
|---------|-------|
| **401** / **403** | JWT / path rule |
| **409 API not active** | `:activate` |
| Empty list, no error | `mandatoryFilter` |

---

## Multi-API and webhooks (see also)

| Concern | Single | Multi |
|---------|--------|-------|
| Env | `DEPLOYMENT_MODE=single` | `multi` (default) |
| Data URL | `/v1/data/{resource}` | `/v1/apis/{apiId}/data/{resource}` |
| Management id | `default` (fixed) | Per API |

Webhooks publish write events to **Redis Streams** when `REDIS_*` is set and hooks are configured. The root Compose stack does **not** include Redis; use [`docker/base/`](../../docker/base/) (dispatcher included) or set `REDIS_HOST` yourself. `:validate` warns if hooks exist without Redis.

Schema customization (hide tables, rename relationships, procedures): Management API `schema/overrides` + `schema:rebuild` — see [Management API](../management_api.md) and [OpenAPI pipeline](../openapi_pipeline.md).

---

## What you learned

- Discover login methods, then exchange credentials for a Bearer JWT.
- Draft → connect → introspect/rebuild → validate → activate is the safe provision path.
- IP, path, table, and field policies compose; design outside-in.
- Multi-API URLs and optional Redis webhooks are for production-style setups.
