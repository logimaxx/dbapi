# 5-minute API guide

From zero to your first JSON:API responses with the local Docker stack.

**Time:** ~5 minutes  
**Needs:** Docker, this repository  
**Next:** [Tutorial 1 — Getting started](tutorials/01-getting-started.md) · [Using the API](using_the_api.md)

---

## 1. Clone the repository

```bash
git clone https://github.com/dbAPIator/dbapi.git
cd dbapi
```

---

## 2. Start dbAPI

From the repository root:

```bash
docker compose up -d
```

Wait until the container is healthy, then:

```bash
curl -sS http://localhost:8888/health
```

Expect `{"status":"ok","service":"dbAPI"}`. The stack runs in **single** mode: one API (`default`), data under `/v1/data/...`.

---

## 3. What the stack set up for you

`docker compose up -d` starts **dbAPI** and **MariaDB**. You do not create a database by hand for this guide.

| Piece | What happens |
|-------|----------------|
| **MariaDB** | Creates database `myapp` (user `user` / password `password`) |
| **Demo schema** | SQL under `docker/mysql-init/` loads tables such as `customers`, `orders`, `products`, `order_lines` |
| **dbAPI** | Reads `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASSWORD` from Compose, tests the connection, builds the schema, and **activates** the `default` API |

Optional: inspect the same DB from your host on port **3308** (`localhost:3308`, database `myapp`).

To point dbAPI at **your** MySQL/MariaDB instead of the demo stack, use `DB_*` on the published image — see [Docker deployment](docker_deployment.md).

---

## 4. List a resource

Tables in the demo database are API resources. List customers:

```bash
curl -sS 'http://localhost:8888/v1/data/customers?page[limit]=2' | jq .
```

You get a JSON:API document: `data[]` with `type`, `id`, `attributes` (columns), and `relationships` (foreign keys / children).

Fetch one row:

```bash
curl -sS http://localhost:8888/v1/data/customers/1 | jq .
```

---

## 5. Filter and include

```bash
curl -sS 'http://localhost:8888/v1/data/customers?filter=country_code=US&page[limit]=5' | jq .

curl -sS 'http://localhost:8888/v1/data/customers/1?include=orders' | jq .
```

Field and relationship names come from your schema — check OpenAPI before guessing:

```text
http://localhost:8888/swagger.html?url=v1/swagger
```

---

## 6. Create a record (optional)

```bash
curl -sS -X POST http://localhost:8888/v1/data/customers \
  -H 'Content-Type: application/vnd.api+json' \
  -d '{
    "data": {
      "type": "customers",
      "attributes": {
        "name": "Five Minute Co",
        "email": "five@example.com",
        "country_code": "RO"
      }
    }
  }' | jq .
```

The local stack starts with auth mode **`none`** so you can explore without a JWT. Production setups usually use `dbAuth` — see [Operate](tutorials/03-operate.md).

---

## Production image instead of Compose?

Use the published container and your own MySQL/MariaDB:

→ [Docker deployment guide](docker_deployment.md) · [On the website](https://dbapi.logimaxx.eu/guide.html#deploy)

---

## Where to go next

| Goal | Doc |
|------|-----|
| Deeper walkthrough | [Tutorials](tutorials/README.md) |
| Filters, writes, bulk | [Using the API](using_the_api.md) |
| Draft → activate | [Management API](management_api.md) |
| Wire an AI agent | [AI integration guide](ai_dbapi_guide.md) |
