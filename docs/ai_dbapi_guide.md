# dbAPI — AI integration guide

> **Purpose:** copy this file into consumer projects (frontend, backend, scripts, automations) as instructions for AI assistants (Cursor rules, `AGENTS.md`, project context).
>
> **Recommended placement:** `.cursor/rules/dbapi.md` or a dedicated section in `AGENTS.md`.
>
> **Full documentation (dbapi repo):** [README](../README.md) · [Tutorials](tutorials/README.md) · [Docker deployment](docker_deployment.md) · [Management API](management_api.md) · [Using the API](using_the_api.md)

---

## 1. What dbAPI is

**dbAPI** exposes a **MySQL/MariaDB** database as a REST API in **[JSON:API](https://jsonapi.org/)** format. The DB schema is the source of truth: tables and views become resources, foreign keys become relationships.

There are **two separate planes**:


| Plane                              | Used by                         | Response format | Purpose                            |
| ---------------------------------- | ------------------------------- | --------------- | ---------------------------------- |
| **Control plane** (Management API) | operators, CI/CD, initial setup | plain JSON      | create / configure / activate APIs |
| **Data plane**                     | your app (SPA, mobile, scripts) | JSON:API        | CRUD on DB rows                    |


**Rule for AI:** consumer-project code should call the **data plane** almost exclusively. Use the Management API only for provisioning, policies, or schema rebuild — not in normal user flows.

---

## 2. Configuration in the consumer project

Define these values (env, config, secrets) in the project that *consumes* dbAPI:

```env
# Required (adapt to your deployment)
DBAPI_BASE_URL=https://api.example.com          # no trailing slash
DBAPI_API_ID=demo                               # omit in single-mode Docker
DBAPI_AUTH_MODE=none                            # none | jwt

# Only when auth mode = jwt
DBAPI_AUTH_BASE=${DBAPI_BASE_URL}/v1/apis/${DBAPI_API_ID}/auth   # or /v1/auth in single-mode
DBAPI_LOGIN_METHOD=password                     # from GET .../auth/login discovery
DBAPI_JWT=                                      # Bearer token, refreshed periodically

# Setup/ops only (NOT in public frontend)
DBAPI_MANAGEMENT_KEY=                           # X-Management-Key
DBAPI_CONFIG_KEY=                               # X-Api-Config-Key per API
```

### Deployment modes


| Mode                                              | Data plane URL                           | Management `apiId`         | Swagger                                        |
| ------------------------------------------------- | ---------------------------------------- | -------------------------- | ---------------------------------------------- |
| **Multi-API** (standard)                          | `{base}/v1/apis/{apiId}/data/{resource}` | `{apiId}` chosen at create | `{base}/swagger.html?url=apis/{apiId}/swagger` |
| **Single-mode** (Docker `DEPLOYMENT_MODE=single`) | `{base}/v1/data/{resource}`              | fixed: `default`           | `{base}/swagger.html?url=v1/swagger`           |
| **Legacy** (avoid in new code)                    | `{base}/apis/{apiId}/data/...`           | —                          | `{base}/apis/{apiId}/swagger`                  |


**Service discovery:** `GET {base}/` returns JSON with path hints.

**Health:** `GET {base}/health` — unauthenticated liveness (`{"status":"ok"}`). Does not check the database.

**OpenAPI:** spec is generated on `schema:rebuild`; read it before inventing resource or field names.

---

## 3. Authentication

### Data plane


| `policies/auth.mode` | Behavior                                                         |
| -------------------- | ---------------------------------------------------------------- |
| `none`               | No JWT; guest read may be allowed (also depends on IP/path ACLs) |
| `dbAuth`             | One or more named login methods (SQL) → JWT Bearer               |


Auth URLs (multi-API): `{base}/v1/apis/{apiId}/auth/...` — single-mode: `{base}/v1/auth/...`. Legacy `{base}/apis/{apiId}/auth/...` still works; prefer `/v1/` in new code.

### Discover login methods

Before building a login form, fetch the configured methods and their required fields:

```http
GET {base}/v1/apis/{apiId}/auth/login
```

**Response (`mode: dbAuth`):**

```json
{
  "loginMethods": [
    { "name": "password", "fields": ["login", "password"], "expiresIn": 3600 },
    { "name": "pin", "fields": ["pin"], "expiresIn": 900 }
  ]
}
```

When `mode: none`, `loginMethods` is an empty array.

### Login

Login is always `**POST .../auth/login/{loginMethod}**` — the method name is part of the URL path, not the body. Send credentials as `**application/x-www-form-urlencoded**`; field names must match the method's `fields` from discovery (typically derived from `[[placeholder]]` names in the SQL query).

**Password login (multi-API):**

```http
POST {base}/v1/apis/{apiId}/auth/login/password
Content-Type: application/x-www-form-urlencoded

login=USER&password=PASS
```

**PIN login (example):**

```http
POST {base}/v1/apis/{apiId}/auth/login/pin
Content-Type: application/x-www-form-urlencoded

pin=1234
```

**Single-mode:** same pattern under `{base}/v1/auth/login` (GET) and `{base}/v1/auth/login/{loginMethod}` (POST).

**Notes:**

- `POST .../auth/login` without a method name returns **404** — always include `{loginMethod}`.
- Unknown method → **404**; missing/empty required field → **400**; invalid credentials → **404**.
- `expires_in` in the token response may differ per method when the policy sets method-level `validity`.
- APIs configured with a legacy single `loginQuery` expose one method named `**password`** (backward compatible).

**Token response:**

```json
{
  "access_token": "<jwt>",
  "expires_in": 3600,
  "token_type": "Bearer"
}
```

When `refresh_validity` is set on the policy (seconds, optional per-method override; `0` disables that method), login also returns `refresh_token` and `refresh_expires_in`. Discovery then includes `refreshExpiresIn` on methods that issue refresh tokens.

**Refresh (rotate):**

```http
POST {base}/v1/apis/{apiId}/auth/refresh
Content-Type: application/x-www-form-urlencoded

refresh_token=...
```

Success: same token JSON as login (new pair). The previous refresh token is invalid. Missing field → **400**; invalid/expired/reused → **401** empty body.

**Logout (revoke refresh):** `POST .../auth/logout` with `refresh_token=...` → **204**.

Refresh tokens are opaque (not JWTs) and stored hashed in `dbapi_refresh_tokens`. Do not send the refresh token as `Authorization: Bearer`.

### Authenticated requests

```http
Authorization: Bearer <access_token>
```

### Management API (ops only)


| Header             | Scope                                            |
| ------------------ | ------------------------------------------------ |
| `X-Management-Key` | instance — create/delete APIs                    |
| `X-Api-Config-Key` | per-API — connection, schema, policies, activate |


**Rule for AI:** do not expose management keys in the frontend. Do not hardcode secrets in source — use env / a secrets manager.

---

## 4. API lifecycle (debugging context)

An API must be `**active`** for the data plane to respond. Otherwise: **409 API not active**.

Typical flow (Management API):

```text
POST /mgmt/v1/apis → draft (permissive defaults)
PUT  .../connection + POST .../connection:test
POST .../schema:sync?activate=true
```

Stepped: `schema:introspect` → `schema:rebuild` → `POST ...:activate` (`:validate` is optional dry-run).

Quick-create (dev/CI): `POST /mgmt/v1/apis?provision=immediate` with `connection` in the body.

---

## 5. JSON:API format — mandatory rules

All write requests use `**Content-Type: application/json**`.

### List response shape

```json
{
  "data": [
    {
      "id": "123",
      "type": "customers",
      "attributes": { "name": "...", "email": "..." },
      "relationships": {
        "orders": { "data": [{ "id": "1", "type": "orders" }] },
        "account_manager_id": { "data": { "id": "5", "type": "users" } }
      }
    }
  ],
  "meta": { "total": 214, "offset": 0 },
  "included": []
}
```

### Critical rules

1. `**type**` must match the resource name (= exposed table/view name).
2. `**id**` in the URL and in the PATCH body must match.
3. DB columns live in `**attributes**`, not at the root of the object.
4. Views without a PK: list OK, **GET by id → 404**, **POST → error**.
5. Header `**X-Request-Id`**: send it for correlation; it also appears in errors (`meta.request_id`).

---

## 6. URLs and HTTP methods

Base: `{base}/v1/apis/{apiId}/data/` (or `{base}/v1/data/` in single-mode).


| Operation           | Method | URL                                        |
| ------------------- | ------ | ------------------------------------------ |
| List / filter       | GET    | `.../data/{resource}`                      |
| Single row          | GET    | `.../data/{resource}/{id}`                 |
| Create              | POST   | `.../data/{resource}`                      |
| Update one row      | PATCH  | `.../data/{resource}/{id}`                 |
| Delete one row      | DELETE | `.../data/{resource}/{id}`                 |
| Bulk update         | PATCH  | `.../data/{resource}` (body: array `data`) |
| Bulk delete         | DELETE | `.../data/{resource}?filter=...`           |
| Relationship (list) | GET    | `.../data/{resource}/{id}/{relation}`      |
| Create related      | POST   | `.../data/{resource}/{id}/{relation}`      |
| Stored procedure    | POST   | `.../data/__call__/{procedure_name}`       |


---

## 7. Query parameters (read)

### Filtering — `filter[{resource}]`

Compact expression language; operators:


| Operator          | Meaning           | Example         |
| ----------------- | ----------------- | --------------- |
| `=`               | equal             | `city=London`   |
| `!=`              | not equal         | `status!=0`     |
| `>` `>=` `<` `<=` | comparisons       | `qty>0`         |
| `=~`              | starts with       | `name=~John`    |
| `~=`              | ends with         | `file~=.pdf`    |
| `~=~`             | contains          | `note~=~urgent` |
| `><`              | one of (sep. `;`) | `status><1;2;3` |


**Combining:** `,` = AND (higher precedence), `||` = OR, `()` = grouping.

Examples:

```http
GET .../customers?filter[customers]=name=~John,active=1
GET .../customers?filter[customers]=(city=NY||city=LA),status=1
GET .../customers?filter[customers/orders]=qty>100
```

Escape: `\` before `,`, `||`, `)`.

### Sorting — `sort[{resource}]`

```http
GET .../customers?sort[customers]=name,-created_at
```

`-` = descending.

### Pagination — `page[...]`

```http
GET .../customers?page[offset]=0&page[limit]=20
GET .../customers?include=orders&page[customers][offset]=0&page[orders][limit]=5
```

### Sparse fieldsets — `fields[{resource}]`

```http
GET .../customers?fields[customers]=name,email
GET .../customers/1?include=orders&fields[customers]=name,email&fields[orders]=status,total
GET .../orders/1?include=customer_id&fields[orders]=status,total&fields[customers]=name,email
```

Keys are resource **types** (table names). Path keys (`fields[orders/customer_id]`) also work for a specific include edge.

### Include relationships — `include`

```http
GET .../customers/1?include=orders,account_manager_id
```

**Relationship names are part of the public contract** — do not guess pluralizations; check OpenAPI or the JSON response.


| Direction             | Default name                               |
| --------------------- | ------------------------------------------ |
| Outbound (FK)         | FK column name (e.g. `account_manager_id`) |
| Inbound (one-to-many) | child table name (e.g. `orders`)           |


---

## 8. Write operations — examples

### Simple create

```http
POST .../data/customers
Content-Type: application/json

