# Docker deployment guide

Use the published dbAPI container image to run dbAPI without cloning the repository or building PHP locally. Images are built from the root [`Dockerfile`](../Dockerfile) and published to **GitHub Container Registry (GHCR)** on each release tag.

For local development with live code mounts and a bundled MariaDB stack, use [`docker-compose.yml`](../docker-compose.yml) in the repository instead.

For **consumer projects** (your app + database, published dbAPI image), copy [`docker/base/`](../docker/base/) into your repo, set `.env` from `.env.example`, add schema SQL under `mysql-init/`, and run `docker compose up -d`.

---

## Image location and tags

| Item | Value |
|------|--------|
| Registry | `ghcr.io` |
| Image | `ghcr.io/dbapiator/dbapi` |
| Package page | https://github.com/dbAPIator/dbapi/pkgs/container/dbapi |

Tags are created when a semver Git tag is pushed (for example `v1.0.0`):

| Tag | Meaning |
|-----|---------|
| `latest` | Most recent release |
| `1.0.0` | Exact version |
| `1.0` | Latest patch in `1.0.x` |
| `1` | Latest release in major version `1.x.x` |

Pin a version in production (`1.0.0`) rather than `latest`.

---

## Pull the image

### Public package

If the GHCR package visibility is **Public** (recommended for open source):

```bash
docker pull ghcr.io/dbapiator/dbapi:1.0.0
```

### Private package

If the package is private, authenticate first:

```bash
echo "$GITHUB_TOKEN" | docker login ghcr.io -u YOUR_GITHUB_USERNAME --password-stdin
docker pull ghcr.io/dbapiator/dbapi:1.0.0
```

Use a GitHub personal access token with the `read:packages` scope.

---

## Deployment modes

dbAPI supports two hosting modes. Choose one before configuring environment variables.

| Mode | `DEPLOYMENT_MODE` | Best for | Data plane URL | Management API id |
|------|-------------------|----------|----------------|-------------------|
| **Single-API** | `single` | One database, simple ops (typical Docker install) | `/v1/data/{resource}` | `default` (fixed) |
| **Multi-API** | `multi` (default) | Dev/staging/prod or many tenants on one instance | `/v1/apis/{apiId}/data/{resource}` | Per API |

### Single-API mode

On container start the entrypoint:

1. Waits up to 60 seconds for MySQL/MariaDB at `DB_HOST`:`DB_PORT` (continues if unreachable)
2. Scaffolds the **`default`** API in **draft** under `CONFIGS_DIR`
3. Pre-fills `connection.php` from `DB_*` env when `DB_HOST` and `DB_NAME` are set (even if the DB is not yet reachable)
4. When the database is reachable, runs connection test, schema build, and activation automatically

In **single** mode, `DB_*` and `CONFIG_API_SECRET` are also applied at request time. Regenerating `connection.php` / `admin_config.php` (plain `return array (...)`) does not drop Docker credentials.

No manual Management API call is required. Use `GET /mgmt/v1` to inspect status. OpenAPI spec: `/management-openapi-single.yaml` (also served at `/management-openapi.yaml` in single mode).

Optional metadata env vars: `API_TITLE`, `API_DESCRIPTION`, `API_VERSION`, `API_TERMS_OF_SERVICE`, `API_LICENSE_NAME`, `API_LICENSE_URL`, `API_CONTACT_NAME`, `API_CONTACT_EMAIL`, `API_CONTACT_URL`, `API_CONTACT_PHONE`.

### Multi-API mode

The container starts the web server only. You create each API through the Management API and store configs on a **persistent volume** mounted at `CONFIGS_DIR`.

```bash
curl -sS -X POST 'http://localhost:8888/mgmt/v1/apis?provision=immediate' \
  -H 'Content-Type: application/json' \
  -H 'X-Management-Key: YOUR_SECRET' \
  -d '{
    "name": "demo",
    "connection": {
      "driver": "mysql",
      "host": "mysql.example.com",
      "port": 3306,
      "database": "myapp",
      "username": "user",
      "password": "password"
    }
  }'
```

