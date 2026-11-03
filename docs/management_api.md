# Management API

The **Management API** is dbAPI’s **sole control plane**. Use it to create, configure, validate, activate, and retire **data APIs** — the JSON:API endpoints that expose your database (`/v1/apis/{apiId}/data/...`).

> **Note:** The legacy Admin API (`/admin/apis`) has been **removed**. Old paths return `410 Gone` with migration hints. Use `/mgmt/v1/apis` only. See [DEPRECATED_ADMIN_API.md](../src/public/DEPRECATED_ADMIN_API.md).

The Management API uses **plain JSON** (not JSON:API). Request bodies for write operations are validated against the OpenAPI specification.

| | Management API | Data API |
|---|----------------|----------|
| **Purpose** | Operate API definitions | CRUD on database rows |
| **Base path** | `/mgmt/v1` | `/v1/apis/{apiId}/data` |
| **Format** | Plain JSON | [JSON:API](https://jsonapi.org/) |

---

## Base URL

Replace `{base}` with your installation URL (no trailing slash).

| Environment | Example `{base}` |
|-------------|------------------|
| Local Apache | `http://localhost/dbapi/src` |
| Docker / custom | Your public URL to `index.php` |

All examples below use `http://localhost/dbapi/src`.

**Discover service:** `GET {base}/` returns JSON with management and data path hints.

**Health:** `GET {base}/health` — unauthenticated liveness probe (`{"status":"ok","service":"dbAPI"}`). Does not check database connectivity.

**OpenAPI & Swagger:**

- Mode-specific source files (served dynamically with the correct `servers[0].url`):
  - Multi: `management-openapi-multi.yaml` → `GET {base}/management-openapi.yaml` (multi mode) or `{base}/management-openapi-multi.yaml`
  - Single: `management-openapi-single.yaml` → `GET {base}/management-openapi.yaml` (single mode) or `{base}/management-openapi-single.yaml`
- JSON: append `.json` instead of `.yaml` on the same paths
- UI: `{base}/swagger.html?url=management-openapi.yaml`

---

## Deployment modes

| | Multi-API (default) | Single-API (`DEPLOYMENT_MODE=single`) |
|---|---------------------|----------------------------------------|
| **Use case** | Host many APIs on one instance | One database, one fixed API (`default`) |
| **Management base** | `/mgmt/v1/apis` (collection) + `/mgmt/v1/apis/{apiId}/...` | `/mgmt/v1` (instance) + `/mgmt/v1/connection`, etc. |
| **Data plane** | `/v1/apis/{apiId}/data/...` | `/v1/data/...` |
| **Create / list / delete APIs** | Yes | **No** — default API is auto-provisioned on startup |
| **Get instance API** | `GET /mgmt/v1/apis/{apiId}` | `GET /mgmt/v1` |
| **Bootstrap** | Manual via Management API | Draft scaffold on launch; `DB_*` env pre-fills `connection.php` even if DB is down |

In single mode, `GET /mgmt/v1/apis`, `POST /mgmt/v1/apis`, and `DELETE` return **404/409**. Renaming (`PATCH` with a new `name`) is not allowed.

---

## Authentication

Two levels of API keys:

| Scope | Header | Used for | Config source |
|-------|--------|----------|---------------|
| **Instance** | `X-Management-Key` | Create/list/delete APIs, break-glass on any API | `config_api_secret` in [`dbapiator.php`](../src/application/config/dbapiator.php) |
| **Per-API** | `X-Api-Config-Key` | Update connection, schema, policies, hooks, lifecycle | `admin_config.php` per API (under `configs_dir`) |

Per-API key aliases: `x-api-config-key`, `x-api-key`, `X-Api-Key` (query supported for both key types where noted above).

Legacy aliases still accepted for the **instance** key: `X-Admin-API-Key`, `X-Api-Key`, `x-api-key` (query).

**Per-API secret** is returned **once** when you create a draft API (or after rotate). Store it securely; it is not shown again on GET.

For endpoints marked “instance or per-API”, either key works: the instance key has full access; the per-API key is scoped to that API’s config directory and IP rules in `admin_config.php`.

```bash
# Rotate per-API key (global or current per-API key)
curl -X POST "{base}/mgmt/v1/apis/my-api/management-credentials:rotate" \
  -H "X-Management-Key: YOUR_INSTANCE_SECRET"
```

Instance IP restrictions apply via `config_api_ips_acls`. Per-API config updates also check IP rules in that API’s `admin_config.php`.

---

## API identity (`apiId`)

- **`apiId`** — Path segment and directory name under `configs_dir` (e.g. `demo`). Same id is used in the Data API: `/v1/apis/demo/data/...`. Must match `^[a-zA-Z0-9_\-]+$`.
- **`id`** — Stable UUID in `meta.php` (metadata only).

Renaming: `PATCH /mgmt/v1/apis/{apiId}` with body field `name` set to a new id renames the config directory when the new name is free. **Not supported in single mode** (id is fixed to `default`).

---

## API metadata (`meta.php`)

Each API stores descriptive metadata in `meta.php`. It is returned on `GET` (list items and single API resource) and can be set at create time or updated with `PATCH`.

| Field | Description |
|-------|-------------|
| `id` | Stable UUID (read-only, set on create) |
| `name` | Directory / path id (`apiId`); required on create in multi mode |
| `title` | Display title for the data-plane OpenAPI `info.title` (defaults to `name`) |
| `description` | Human-readable summary |
| `version` | Version label for data-plane OpenAPI `info.version` (default `1.0.0`) |
| `termsOfService` | URL |
| `contact` | `{ name, email, url, phone }` |
| `license` | `{ name, url }` |
| `createdAt`, `updatedAt` | Timestamps |

Create/update accept the same flat fields as the `Api` resource (except read-only `id`, `createdAt`, `updatedAt`, and operational summaries):

```json
{
  "name": "demo",
  "title": "Demo Shop API",
  "description": "Customer and order data",
  "version": "1.2.0",
  "termsOfService": "https://example.com/terms",
  "contact": { "name": "API Team", "email": "api@example.com" },
  "license": { "name": "MIT", "url": "https://opensource.org/licenses/MIT" }
}
```

**Single-mode Docker env** (optional, applied at auto-provision): `API_TITLE`, `API_DESCRIPTION`, `API_VERSION`, `API_TERMS_OF_SERVICE`, `API_LICENSE_NAME`, `API_LICENSE_URL`, `API_CONTACT_NAME`, `API_CONTACT_EMAIL`, `API_CONTACT_URL`, `API_CONTACT_PHONE`.

Metadata is copied into each API's generated `openapi.json` (`info` block) when the schema is rebuilt.

---

## Lifecycle and status

| Status | Management API | Data API |
|--------|----------------|----------|
| `draft` | Full configuration | **Not serving** (no data routes) |
| `active` | Configuration + lifecycle | **Serving** |
| `inactive` | Configuration retained | **Not serving** (409 `API not active`) |

Typical flow:

```text
POST /mgmt/v1/apis          → draft (auth: none, permissive network defaults)
PUT  .../connection         → configure DB
POST .../connection:test    → verify connectivity
POST .../schema:sync        → introspect + rebuild (+ optional ?activate=true)
POST ...:activate           → active (runs validation inline; :validate is dry-run only)
```

**Schema:sync** replaces the separate `schema:introspect` + `schema:rebuild` steps. Add `?activate=true` to validate and activate in one call.

Stepped schema (when you need to inspect between steps): `schema:introspect` → optional overrides → `schema:rebuild`.

**Validate** (`POST /mgmt/v1/apis/{apiId}:validate`) returns:

```json
{
  "ready": true,
  "checks": [
    { "id": "connection.configured", "status": "ok", "message": "..." },
    { "id": "connection.tested", "status": "ok", "message": "..." },
    { "id": "schema.structure", "status": "ok", "message": "..." },
    { "id": "policies.configNetwork", "status": "ok", "message": "..." },
    { "id": "policies.dataNetwork", "status": "ok", "message": "..." },
    { "id": "policies.auth", "status": "ok", "message": "..." },
    { "id": "hooks.redis", "status": "ok", "message": "..." },
    { "id": "meta.name", "status": "ok", "message": "..." }
  ]
}
```

Required check ids: `connection.configured`, `connection.tested`, `schema.structure`, `schema.openapi`, `policies.configNetwork`, `policies.dataNetwork`, `policies.auth`, `meta.name`. `hooks.redis` is `warn` if hooks exist but `REDIS_HOST` is unset. `schema.openapi` is `warn` when `openapi.json` is older than `structure.php` (run `schema:rebuild`).

**Activate** runs validation inline; a separate `:validate` call is optional (dry-run). Returns the API resource object (same shape as `GET /mgmt/v1/apis/{apiId}`). On failure returns `409` with `error.details.validation`. Idempotent if already active.

**Deactivate** sets `inactive` without deleting files; returns the API resource object. **Delete** removes `configs_dir/{apiId}/` (`204`); requires instance key; use `?force=true` if the API is still `active`.

---

## Response format

### Success

- **List** (`GET /mgmt/v1/apis`): `{ "items": [ Api, ... ], "pagination": { "limit", "offset", "total" } }`
- **Create** (`POST /mgmt/v1/apis`): `201` + `Location`; body `{ "api": Api, "managementCredential": { "secret", "header", "note" } }`
- **Get / patch / activate / deactivate**: body is the **Api** object (metadata + `status` + summary fields), not wrapped in `{ "api": ... }`
- **Delete**: `204` empty body
- **Connection test**: `200` with `{ "status": "ok"|"fail", "at", "latencyMs", "message" }` (HTTP stays 200 on failure; check `status`)
- **Schema introspect**: `{ "introspectedAt", "entityCount" }`
- **Schema rebuild**: `{ "rebuiltAt", "entityCount", "warnings": [ ... ] }`
- **Schema effective / introspected (generated on the fly)**: `{ "entities": { ... } }` — entity map from DB + `patch.php`
- **Schema introspected (snapshot file)**: JSON saved at introspect time (entity map at top level when read from `schema_introspected.json`)

### Errors

```json
{
  "error": {
    "code": 3002,
    "message": "API not found",
    "details": { "apiId": "missing" }
  }
}
```

Numeric `code` values come from [`errorscatalog.php`](../src/application/config/errorscatalog.php).

### Api resource object

Returned by list items, `GET/PATCH .../apis/{apiId}`, and lifecycle activate/deactivate. Fields from `meta.php` plus summaries:

| Field | Description |
|-------|-------------|
| `id`, `name`, `title`, `description`, `version`, `termsOfService`, `contact`, `license`, `createdAt`, `updatedAt` | Metadata (`title`, `version`, etc. from `meta.php`) |
| `status` | `draft`, `active`, or `inactive` |
| `connection.configured` | Whether `connection.php` has a database |
| `connection.lastTest` | `{ status, at, message }` after `connection:test` |
| `policies.configNetworkConfigured` | `admin_config.php` exists |
| `policies.dataNetworkConfigured` | `data_api_acls.php` exists |
| `schema.introspectedAt` | Last `schema:introspect` time |
| `schema.effectiveVersion` | Set when `structure.php` exists |

`GET .../connection` returns the wire format (`driver`, `host`, `port`, …) with `password` masked. `GET .../policies/auth` returns the **on-disk** shape (`loginMethods` or legacy `loginQuery`, masked `jwt_key`), not necessarily the same as the PUT body.

---

## Quick start (stepped)

Set your instance secret (default in dev: `myverysecuresecret`).

### 1. Create draft API

```bash
curl -sS -X POST "http://localhost/dbapi/src/mgmt/v1/apis" \
  -H "Content-Type: application/json" \
  -H "X-Management-Key: myverysecuresecret" \
  -d '{"name":"demo","description":"My API"}'
```

Response includes `api` and `managementCredential.secret` — save the secret.

New APIs are created with **dev-friendly defaults**: `auth` mode `none`, config-network and data-network allow `0.0.0.0/0`. Tighten policies before production.

### 2. Connection

```bash
curl -sS -X PUT "http://localhost/dbapi/src/mgmt/v1/apis/demo/connection" \
  -H "Content-Type: application/json" \
  -H "X-Api-Config-Key: YOUR_PER_API_SECRET" \
  -d '{
    "driver": "mysql",
    "host": "127.0.0.1",
    "port": 3306,
    "database": "dbapi_test",
    "username": "dbapi",
    "password": "dbapi"
  }'

curl -sS -X POST "http://localhost/dbapi/src/mgmt/v1/apis/demo/connection:test" \
  -H "X-Api-Config-Key: YOUR_PER_API_SECRET"
```

Expect `"status": "ok"` on success. A failed test still returns HTTP `200` with `"status": "fail"` and `message` set.

Connection body uses `driver` `mysql` (mysqli). Fields: `host`, `port`, `database`, `username`, `password`.

### 3. Schema

```bash
# Recommended: introspect + rebuild in one step
curl -sS -X POST "http://localhost/dbapi/src/mgmt/v1/apis/demo/schema:sync" \
  -H "X-Api-Config-Key: YOUR_PER_API_SECRET"

# Optional: sync and activate in one call (skips separate :validate / :activate)
curl -sS -X POST "http://localhost/dbapi/src/mgmt/v1/apis/demo/schema:sync?activate=true" \
  -H "X-Api-Config-Key: YOUR_PER_API_SECRET"
```

`schema:sync` writes `schema_introspected.json`, rebuilds `structure.php` (preserves relationship names), and regenerates `openapi.json`. Response includes `warnings` when the DB and prior schema diverge.

Stepped alternative (inspect between steps):

```bash
curl -sS -X POST "http://localhost/dbapi/src/mgmt/v1/apis/demo/schema:introspect" \
  -H "X-Api-Config-Key: YOUR_PER_API_SECRET"

curl -sS "http://localhost/dbapi/src/mgmt/v1/apis/demo/schema/effective" \
  -H "X-Api-Config-Key: YOUR_PER_API_SECRET"

curl -sS -X POST "http://localhost/dbapi/src/mgmt/v1/apis/demo/schema:rebuild" \
  -H "X-Api-Config-Key: YOUR_PER_API_SECRET"
```

Optional: `GET/PUT/PATCH .../schema/overrides` to adjust `patch.php` (contents merged on rebuild).

`schema:rebuild` preserves existing **relationship names** (stable public API) and returns `warnings` (e.g. `ORPHAN_RELATION`, `QUALIFIED_RELATION_NAME`, `CONFLICT_RELATION_NAME`) when the database and prior schema diverge. Warnings are also stored in `meta.php` under `schema.lastWarnings`.

Each rebuild also writes **`openapi.json`** (validated, atomic write). The data API serves it at `GET /apis/{apiId}/swagger` (no runtime regeneration).

After changing only `patch.php`, refresh the spec without a full DB rebuild:

```bash
curl -sS -X POST "http://localhost/dbapi/src/mgmt/v1/apis/demo/schema:regenerate-openapi" \
  -H "X-Api-Config-Key: YOUR_PER_API_SECRET"

curl -sS "http://localhost/dbapi/src/mgmt/v1/apis/demo/schema/openapi" \
  -H "X-Api-Config-Key: YOUR_PER_API_SECRET"
```

See [OpenAPI pipeline](openapi_pipeline.md). Bulk refresh: `php scripts/generate_openapi_specs.php [configs_dir] [base_url]`.

### 4. Policies (optional)

Defaults on create are permissive (`auth: none`, allow-all network). Skip this section for local dev; configure before production.

```bash
# Example: restrict data API to JWT + IP allowlist
curl -sS -X PUT "http://localhost/dbapi/src/mgmt/v1/apis/demo/policies/auth" \
  -H "Content-Type: application/json" \
  -H "X-Api-Config-Key: YOUR_PER_API_SECRET" \
  -d '{"mode":"dbAuth","dbAuth":{"loginMethods":{"password":{"sql":"SELECT ..."}}}}'

curl -sS -X PUT "http://localhost/dbapi/src/mgmt/v1/apis/demo/policies/data-network" \
  -H "Content-Type: application/json" \
  -H "X-Api-Config-Key: YOUR_PER_API_SECRET" \
  -d '{"defaultAction":"deny","rules":[{"cidr":"203.0.113.0/24","action":"allow"}]}'
```

### 5. Activate (if not using schema:sync?activate=true)

```bash
curl -sS -X POST "http://localhost/dbapi/src/mgmt/v1/apis/demo:activate" \
  -H "X-Api-Config-Key: YOUR_PER_API_SECRET"
```

`:validate` is optional — use it as a dry-run before activate.

### 6. Use the Data API

```bash
curl -sS "http://localhost/dbapi/src/v1/apis/demo/data/customers"
```

Response is JSON:API (`data`, `meta`, …). See [Using the API](using_the_api.md).

---

## Quick-create (CI / migration)

One request provisions a draft API, saves connection, tests it, builds `structure.php` from the live database (`DBWalk::parse` + `patch.php` merge), validates, and activates. It does **not** run `schema:introspect` or `schema:rebuild` (no `schema_introspected.json`, no relationship-name preservation warnings). For production APIs with existing clients, prefer the stepped flow (introspect → optional overrides → rebuild → activate).

```bash
curl -sS -X POST "http://localhost/dbapi/src/mgmt/v1/apis?provision=immediate" \
  -H "Content-Type: application/json" \
  -H "X-Management-Key: myverysecuresecret" \
  -d '{
    "name": "demo",
    "connection": {
      "driver": "mysql",
      "host": "127.0.0.1",
      "port": 3306,
      "database": "dbapi_test",
      "username": "dbapi",
      "password": "dbapi"
    }
  }'
```

Alternative: header `Prefer: immediate-provision`.

On failure, the API may remain in `draft` with validation details in the response (`422`).

---

## Legacy endpoint (deprecated)

`POST {base}/apis` still works for backward compatibility. It maps to quick-create and returns deprecation headers (`Deprecation`, `Link`, `Sunset`).

Legacy connection shape (`hostname` instead of `host`) is accepted:

```json
{
  "name": "demo",
  "connection": {
    "hostname": "127.0.0.1",
    "username": "dbapi",
    "password": "dbapi",
    "database": "dbapi_test"
  }
}
```

Prefer `/mgmt/v1/apis` for new integrations.

---

## Endpoint reference

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| GET | `/mgmt/v1/apis` | Instance | List APIs (`limit`, `offset`) |
| POST | `/mgmt/v1/apis` | Instance | Create draft (`?provision=immediate` for quick-create) |
| GET | `/mgmt/v1/apis/{apiId}` | Instance or per-API | Get API metadata |
| PATCH | `/mgmt/v1/apis/{apiId}` | Instance or per-API | Update name, description, contact |
| DELETE | `/mgmt/v1/apis/{apiId}` | Instance | Delete API (`?force=true` if active) |
| POST | `/mgmt/v1/apis/{apiId}/management-credentials:rotate` | Instance or per-API | Rotate per-API secret |
| GET | `/mgmt/v1/apis/{apiId}/connection` | Instance or per-API | Get connection (password masked) |
| PUT | `/mgmt/v1/apis/{apiId}/connection` | Instance or per-API | Set connection |
| POST | `/mgmt/v1/apis/{apiId}/connection:test` | Instance or per-API | Test DB connectivity |
| GET | `/mgmt/v1/apis/{apiId}/policies/config-network` | Instance or per-API | Config API IP policy |
| PUT | `/mgmt/v1/apis/{apiId}/policies/config-network` | Instance or per-API | Set config API IP policy |
| GET | `/mgmt/v1/apis/{apiId}/policies/data-network` | Instance or per-API | Data API IP policy |
| PUT | `/mgmt/v1/apis/{apiId}/policies/data-network` | Instance or per-API | Set data API IP policy |
| GET | `/mgmt/v1/apis/{apiId}/policies/auth` | Instance or per-API | Auth policy |
| PUT | `/mgmt/v1/apis/{apiId}/policies/auth` | Instance or per-API | Set auth policy (`none`, `dbAuth`) |
| POST | `/mgmt/v1/apis/{apiId}/schema:introspect` | Instance or per-API | Introspect DB; writes `schema_introspected.json` |
| POST | `/mgmt/v1/apis/{apiId}/schema:sync` | Instance or per-API | Introspect + rebuild (`?activate=true` to activate) |
| GET | `/mgmt/v1/apis/{apiId}/schema/introspected` | Instance or per-API | Read introspection snapshot (or generate if missing) |
| GET | `/mgmt/v1/apis/{apiId}/schema/effective` | Instance or per-API | Effective schema preview (DB + `patch.php`, not written to disk) |
| GET | `/mgmt/v1/apis/{apiId}/schema/overrides` | Instance or per-API | Get `patch.php` overrides |
| PUT | `/mgmt/v1/apis/{apiId}/schema/overrides` | Instance or per-API | Replace overrides |
| PATCH | `/mgmt/v1/apis/{apiId}/schema/overrides` | Instance or per-API | Merge-patch overrides |
| POST | `/mgmt/v1/apis/{apiId}/schema:rebuild` | Instance or per-API | Build `structure.php`; response includes `warnings` |
| GET | `/mgmt/v1/apis/{apiId}/hooks` | Instance or per-API | All entity hooks |
| PUT | `/mgmt/v1/apis/{apiId}/hooks` | Instance or per-API | Replace all hooks (map) |
| PUT | `/mgmt/v1/apis/{apiId}/hooks/{entityName}` | Instance or per-API | Upsert hooks for one table |
| DELETE | `/mgmt/v1/apis/{apiId}/hooks/{entityName}` | Instance or per-API | Remove entity hooks |
| POST | `/mgmt/v1/apis/{apiId}:validate` | Instance or per-API | Preflight checks (dry-run; optional before activate) |
| POST | `/mgmt/v1/apis/{apiId}:activate` | Instance or per-API | Activate data API (validates inline) |
| POST | `/mgmt/v1/apis/{apiId}:deactivate` | Instance or per-API | Deactivate data API |

Colon actions (`:validate`, `:activate`, `connection:test`, `schema:sync`, `schema:introspect`) are literal path segments.

---

## On-disk configuration

Each API is stored under `configs_dir/{apiId}/` (see [`dbapiator.php`](../src/application/config/dbapiator.php)):

| File | Management resource |
|------|---------------------|
| `meta.php` | API metadata (`id`, `name`, timestamps, …) |
| `status.php` | `draft` \| `active` \| `inactive` |
| `connection.php` | Connection (mysqli-style internally) |
| `structure.php` | Effective schema (Data API) |
| `patch.php` | Schema overrides |
| `admin_config.php` | Per-API secret + config-network ACLs |
| `data_api_acls.php` | Data API IP + path rules |
| `authentication.php` | Data API auth (`mode`, `loginMethods` or legacy `loginQuery`, JWT key, …) |
| `api_clients.php` | API clients (public token, secret hash, static JWT claims). Absent until the first client is created |

Hooks are stored **inside** `structure.php` per entity (`hooks` key on each resource).

---

## Auth policy (`policies/auth`)

| `mode` | Behavior |
|--------|----------|
| `none` | No JWT; `default_access_rule: public` on disk |
| `dbAuth` | Named login methods (SQL) → JWT; `default_access_rule` usually `private` |

| Field | Meaning |
|-------|---------|
| `default_access_rule` | `public` — anonymous GET on tables marked `access: public`; `private` — JWT required by default |
| `filterBypassRoles` | JWT `role` values that skip `mandatoryFilter` / `mandatoryAssign` (e.g. `["admin"]`) |

Legacy `allowGuest` is still read: `true` → `public`, `false` → `private`.

### Single login method (legacy shape)

Request body accepts nested `dbAuth` or flat `login` / `loginQuery`. Placeholders use `[[field]]` syntax (replaced from form POST fields):

```json
{
  "mode": "dbAuth",
  "dbAuth": {
    "login": {
      "sql": "SELECT username AS unm, role FROM app_users WHERE username='[[login]]' AND password='[[password]]'"
    },
    "validity": 3600
  }
}
```

On disk this becomes `loginQuery` plus default `validity`. At runtime it is exposed as login method **`password`**.

### Multiple login methods (preferred)

Define named methods under `dbAuth.loginMethods`. Each method has a `sql` (or `loginQuery`) and optional per-method `validity` and explicit `fields` (otherwise inferred from `[[placeholder]]` names in the SQL):

```json
{
  "mode": "dbAuth",
  "dbAuth": {
    "validity": 3600,
    "loginMethods": {
      "password": {
        "sql": "SELECT username AS unm, role FROM app_users WHERE username='[[login]]' AND password='[[password]]'"
      },
      "pin": {
        "sql": "SELECT username AS unm, role FROM app_users WHERE pin='[[pin]]'",
        "validity": 900
      }
    }
  }
}
```

On disk (`authentication.php`): `mode`, `default_access_rule`, optional `filterBypassRoles`, `validity`, optional `refresh_validity`, `loginMethods` (or legacy `loginQuery`), `jwt_key` (masked on GET). Optional per-method `refresh_validity` (use `0` to disable refresh for that method).

---

## Data plane access control

Layers (evaluated in order):

1. **IP ACL** (`data_api_acls.php` → `IP`)
2. **Path rules** (`path`) — first match wins; optional `when: { claim: value }` on JWT claims; evaluated **before** rejecting missing JWT
3. **Table access** (`structure.php`) — only when `path` is **empty**; per-entity `access` (`public` \| `private` \| `scoped`) inherits `default_access_rule`
4. **`mandatoryFilter`** — server-side filter AST on GET / bulk PATCH / DELETE / by-id PATCH / DELETE (overrides client filter on same fields)
5. **`mandatoryAssign`** — on POST, overwrites body columns from JWT (e.g. `user_id: "{{userId}}"`)
6. **Schema ACL** — static `read` / `insert` / `update` / `delete` per table/field

### Path rule with role (`when`)

```json
{ "pattern": "/*", "methods": "*", "allow": true, "when": { "role": "admin" } },
{ "pattern": "/users/{{userId}}/*", "methods": "*", "allow": true }
```

### Table access (empty `path` array)

```yaml
# structure.php excerpts
products: { access: public }
users:    { access: scoped, scopePattern: "/users/{{userId}}" }
orders:
  access: private
  mandatoryFilter: "user_id={{userId}}"
  mandatoryAssign:
    user_id: "{{userId}}"
```

Anonymous write (e.g. registration): path rule without `when`, e.g. `{ "pattern": "/users", "methods": "POST", "allow": true }`; table stays `scoped` or `private`.

### Data plane login endpoints

After activate, clients use:

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/v1/apis/{apiId}/auth/login` (or `/v1/auth/login` in single-mode) | List configured methods: `{ "loginMethods": [{ "name", "fields", "expiresIn", "refreshExpiresIn"? }] }` |
| POST | `/v1/apis/{apiId}/auth/login/{loginMethod}` | Authenticate; body: `application/x-www-form-urlencoded` with the method's fields |
| POST | `/v1/apis/{apiId}/auth/refresh` | Rotate refresh token; body: `refresh_token=...` (`application/x-www-form-urlencoded`) |
| POST | `/v1/apis/{apiId}/auth/logout` | Revoke a refresh token; body: `refresh_token=...`; always **204** |
| GET | `/v1/apis/{apiId}/auth/session` | Validate Bearer access JWT; **204** if valid, **401** empty body if not |
| POST | `/v1/apis/{apiId}/auth/client` (or `/v1/auth/client` in single-mode) | Exchange API client `token` and `secret` (form body) for an access JWT. No refresh token. Unknown token, wrong secret, and disabled clients all return **401** |

### API clients

Machine clients are not rows in the application database. They live in `api_clients.php` and are managed with the Management API (`X-Management-Key` or `X-Api-Config-Key`):

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/mgmt/v1/apis/{apiId}/clients` | List token, claims, validity, enabled. No secret |
| POST | `/mgmt/v1/apis/{apiId}/clients` | Create. Body `{ "token", "claims", "validity"? }`. Response includes `secret` once |
| PATCH | `/mgmt/v1/apis/{apiId}/clients/{token}` | Replace `claims`, set `validity`, or set `enabled` |
| POST | `/mgmt/v1/apis/{apiId}/clients/{token}:rotate` | New secret, returned once |
| DELETE | `/mgmt/v1/apis/{apiId}/clients/{token}` | Remove the client |

Single-mode uses `/mgmt/v1/clients` with the same suffixes. `claims` are scalar JWT claims (`role`, `company_id`, …). The server sets `client` to the token and `login_method` to `client`. Access JWT lifetime defaults to 900 seconds (60–86400). Issuing a JWT requires auth mode `dbAuth` and a `jwt_key`. Path rules and `mandatoryFilter` apply to these claims the same way as to a user login.

`POST .../auth/login` without `{loginMethod}` returns **404**. Unknown method → **404**; bad/missing fields → **400**; failed auth → **404**.

When `refresh_validity` is set (> 0) on the auth policy (or a login method), login also returns `refresh_token` and `refresh_expires_in`. `POST .../auth/refresh` consumes that token (rotation: the old token is invalid after a successful refresh) and returns a new pair. Missing `refresh_token` → **400**; invalid/expired/reused → **401** empty body.

Refresh tokens are opaque, stored hashed in table `dbapi_refresh_tokens` (created on first issue; skipped during schema introspect so it is not a data-plane resource).

---

## Network policies

**config-network** — Who may call Management operations for this API (stored in `admin_config.php`).

**data-network** — IP rules for clients calling the Data API (`data_api_acls.php` → `IP`). API shape: `{ "defaultAction": "allow"|"deny", "rules": [ { "cidr", "action" } ] }` (stored as `ip` on disk).

Default on draft create: allow caller IP, deny `0.0.0.0/0` for config and data IP ACLs; data **path** rules allow `GET`/`OPTIONS` and deny other methods. On **activate**, path rules are written only if `path` was empty. Override IP rules with `PUT .../policies/data-network` or `config-network`.

---

## Hooks

Webhooks are configured per **entity** (table/view name from schema):

```json
{
  "create": [
    { "url": "https://example.com/hooks/order", "method": "POST", "headers": {}, "body": null }
  ],
  "update": [],
  "delete": []
}
```

Delivery uses Redis Streams when `REDIS_HOST` is configured.

---

## Server configuration

Environment variables (optional):

| Variable | Purpose |
|----------|---------|
| `CONFIG_API_SECRET` | Instance `X-Management-Key` value |
| `CONFIG_API_IPS_ACLS` | JSON array of IP ACL rules for instance |
| `CONFIGS_DIR` | API config root directory |
| `API_TITLE`, `API_DESCRIPTION`, `API_VERSION` | Single-mode default API metadata (optional) |
| `API_CONTACT_*`, `API_LICENSE_*`, `API_TERMS_OF_SERVICE` | Single-mode contact/license metadata (optional) |
| `REDIS_HOST`, `REDIS_PORT`, … | Hooks / streams |

---

## Testing

**Test database:** load [`src/tests/dbapi_test.sql`](../src/tests/dbapi_test.sql), then use [`src/tests/connection.json`](../src/tests/connection.json).

**Automated tests (PHPUnit + Guzzle):**

```bash
cd src && composer install
./vendor/bin/phpunit tests/TestManagementAPI.php
```

Config: copy [`src/tests/test.env.example`](../src/tests/test.env.example) to `src/tests/test.env` (loaded by `tests/bootstrap.php`).

Detailed scenarios: [management_api_test_plan.md](management_api_test_plan.md).

**Migrate existing APIs** (add `meta.php` / `status.php`):

```bash
php scripts/migrate_management_api.php /path/to/configs_dir
```

---

## Related documentation

- [Using the Data API](using_the_api.md)
- [Management API test plan](management_api_test_plan.md)
- [README — Getting started](../src/README.md)