{
  "data": {
    "type": "customers",
    "attributes": {
      "name": "Acme",
      "email": "a@acme.test",
      "country_code": "RO"
    }
  }
}
```

### Update

```http
PATCH .../data/customers/42
Content-Type: application/json

{
  "data": {
    "type": "customers",
    "id": "42",
    "attributes": { "name": "Acme SRL" }
  }
}
```

### Create with nested relationship

Inbound (one-to-many) children are an array in `relationships.{name}.data`. Example: create an order with line items in one request (`order_lines` is the child table name from the schema).

```http
POST .../data/orders
Content-Type: application/json

{
  "data": {
    "type": "orders",
    "attributes": {
      "customer_id": 1,
      "status": "draft"
    },
    "relationships": {
      "order_lines": {
        "data": [
          {
            "type": "order_lines",
            "attributes": {
              "product_id": 1,
              "quantity": 2,
              "unit_price": 9.99
            }
          },
          {
            "type": "order_lines",
            "attributes": {
              "product_id": 2,
              "quantity": 1,
              "unit_price": 4.50
            }
          }
        ]
      }
    }
  }
}
```

### Bulk insert

Body: `"data": [ {...}, {...} ]` — default limit 100 records.

### Bulk update

```http
PATCH .../data/customers
```

Body: array of objects with `type`, `id`, `attributes`.

### Bulk delete

```http
DELETE .../data/notes?filter[id><10;11;12]
```

**Collection-level PATCH/DELETE without a valid filter or id array → 400.**

### Upsert — `onduplicate`

```http
POST .../data/products?onduplicate=ignore
POST .../data/products?onduplicate=update&update=name,price
POST .../data/products?onduplicate=update&update=price:add
```

Values: `error` (default), `ignore`, `update`.

On `update`, a bare field or `field:set` replaces the stored value. `field:add` and `field:sub` add or subtract the inserted number. Those operators are accepted only on updateable numeric columns (not the primary key, not text). The conflicting row is returned. Columns omitted from `update` stay as they are.

### Export

```http
GET .../data/customers?format=csv
```

### CSV import

```http
POST .../data/products?onduplicate=ignore
Content-Type: text/csv

