# dbAPI

Turn a MySQL or MariaDB schema into a [JSON:API](https://jsonapi.org/) REST layer — without writing CRUD for every table.

Introspect → configure policies → **activate**. Filtering, relationships, field-level ACLs, JWT auth, generated OpenAPI. One install can host many independent APIs (`apiId` per database).

**Current release:** `1.5.2` · **License:** Apache-2.0 · **Image:** [`ghcr.io/dbapiator/dbapi`](https://github.com/dbAPIator/dbapi/pkgs/container/dbapi)

**Note on history:** This project started years ago as a single-developer tool for personal use. Early commit messages are sparse; more recent history is clearer.

---

## Try it

**Local (bundled MariaDB):**

```bash
git clone https://github.com/dbAPIator/dbapi.git
cd dbapi && docker compose up -d
curl -sS http://localhost:8888/health
curl -sS 'http://localhost:8888/v1/data/customers?page[limit]=1'
```

**Against your own MySQL/MariaDB:**

```bash
docker run -d --name dbapi -p 8888:80 \
  -e DEPLOYMENT_MODE=single \
  -e CONFIGS_DIR=/app/apis \
  -e CONFIG_API_SECRET='change-me' \
  -e DB_HOST=mysql.example.com \
  -e DB_NAME=myapp \
  -e DB_USER=dbapi \
  -e DB_PASSWORD='secret' \
  -v dbapi-configs:/app/apis \
  ghcr.io/dbapiator/dbapi:1.5.2
```

Example response:

```http
GET /v1/data/customers/42
```

```json
{
  "data": {
    "type": "customers",
    "id": "42",
    "attributes": { "name": "Acme", "status": "active" },
    "relationships": {
      "orders": { "links": { "related": "/v1/data/customers/42/orders" } }
    }
  }
}
```

More: [5-minute guide](docs/five_minute_api.md) · [Docker deployment](docs/docker_deployment.md) · [Tutorials](docs/tutorials/README.md)

| Goal | Where to start |
|------|----------------|
| Develop from source | [Quick start — Docker](#docker) |
| Build a client | [Using the API](docs/using_the_api.md) · [AI guide](docs/ai_dbapi_guide.md) |
| Provision / operate | [Management API](docs/management_api.md) |
| Cut a release | [Releasing](docs/releasing.md) |

```text
  Apps & integrations                dbAPI                         Your database
 ┌──────────────────┐    ┌─────────────────────────────┐    ┌─────────────────┐
 │ SPA, mobile,     │    │ Control plane  /mgmt/v1     │    │ MySQL / MariaDB │
 │ scripts, BI      │───▶│  create · configure ·       │───▶│ tables · views  │
 │ partner APIs     │    │  validate · activate        │    │ FKs · procedures│
 └──────────────────┘    │ Data plane  /v1/apis/…/data │    └─────────────────┘
                         │  JSON:API CRUD + policies   │              ▲
                         └──────────────┬──────────────┘              │
                                        │ per-API config              │
                                        ▼ (introspection)             │
                         ┌──────────────────────────────┐             │
                         │ dbconfigs/{apiId}/           │─────────────┘
                         │ structure · policies · hooks │
                         └──────────────────────────────┘
```

---

## Who it is for

dbAPI is for **teams that already build on MySQL or MariaDB** and need a governed HTTP API without maintaining CRUD code for every table. It works especially well when important rules live in the database (triggers, stored procedures, views) and clients need a stable, documented REST surface.

| Audience | Typical need |
|----------|----------------|
| **Developers & founders** | Ship internal tools, admin panels, or small line-of-business apps quickly; keep transactional logic in SQL instead of duplicating it in application controllers. |
| **Agencies & consultancies** | Deliver repeatable JSON:API + OpenAPI projects on client-owned databases, with per-project policies and docs. |
| **IT & operations teams** | Modernize access to an existing MySQL estate (self-hosted or on-prem) without adopting a full vendor ERP or a cloud-only BaaS. |
| **Products with many databases** | Host several independent APIs (`apiId`) on one installation — dev/staging/prod, multiple products, or per-tenant schemas. |

**Less suited if you need:** a hosted Postgres platform with built-in auth and realtime (Supabase-style), GraphQL as the primary protocol, or a framework where all business logic stays in application code only.

**Supported databases:** MySQL and MariaDB (mysqli). Other engines are not supported yet.

### Compared to similar tools

dbAPI sits in the same broad category as schema-driven HTTP layers (PostgREST, Hasura, Directus): expose a database without hand-writing CRUD. The differences that usually decide the choice:

| | **dbAPI** | **PostgREST** | **Hasura** | **Directus** |
|---|-----------|---------------|------------|--------------|
| **Database** | MySQL / MariaDB | PostgreSQL | PostgreSQL (primary) | Many (incl. MySQL) |
| **API style** | [JSON:API](https://jsonapi.org/) REST + OpenAPI | REST / RPC | GraphQL (+ REST options) | REST (+ GraphQL) |
| **Control plane** | Management API: draft → validate → activate | Config / DB roles | Metadata + console | Admin app + API |
| **Multi-API on one host** | First-class (`apiId` per DB) | Typically one schema service | One project / metadata set | One project |
| **Field-level access** | Per-table / per-field ACLs | DB roles + grants | Permissions engine | Roles + permissions |
| **Admin / CMS UI** | No (API + Swagger) | No | Console (dev/ops) | Yes (core product) |
| **Best fit** | Governed MySQL JSON:API, ops lifecycle | Postgres-native auto-REST | Realtime GraphQL on Postgres | Content/ops UI over SQL |

**Choose dbAPI when** you are on MySQL or MariaDB, want JSON:API clients and generated OpenAPI, and need several independent APIs (or draft/activate rollout) on one installation.

**Choose PostgREST when** the database is PostgreSQL and you want a thin, battle-tested REST/RPC layer close to SQL and DB roles.

**Choose Hasura when** GraphQL (and often realtime subscriptions) is the primary client contract on Postgres.

**Choose Directus when** you need a full admin/CMS UI and a broader “data platform” experience, not only an HTTP data plane.

These tools overlap; none is a strict superset. dbAPI is intentionally **not** a BaaS (hosted auth, realtime, managed DB) and **not** a low-code admin UI.

---

## Use cases

### REST layer over an existing schema

Point dbAPI at a database, introspect, set policies, activate — then consume tables and views as [JSON:API](https://jsonapi.org/) resources with filtering, relationships, field-level permissions, and JWT auth. Schema changes go through rebuild; clients stay aligned via generated OpenAPI.

**Consumers:** SPAs, mobile apps, partner integrations, scripts, reporting tools.

### Line-of-business and workflow applications

Back-office systems where the database is the system of record: ERP-style modules, approval flows, document lifecycle, inventory, or membership admin. dbAPI provides HTTP and documentation; invariants and workflows can stay in SQL.

### Extra API surface alongside an existing app

Add governed read/write access for integrations or new UIs without rewriting your monolith — same database, separate `apiId`, independent network and auth policies.

### Controlled rollout via the Management API

Create APIs in `draft`, test connections, introspect and rebuild schema, configure policies, run validation, then **activate** when ready. Deactivate without losing config. Fits operators and CI/CD pipelines that should not expose data endpoints until checks pass.

### Multi-API on one server

Run dev, staging, and production APIs — or separate products and tenants — as distinct `apiId` directories under `dbconfigs/`, each with its own connection, policies, and OpenAPI spec.

dbAPI is a **standalone HTTP service** (not embedded middleware). It is **not** a replacement for a full BaaS (auth platform, realtime, hosted database), a low-code admin UI, or a general microservices framework.

---

## Why dbAPI

Building a REST API on top of an existing database usually means endless boilerplate: one controller per table, hand-maintained OpenAPI, ad hoc filter syntax, and security bolted on later. Schema changes break clients or require another deploy.

dbAPI flips that model:

- **Schema is the source of truth** — introspect from `information_schema`, rebuild when the database evolves, preserve overrides in `patch.php`.
- **Policy before data** — APIs stay in `draft` until connection, schema, and policies pass validation; only then does the data plane go live.
- **Standards-shaped responses** — [JSON:API](https://jsonapi.org/) documents with relationships, sparse fieldsets, and consistent errors.
- **Operator-friendly control plane** — plain JSON Management API with OpenAPI validation, separate from consumer-facing data routes.

---

## Two planes, one product

| | Control plane | Data plane |
|---|---------------|------------|
| **Who** | Operators, CI/CD, admins | Applications and end users |
| **Path (multi-API)** | `/mgmt/v1/apis/...` | `/v1/apis/{apiId}/data/...` |
| **Path (single-mode Docker)** | `/mgmt/v1/apis/default` | `/v1/data/...` |
| **Format** | Plain JSON | JSON:API |
| **Purpose** | Define *whether* and *how* an API exists | Read and write *rows* |

**Control plane** — Create an API (`apiId`), attach a connection, introspect and rebuild schema, set network and auth policies, register webhooks, run preflight checks, then **activate**. Until activation, data routes do not serve traffic. Deactivate or delete without losing your override files (unless you remove the config directory).

**Data plane** — Once `active`, clients call familiar REST shapes: list and fetch resources, create and update records, traverse foreign keys, filter and paginate. Legacy paths under `/apis/...` still work; new integrations should use `/v1/apis/...`.

Full references: [Management API](docs/management_api.md) · [Using the data API](docs/using_the_api.md)

---

## Data plane — what you can do

### Automatic API surface

Every exposed table or view becomes a **resource type**. Foreign keys become **relationships** with stable names (preserved across schema rebuilds unless you override them). Each active API gets a cached **`openapi.json`** and works with the bundled **Swagger UI**.

```http
GET  /v1/apis/{apiId}/data/customers
GET  /v1/apis/{apiId}/data/customers/42
GET  /v1/apis/{apiId}/data/customers/42/orders
POST /v1/apis/{apiId}/data/customers
```

### Read with power

- **Filtering** — compact expression language (`=`, `>`, `>=`, contains, one-of, AND/OR, parentheses) compiled to safe SQL.
- **Sort** — `sort=name,-created_at` (per-resource when using `include`).
- **Pagination** — `page[offset]` and `page[limit]` with instance-level caps.
- **Sparse fieldsets** — `fields[customers]=name,email` to trim payloads.
- **Includes** — load related records in one round trip (`include=orders,account_manager_id`), depth-limited.
- **Filter on relationships** — e.g. customers that have orders matching a condition.
- **Views** — read-only resources; list and filter work; responses omit `id` when there is no primary key.
- **Export** — optional CSV or XLS on reads for spreadsheets and reporting.
- **CSV import** — `POST` collection with `Content-Type: text/csv` (or multipart file) maps header columns to insertable attributes; same bulk limits and transactions as JSON create.

### Write with control

- **Create / update / delete** — single records or bulk operations where supported.
- **Nested creates** — attach related records in one JSON:API payload (configurable recursion depth).
- **Duplicate handling** — `onduplicate=error|ignore|update` with field lists for upsert-style flows.
- **Filter-based updates and deletes** — bulk changes on matching rows (with guardrails).

### Security on every request

- **IP ACLs** for data endpoints (and separate rules for management/config traffic).
- **Path-based authorization** — restrict which URLs and HTTP methods callers may use.
- **JWT authentication** — issue tokens after a configurable SQL login query against the same database; optional guest/read modes; optional rotating **refresh tokens** (`POST .../auth/refresh`, `POST .../auth/logout`).
- **Per-table and per-field ACLs** — control read, insert, update, delete, sort, and search per column.
- **Inactive APIs return 409** — configuration can continue while data traffic is blocked.
- **Liveness** — unauthenticated `GET /health` for orchestrators (Docker `HEALTHCHECK` uses it).

### Safety guardrails

Defaults are tunable via environment variables and `dbapiator.php` (and per-API query timeouts in `connection.php`):

| Guardrail | Typical default |
|-----------|-----------------|
| Page size | 100 default, 1000 max |
| Filter expression size / AST complexity | 4096 chars, depth 20, 100 nodes |
| Bulk insert / update per request | 100 / 50 records |
| Request and query timeout | 60 seconds |
| Nested `include` depth | 5 levels |

Every response can carry **`X-Request-Id`** for correlation; errors include `meta.request_id` when applicable.

---

## Control plane — how operators work

Typical activation flow:

```text
POST /mgmt/v1/apis                    → draft (save per-API credential once)
PUT  .../connection                   → database credentials
POST .../connection:test              → verify connectivity
POST .../schema:introspect            → snapshot from information_schema
POST .../schema:rebuild               → structure.php + openapi.json
PUT  .../policies/auth                → none, or dbAuth + JWT
PUT  .../policies/data-network        → IP rules for data clients
POST ...:validate                     → readiness report (required checks)
POST ...:activate                     → data plane live
```

**Management capabilities include:**

- **API lifecycle** — draft, active, inactive; clone, rename, delete (`force` when still active).
- **Schema tools** — introspect, effective schema view, overrides via `patch.php`, rebuild with warnings.
- **Policies** — config-network (who may manage this API), data-network (who may call data routes), auth (mode and login query).
- **Hooks** — per-entity webhook targets for create/update/delete (delivered via **Redis Streams** when Redis is configured).
- **Credential rotation** — per-API config keys without recreating the API.
- **Quick-create** — `POST /mgmt/v1/apis?provision=immediate` with connection in one step when appropriate.

**Authentication:** instance key (`X-Management-Key`) for full admin access; per-API key (`X-Api-Config-Key`) scoped to one API’s configuration directory. Request bodies are validated against the Management OpenAPI spec.

---

## Integration and extension

- **Webhooks** — publish write events to Redis Streams for async dispatchers or downstream pipelines.
- **Schema overrides** — rename resources, hide tables, tune relationship names without forking generated structure by hand.
- **OpenAPI everywhere** — management specs at `src/public/management-openapi-multi.yaml` and `management-openapi-single.yaml` (served as `/management-openapi.yaml` for the active mode); per-API spec generated on rebuild and served from disk (no regeneration per request).
- **Multi-API hosting** — dev, staging, and tenant-specific APIs as separate `apiId` directories under `dbconfigs/`.

---

## Quick start

### Docker

#### Production (GHCR image)

Same minimal `docker run` as in [Try it](#try-it). Full **`docker run`**, Compose stacks, environment variables, upgrades, and troubleshooting: **[Docker deployment guide](docs/docker_deployment.md)**.

```bash
docker pull ghcr.io/dbapiator/dbapi:1.5.2
```

#### Local development (from source)

Clone the repo and start the dev stack (live code mounts, bundled MariaDB):

```bash
docker compose up -d
```

| Service | URL |
|---------|-----|
| dbAPI | http://localhost:8888/ |
| MariaDB | `localhost:3308` (database `myapp`) |

For optional webhooks (Redis Streams + dispatcher), use [`docker/base/`](docker/base/).

Local Compose uses **single deployment mode** (`DEPLOYMENT_MODE=single`). On first start the container waits for MySQL, auto-provisions the `default` API from `DB_*` environment variables, and serves data at `/v1/data/...`.

MariaDB is seeded with the full data-plane test schema on **first** database init (`docker/mysql-init/`). To re-run the seed, remove the MySQL data volume first: `rm -rf .docker_data/mysql && docker compose up -d`.

```bash
# Liveness
curl -sS http://localhost:8888/health

# Service discovery
curl -sS http://localhost:8888/

# Data plane
curl -sS http://localhost:8888/v1/data/customers

# OpenAPI spec + Swagger UI
curl -sS http://localhost:8888/v1/swagger
open 'http://localhost:8888/swagger.html?url=v1/swagger'
```

Management API (configure policies, rebuild schema, etc.) uses the fixed id **`default`**:

```bash
curl -sS http://localhost:8888/mgmt/v1/apis/default \
  -H 'X-Management-Key: myverysecuresecret'
```

Instance secret: `CONFIG_API_SECRET` in `docker-compose.yml` (default `myverysecuresecret`) → header **`X-Management-Key`**.

For **multi-API hosting** (dev/staging/prod on one install), omit `DEPLOYMENT_MODE=single` and use the standard flow:

```bash
curl -sS -X POST 'http://localhost:8888/mgmt/v1/apis?provision=immediate' \
  -H 'Content-Type: application/json' \
  -H 'X-Management-Key: myverysecuresecret' \
  -d '{"name":"demo","connection":{"driver":"mysql","host":"mysql","port":3306,"database":"myapp","username":"user","password":"password"}}'
```

Data endpoints: `http://localhost:8888/v1/apis/demo/data/{resource}`

### Local Apache / PHP

**Requirements:** PHP 8.3+, `mysqli`, `json`, `mbstring`, `curl`, `yaml`.

1. Point the web root at [`src/`](src/) (e.g. `http://localhost/dbapi/src`).
2. Ensure [`dbconfigs/`](dbconfigs/) is writable by the web server user.
3. Configure [`src/application/config/dbapiator.php`](src/application/config/dbapiator.php) — `configs_dir`, secrets, paging limits (or use env vars).

**Swagger UI:** `{base}/swagger.html?url=management-openapi.yaml` · per-API: `{base}/swagger.html?url=apis/{apiId}/swagger`

---

## Repository layout

| Path | Purpose |
|------|---------|
| [`src/`](src/) | PHP application (document root) |
| [`dbconfigs/`](dbconfigs/) | Generated per-API configuration |
| [`docs/`](docs/) | Guides ([Docker](docs/docker_deployment.md), [Management API](docs/management_api.md), [Releasing](docs/releasing.md)) |
| [`Dockerfile`](Dockerfile) | Production container image |
| [`docker-compose.yml`](docker-compose.yml) | Local dev stack (build from source) |
| [`docker/base/`](docker/base/) | Copy-paste Compose starter for consumer projects |
| [`.github/workflows/`](.github/workflows/) | CI — tests, security audit, Docker publish |
| [`scripts/release.sh`](scripts/release.sh) | Version bump, changelog, git tag |
| [`tests/`](tests/) | Schemathesis harness (optional) |

Application entry point: [`src/index.php`](src/index.php).

---

## Tests

Integration tests (PHPUnit + Guzzle) run against a live instance:

```bash
# Unit tests only (fast, no server/DB)
cd src && composer install && composer test:unit

# Multi-API mode (local Apache)
composer test:dataplane-setup   # from src/ — loads DB + copies test env
cd src && composer test:integration -- --filter TestDataPlaneAPI

# Single-mode Docker
docker compose up -d
cp src/tests/test.env.single.example src/tests/test.env
cd src && ./vendor/bin/phpunit tests/TestSingleModeDataPlaneAPI.php
```

| Suite | Focus |
|-------|--------|
| `composer test:unit` | Filter parser, OpenAPI, schema sync, health, helpers (~69 tests) |
| `TestManagementAPI` | Control plane lifecycle |
| `TestDataPlaneAPI` | Full data-plane coverage (multi-API, ~75 tests) |
| `TestSingleModeDataPlaneAPI` | Same scenarios via Docker `/v1/data/...` |

Details: [management_api_test_plan.md](docs/management_api_test_plan.md) · [data_plane_test_plan.md](docs/data_plane_test_plan.md)

---

## CI and security

GitHub Actions run on push/PR to `master` and on release tags:

| Workflow | Trigger | What it does |
|----------|---------|--------------|
| [Tests](.github/workflows/tests.yml) | Push, PR | PHPUnit unit tests; integration tests with MariaDB |
| [Security](.github/workflows/security.yml) | Push, PR, weekly | `composer audit` for dependency CVEs |
| [Publish Docker image](.github/workflows/docker-publish.yml) | Tag `v*.*.*` | Build, push to `ghcr.io/dbapiator/dbapi`, Trivy container scan |

[Dependabot](.github/dependabot.yml) opens weekly update PRs for Composer, GitHub Actions, and the Docker base image.

---

## Documentation

- [5-minute API guide](docs/five_minute_api.md) — compose up, list, filter, create (also on [dbapi.logimaxx.eu/guide.html](https://dbapi.logimaxx.eu/guide.html))
- [Tutorials](docs/tutorials/README.md) — hands-on path (getting started → data plane → operate)
- [Docker deployment guide](docs/docker_deployment.md) — GHCR image, `docker run`, Compose, env vars (also on [dbapi.logimaxx.eu/deploy.html](https://dbapi.logimaxx.eu/deploy.html))
- [Management API](docs/management_api.md) — control plane reference
- [Using the API](docs/using_the_api.md) — filters, pagination, relationships, writes
- [AI integration guide](docs/ai_dbapi_guide.md) — copy into consumer projects for Cursor / agents
- [OpenAPI pipeline](docs/openapi_pipeline.md) — how specs are generated and validated
- [Changelog](CHANGELOG.md) · [Releasing](docs/releasing.md) — version history, tags, Docker publish

---

## Authors

Developed by [Sergiu Voicu](https://github.com/logimaxx), co-founder of [LogiMaxx Systems](https://logimaxx.ro).

- Website: [dbapi.logimaxx.eu](https://dbapi.logimaxx.eu)
- Contact: [sergiu@logimaxx.ro](mailto:sergiu@logimaxx.ro)

---

## License

Apache-2.0 — see [LICENSE.md](LICENSE.md).
