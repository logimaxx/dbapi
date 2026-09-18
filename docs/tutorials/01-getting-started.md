# Tutorial 1 — Getting started

Run dbAPI locally, confirm it is healthy, and fetch your first records.

**Time:** ~10 minutes  
**In a hurry?** Use the [5-minute API guide](../five_minute_api.md) instead.  
**Next:** [Data plane](02-data-plane.md)

---

## What you are building toward

dbAPI turns a MySQL or MariaDB schema into a [JSON:API](https://jsonapi.org/) REST layer. Tables become **resources**; foreign keys become **relationships**.

| Plane | Who uses it | Example path |
|-------|-------------|--------------|
| **Data plane** | Your app, scripts, integrations | `/v1/data/customers` |
| **Control plane** (Management API) | Operators, CI/CD | `/mgmt/v1/apis/default` |

This tutorial focuses on the **data plane** — reading rows in the demo database.

---

## Step 1 — Start the dev stack

From the repository root:

```bash
docker compose up -d
```

Wait until the container is healthy:

```bash
curl -sS http://localhost:8888/health
curl -sS http://localhost:8888/
```

`/health` returns `{"status":"ok","service":"dbAPI"}`. The root URL returns path hints for management, data, auth, and swagger. The local stack runs in **single** mode: one API (`default`), auto-provisioned from `DB_*` env vars.

---

## Step 2 — Explore with Swagger UI

```text
http://localhost:8888/swagger.html?url=v1/swagger
```

Browse **customers**, **orders**, **products**, and **order_lines** — these map to tables in `myapp`.

```bash
curl -sS http://localhost:8888/v1/swagger | jq '.paths | keys[:5]'
```

Always check OpenAPI before guessing field or relationship names.

---

## Step 3 — List and fetch

```bash
curl -sS 'http://localhost:8888/v1/data/customers?page[limit]=3' | jq .
curl -sS http://localhost:8888/v1/data/customers/1 | jq .
```

Key shape:

- **`type`** — table name
- **`id`** — primary key as a string
- **`attributes`** — column values
- **`relationships`** — FK / child links
- **`meta.total`** — matching row count (before pagination)

Missing id → JSON:API error with `errors[]` and usually **404**.

---

## Step 4 — Request correlation

Every response includes **`X-Request-Id`**. Send your own for log correlation:

```bash
curl -sS -H 'X-Request-Id: tutorial-01-demo' \
  http://localhost:8888/v1/data/customers/1 -D - -o /dev/null | grep -i x-request-id
```

---

## Step 5 — Peek at Management API (optional)

In single-mode Docker the API id is always **`default`**:

```bash
curl -sS http://localhost:8888/mgmt/v1/apis/default \
  -H 'X-Management-Key: myverysecuresecret' | jq '{ name, status, connection: .connection.configured }'
```

You should see `"status": "active"`. If the data plane returns **409 API not active**, activate first — see [Operate](03-operate.md).

Do not expose the management key in browser-side code.

---

## What you learned

- Tables are JSON:API resources at `/v1/data/{resource}`.
- OpenAPI at `/v1/swagger` is the contract for names.
- Management API configures whether the data plane is live.

---

## Next step

[Data plane](02-data-plane.md) — filters, relationships, writes, bulk, and CSV.