sku,name,price,is_active
SKU-1,Widget,9.99,1
```

Header columns must be insertable attributes. Same bulk limit and all-or-nothing transaction as JSON create. Multipart `file`/`csv` upload is also accepted.

---

## 9. Stored procedures

```http
POST .../data/__call__/sp_validate_login
Content-Type: application/json

{ "params": ["user", "pass"] }
```

Check OpenAPI for exact parameters for procedures in your schema.

---

## 10. Limits and guardrails

Respect instance limits (defaults, overridable via env):


| Limit                    | Typical default |
| ------------------------ | --------------- |
| `page[limit]` max        | 1000            |
| filter expression length | 4096 characters |
| bulk insert              | 100             |
| bulk update              | 50              |
| request timeout          | 60s             |
| `include` depth          | 5               |


If you get 400/413/408, reduce payload size or simplify the filter — do not bypass limits.

---

## 11. Errors — how to interpret them

### Data plane (JSON:API)

```json
{
  "errors": [
    { "status": "404", "title": "...", "detail": "..." }
  ],
  "meta": { "request_id": "..." }
}
```

Common codes: **404** missing resource/id, **409** conflict (unique, API inactive), **400** invalid body/filter, **401/403** auth/ACL.

### Management API

```json
{
  "error": {
    "code": 3002,
    "message": "API not found",
    "details": { "apiId": "..." }
  }
}
```

---

## 12. Anti-patterns — what NOT to do

1. **Do not write direct CRUD SQL** in the consumer app when the goal is to use dbAPI — use the data plane.
2. **Do not use `/admin/apis`** — removed (410); use `/mgmt/v1/apis`.
3. **Do not assume field names** — read OpenAPI or a probe GET.
4. **Do not send flat fields** on POST/PATCH — only JSON:API shape with `data.type` + `data.attributes`.
5. **Do not call the Management API from the browser** — exposed admin keys are a vulnerability.
6. **Do not ignore API status** — if everything returns 409, check `:activate` and `policies/data-network`.
7. **Do not use legacy paths** (`/apis/...`) in new code — prefer `/v1/apis/...`.
8. **Do not PATCH a collection** without an id array or a valid filter for bulk delete.

---

## 13. Recommended workflow when building a feature

1. `**GET {base}/`** — confirm URL and mode (single vs multi).
2. **Fetch OpenAPI** — identify resources, fields, relationships.
3. **Probe read** — `GET .../data/{resource}?page[limit]=1`.
4. **If auth = jwt** — `GET .../auth/login` for methods/fields, then `POST .../auth/login/{method}`; attach `Authorization`.
5. **Implement CRUD** following JSON:API.
6. **Server-side filter/pagination** — do not load everything and filter client-side on large sets.
7. **Propagate `X-Request-Id`** in logs for debugging.

---

## 14. Code patterns (pseudo)

### Generic HTTP client

```javascript
const base = process.env.DBAPI_BASE_URL;
const apiId = process.env.DBAPI_API_ID;
const prefix = apiId
  ? `${base}/v1/apis/${apiId}/data`
  : `${base}/v1/data`;