Data endpoints: `http://localhost:8888/v1/apis/demo/data/{resource}`

---

## Requirements

| Component | Required | Notes |
|-----------|----------|-------|
| MySQL or MariaDB | Yes | dbAPI connects via `mysqli` |
| Writable config directory | Yes | Mount a volume at `CONFIGS_DIR` |
| Redis | No | Only needed for webhook dispatch (`REDIS_*`) |

The container listens on **port 80** internally. Map it to a host port (for example `-p 8888:80`).

---

## Environment variables

### Core

| Variable | Required | Default | Description |
|----------|----------|---------|-------------|
| `CONFIGS_DIR` | Recommended | `/var/www/html/dbapi/dbconfigs` | Directory for per-API config files. Use `/app/apis` in Docker. |
| `CONFIG_API_SECRET` | Yes (production) | `myverysecuresecret` | Management API key → HTTP header **`X-Management-Key`** |
| `CONFIG_API_IPS_ACLS` | No | `[{"allow":true,"ip":"0.0.0.0/0"}]` | JSON array restricting Management API by IP |
| `DEPLOYMENT_MODE` | No | `multi` | Set to `single` for single-API auto-provision |

Single-mode always uses the fixed API id `default` (not configurable).

### Single-mode database connection

Required when `DEPLOYMENT_MODE=single`:

| Variable | Required | Default | Description |
|----------|----------|---------|-------------|
| `DB_HOST` | Yes | — | MySQL/MariaDB hostname |
| `DB_PORT` | No | `3306` | Database port |
| `DB_NAME` | Yes | — | Database name |
| `DB_USER` | Yes | — | Database user |
| `DB_PASSWORD` | No | `""` | Database password |

Optional metadata for the auto-provisioned API (written to `meta.php`):

| Variable | Description |
|----------|-------------|
| `API_TITLE` | Display title (OpenAPI `info.title`) |
| `API_DESCRIPTION` | Description |
| `API_VERSION` | Version label (default `1.0.0`) |
| `API_TERMS_OF_SERVICE` | Terms URL |
| `API_LICENSE_NAME`, `API_LICENSE_URL` | License block |
| `API_CONTACT_NAME`, `API_CONTACT_EMAIL`, `API_CONTACT_URL`, `API_CONTACT_PHONE` | Contact block |

### Pagination and limits

| Variable | Default |
|----------|---------|
| `DEFAULT_PAGE_SIZE` | `100` |
| `MAX_PAGE_SIZE` | `1000` |
| `DEFAULT_RELATIONSHIPS_PAGE_SIZE` | `10` |
| `REQUEST_TIMEOUT_SECONDS` | `60` |
| `MAX_INCLUDE_DEPTH` | `5` |

### Redis (webhooks, optional)

| Variable | Description |
|----------|-------------|
| `REDIS_HOST` | Redis hostname |
| `REDIS_PORT` | Default `6379` |
| `REDIS_PASSWORD` | If auth is enabled |
| `REDIS_STREAM` | Stream name (for example `dbapi_webhooks`) |
| `REDIS_GROUP` | Consumer group name |

---

## Single-API: `docker run`

Minimal example with an external database and a named volume for API configs:

```bash
docker run -d --name dbapi \
  -p 8888:80 \
  -e DEPLOYMENT_MODE=single \
  -e CONFIGS_DIR=/app/apis \
  -e CONFIG_API_SECRET='change-me-in-production' \
  -e DB_HOST=mysql.example.com \
  -e DB_PORT=3306 \
  -e DB_NAME=myapp \
  -e DB_USER=dbapi \
  -e DB_PASSWORD='secret' \
  -v dbapi-configs:/app/apis \
  ghcr.io/dbapiator/dbapi:1.0.0
```

Verify:

```bash
curl -sS http://localhost:8888/
curl -sS http://localhost:8888/v1/data/
curl -sS http://localhost:8888/mgmt/v1/apis/default \
  -H 'X-Management-Key: change-me-in-production'
```

OpenAPI and Swagger UI:

```bash
curl -sS http://localhost:8888/v1/swagger
# Browser: http://localhost:8888/swagger.html?url=v1/swagger
```

---

## Single-API: Docker Compose (production-style)

Use the copy-paste starter in [`docker/base/`](../docker/base/):

```bash
cp -r path/to/dbapi/docker/base ./docker/dbapi
cd docker/dbapi
cp .env.example .env   # set CONFIG_API_SECRET, DB credentials, DBAPI_IMAGE_TAG
# optional: add mysql-init/*.sql for your schema
docker compose up -d
```

The stack uses the published GHCR image for dbAPI, a locally built `webhooks-dispatcher` image (PHP CLI worker), named volumes for configs and data, MariaDB with `mysql-init/` hooks, Redis, and an optional Adminer UI (`docker compose --profile tools up -d`).

Replace `change-me-in-production`, database credentials, and the image tag before using in production.

---

## Multi-API: `docker run`

Omit `DEPLOYMENT_MODE=single` and mount a writable config volume:

```bash
docker run -d --name dbapi \
  -p 8888:80 \
  -e CONFIGS_DIR=/app/apis \
  -e CONFIG_API_SECRET='change-me-in-production' \
  -v dbapi-configs:/app/apis \
  ghcr.io/dbapiator/dbapi:1.0.0
```

Create APIs via the Management API (see [Management API](management_api.md)).

---

## Volumes and persistence

| Path in container | Purpose |
|-------------------|---------|
| `/app/apis` | Per-API configuration (`structure.php`, `connection.php`, policies, OpenAPI). **Must persist** across restarts. |

Application code and PHP dependencies are baked into the image. You do not need to mount `src/`.

Back up the config volume regularly. Deleting it removes API definitions (unless you can recreate them from the Management API and database introspection).

---

## Upgrades

1. Pin to semver tags (`1.0.0`), not `latest`, in production.
2. Read [CHANGELOG.md](../CHANGELOG.md) for breaking changes.
3. Pull the new tag and recreate the container:

```bash
docker pull ghcr.io/dbapiator/dbapi:1.0.1
docker stop dbapi && docker rm dbapi
# re-run docker run or docker compose up -d with the new tag
```

Existing config on the volume is preserved. Run Management API validation or schema rebuild if the release notes require it.

---

## Health check

`GET /health` is an unauthenticated liveness probe. It returns HTTP 200 and JSON `{"status":"ok","service":"dbAPI"}` when PHP-FPM and nginx can serve requests. It does not test database connectivity (use `POST .../connection:test` on the Management API for that).

The image `HEALTHCHECK` requests `http://127.0.0.1/health` inside the container. Orchestrators (Docker Compose, Kubernetes) can use the same path.

```bash
curl -fsS http://localhost:8888/health
```

Note: in **single mode**, the first start may take longer while waiting for MySQL and running auto-provision.

---

## Troubleshooting

| Symptom | Likely cause | What to check |
|---------|--------------|---------------|
| `denied` on `docker pull` | Private GHCR package | `docker login ghcr.io` or set package visibility to Public |
| Container exits on start | MySQL not reachable | `DB_HOST`, firewall, `depends_on` / network |
| `MySQL did not become ready` | DB slow to start or wrong host/port | Increase wait by starting MySQL first; verify credentials |
| 404 on `/v1/data/...` | API not active or wrong mode | Single mode: check `/mgmt/v1/apis/default`. Multi: create and activate API |
| 401 on Management API | Wrong secret | `CONFIG_API_SECRET` → header `X-Management-Key` |
| Empty config after restart | No volume on `/app/apis` | Mount a named volume or bind mount |

View logs:

```bash
docker logs -f dbapi
```

---

## Related documentation

- Website: [Guide — get started & deploy](https://dbapi.logimaxx.eu/guide.html)
- [README — Quick start](../README.md#quick-start)
- [Management API](management_api.md) — control plane
- [Using the API](using_the_api.md) — data plane (filters, relationships, writes)
- [AI integration guide](ai_dbapi_guide.md) — URLs and auth for consumer projects
