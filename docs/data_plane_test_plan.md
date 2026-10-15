# Data plane — test plan

User guide: **[using_the_api.md](using_the_api.md)**  
Control plane setup: **[management_api_test_plan.md](management_api_test_plan.md)**

The data plane is the JSON:API surface at `/v1/apis/{apiId}/data/...` (multi-API) or `/v1/data/...` (single-mode Docker).

## Test database

Two equivalent entry points load the same shared schema body (`src/tests/dataplane-schema-body.sql`):

| Context | Entry point | Database |
|---------|-------------|----------|
| Local PHPUnit (multi-API) | `src/tests/dbapi_dataplane.sql` or `composer test:dataplane-setup` | `dbapi_dataplane` |
| Docker single-mode | `docker/mysql-init/001-load-demo-schema.sh` | `myapp` |

Load locally:

```bash
composer test:dataplane-setup
# or: mysql -u root -p < src/tests/dbapi_dataplane.sql  (from repository root)
# or: scripts/load-dataplane-schema-local.sh
cp src/tests/connection.dataplane.example.json src/tests/connection.json
cp src/tests/test.env.dataplane.example src/tests/test.env
```

Docker (full schema on first MySQL init):

```bash
docker compose up -d
# Re-seed after schema changes:
rm -rf .docker_data/mysql && docker compose up -d
```

### What the schema covers

| Area | Tables / objects | Used for |
|------|------------------|----------|
| Commerce + FKs | `customers`, `products`, `orders`, `order_lines` | CRUD, relationships, `include`, RESTRICT/CASCADE |
| Outbound FK | `users`, `customers.account_manager_id` | Outbound `include`, relationship URLs |
| Filter fixtures | `filter_cases` (fixed IDs 1–20) | `=`, `>`, `<=`, `=~`, `~=~`, `!<>`, `><`, `||`, `()`, `filter_advanced`, sort, pagination |
| Types | `catalog_items` (JSON, decimal, nullable dates) | Sparse fields, type coercion |
| Text / nulls | `notes` | Optional FK, commas in values, bulk delete by filter |
| Extra graph | `suppliers`, `shipments` | Additional inbound relations |
| Read-only | `v_order_totals_by_day` | View GET; POST must fail |
| Auth helpers | `app_users`, `v_app_login`, `sp_validate_login` | Stored procedure call test |

## Automated test suites

Shared scenarios live in `DataPlaneTestsTrait` (~55 tests):

| Suite | Base class | URL prefix | When to use |
|-------|------------|------------|-------------|
| `TestDataPlaneAPI` | `DataPlaneTestCase` | `/v1/apis/{ephemeralId}/data/` | Local Apache, multi-API mode |
| `TestSingleModeDataPlaneAPI` | `SingleModeDataPlaneTestCase` | `/v1/data/` | Docker (`docker compose up`) |

### Read

- List collection, get by id, unknown resource, missing id
- Sparse fieldset, read-only view
- JSON metadata on `catalog_items`

### Filters & sort

- Equality, greater-than, less-than-or-equal, one-of (`><`), OR grouping, negation
- Begins-with (`=~`), contains (`~=~`), AND combinations
- `filter_advanced` merged with `filter`
- Filter on relationships (`filter[customers/orders]=...`)
- Ascending, descending, and multi-field sort

### Pagination

- Per-resource and global `page[offset]` / `page[limit]`
- `meta.total`, second page, offset beyond total

### Relationships

- `include` inbound (orders) and outbound (account_manager_id)
- Related collection endpoint with pagination
- Create, update related records
- Nested create (customer + order)

### Write

- POST → PATCH → DELETE
- Bulk insert, bulk update by id array, bulk delete by filter
- `onduplicate=ignore` and `onduplicate=update` (`field`, `field:add`, `field:sub`; reject non-numeric, unknown operator, primary key)
- CSV import (`POST` `Content-Type: text/csv`): create, unknown column, bulk limit, onduplicate ignore, rollback on duplicate

### Export & procedures

- CSV export (`format=csv`)
- Stored procedure via `/data/__call__/{name}`

### Negative / constraints

- Duplicate unique, invalid FK, RESTRICT delete, empty body, POST to view
- Bulk PATCH without filter array — 400
- `X-Request-Id` correlation

## Run automated tests

**Multi-API (local):**

```bash
composer test:dataplane-setup
cd src && composer install && composer test:integration -- --filter TestDataPlaneAPI
```

**Single-mode (Docker):**

```bash
docker compose up -d
cp src/tests/test.env.single.example src/tests/test.env
cd src && ./vendor/bin/phpunit tests/TestSingleModeDataPlaneAPI.php
```

Full suite:

```bash
cd src && composer test
```

Quick unit tests (no database or HTTP server):

```bash
cd src && composer test:unit
```

`TestSingleModeDataPlaneAPI` auto-skips when the Docker stack is not running or the full schema is not loaded (< 20 `filter_cases` rows).

`TestDataPlaneAPI` auto-skips when the local `dbapi_dataplane` database is unreachable, stale, or missing required fixtures (same `filter_cases` / `account_manager_id` checks). Reload with `composer test:dataplane-setup`.

## Environment variables

| Variable | Default | Purpose |
|----------|---------|---------|
| `BASE_URL` | `http://localhost/dbapi/src/` | dbAPI base |
| `MGMT_KEY` | `myverysecuresecret` | `X-Management-Key` |
| `CONNECTION_JSON` | — | Inline connection JSON (overrides file) |

## Relationship to control-plane tests

| Suite | Database | Focus |
|-------|----------|---------|
| `TestManagementAPI` | `dbapi_test` | Lifecycle, policies, activate/deactivate |
| `TestDataPlaneAPI` | `dbapi_dataplane` | Full data-plane coverage (multi-API) |
| `TestSingleModeDataPlaneAPI` | `myapp` (Docker) | Same scenarios via `/v1/data/...` |
