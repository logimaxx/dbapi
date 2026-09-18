# dbAPI tutorials

Three hands-on guides from first request through production-style operations.

**In a hurry?** Start with the [5-minute API guide](../five_minute_api.md).

**Prerequisites:** basic HTTP/REST familiarity and a working MySQL or MariaDB database (or the bundled dev stack below).

## Learning path

| # | Tutorial | What you will learn |
|---|----------|---------------------|
| — | [5-minute API guide](../five_minute_api.md) | Compose up, list/filter/create in one sitting |
| 1 | [Getting started](01-getting-started.md) | Run locally, discover endpoints, list resources |
| 2 | [Data plane](02-data-plane.md) | Filters, relationships, writes, bulk, CSV |
| 3 | [Operate](03-operate.md) | Auth, provisioning, security policies |

## Recommended environment

```bash
docker compose up -d
```

| Item | Value |
|------|-------|
| Base URL | `http://localhost:8888` |
| Deployment mode | single (`/v1/data/...`) |
| Management API id | `default` |
| Instance secret | `myverysecuresecret` (see `docker-compose.yml`) |
| Demo database | `myapp` — seeded with customers, orders, products, and more |

```bash
curl -sS http://localhost:8888/ | jq .
open 'http://localhost:8888/swagger.html?url=v1/swagger'
```

## URL conventions

| Mode | Data plane prefix | Auth prefix |
|------|-------------------|-------------|
| **Single** (Docker dev) | `/v1/data/{resource}` | `/v1/auth/...` |
| **Multi-API** | `/v1/apis/{apiId}/data/{resource}` | `/v1/apis/{apiId}/auth/...` |

Tutorials 1–2 use **single-mode** paths. Tutorial 3 also shows multi-API equivalents.

## Reference documentation

- [Using the API](../using_the_api.md) — data plane details
- [Management API](../management_api.md) — control plane reference
- [Docker deployment](../docker_deployment.md) — production containers
- [AI integration guide](../ai_dbapi_guide.md) — copy into consumer projects