async function dbapiGet(resource, query = {}, token) {
  const url = new URL(`${prefix}/${resource}`);
  Object.entries(query).forEach(([k, v]) => url.searchParams.set(k, v));
  const res = await fetch(url, {
    headers: {
      Accept: 'application/json',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
  });
  if (!res.ok) throw await res.json();
  return res.json();
}

// Active customers, page 1
const page = await dbapiGet('customers', {
  'filter[customers]': 'active=1',
  'page[offset]': '0',
  'page[limit]': '20',
  'sort[customers]': 'name',
});
const rows = page.data.map(r => ({ id: r.id, ...r.attributes }));
```

### TypeScript — types from OpenAPI

Generate types from `openapi.json` (openapi-typescript, orval, etc.) instead of hand-writing interfaces.

---

## 15. Quick references


| Resource                             | Location                                                                      |
| ------------------------------------ | ----------------------------------------------------------------------------- |
| Full Management API                  | [docs/management_api.md](management_api.md)                                   |
| Filtering, relationships, pagination | [docs/using_the_api.md](using_the_api.md)                                     |
| Tests / validation scenarios         | [docs/data_plane_test_plan.md](data_plane_test_plan.md)                       |
| Docker image (GHCR)                  | [docs/docker_deployment.md](docker_deployment.md) — `ghcr.io/dbapiator/dbapi` |
| Local dev Docker Compose             | [docker-compose.yml](../docker-compose.yml) — port 8888                       |
| Management OpenAPI spec              | `src/public/management-openapi.yaml`                                          |


---

## 16. Placeholder — fill in when copying to a consumer project

> Complete the section below when you copy this document into another project:

```yaml
dbapi:
  base_url: "https://CHANGE_ME"
  api_id: "CHANGE_ME"           # or null for single-mode
  auth_mode: "none"             # none | jwt
  openapi_url: "https://CHANGE_ME/v1/apis/CHANGE_ME/swagger"
  resources_used:
    - customers
    - orders
  notes: |
    e.g. orders is an inbound relationship on customers;
    FK account_manager_id → users.
```

