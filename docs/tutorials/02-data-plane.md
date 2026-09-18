# Tutorial 2 — Data plane

Read, query, relate, and write records through the JSON:API data plane.

**Prerequisites:** [Getting started](01-getting-started.md)  
**Next:** [Operate](03-operate.md)  
**Reference:** [Using the API](../using_the_api.md)

---

## JSON:API write shape

Writes use `Content-Type: application/json` (or `application/vnd.api+json`) and wrap the payload in **`data`**:

```json
{
  "data": {
    "type": "resource_name",
    "attributes": { "column": "value" }
  }
}
```

Rules: **`type`** matches the table; on PATCH include **`id`** matching the URL; columns go in **`attributes`**; links use **`relationships`**.

### Create / update / delete

```bash
curl -sS -X POST http://localhost:8888/v1/data/notes \
  -H 'Content-Type: application/json' \
  -d '{
    "data": {
      "type": "notes",
      "attributes": {
        "customer_id": 1,
        "body": "Created from tutorial 2",
        "priority": 1
      }
    }
  }' | jq .
```

```bash
curl -sS -X PATCH http://localhost:8888/v1/data/notes/NOTE_ID \
  -H 'Content-Type: application/json' \
  -d '{
    "data": {
      "type": "notes",
      "id": "NOTE_ID",
      "attributes": { "body": "Updated", "priority": 2 }
    }
  }' | jq .
```

```bash
curl -sS -X DELETE http://localhost:8888/v1/data/notes/NOTE_ID -w '\nHTTP %{http_code}\n'
```

Unique violations (e.g. duplicate customer email) return **409**. Views without a PK are list/filter only — no GET-by-id or writes.

---

## Filtering, sort, pagination, fields

Use `filter[{resource}]` with a compact expression language.

| Operator | Meaning | Example |
|----------|---------|---------|
| `=` `!=` | equal / not | `status=open` |
| `>` `>=` `<` `<=` | comparisons | `score>=40` |
| `=~` `~=` `~=~` | starts / ends / contains | `note~=~page` |
| `><` | one of (`;`-separated) | `country><US;DE` |

`,` = AND, `||` = OR, `()` = grouping.

```bash
curl -sS -G 'http://localhost:8888/v1/data/products' \
  --data-urlencode 'filter[products]=is_active=1' \
  --data-urlencode 'sort[products]=name' \
  --data-urlencode 'fields[products]=name,sku,price' \
  --data-urlencode 'page[offset]=0' \
  --data-urlencode 'page[limit]=10' | jq .
```

Prefix **`-`** on sort fields for descending. Use **`meta.total`** for UI paging (typical max `page[limit]`: 1000).

---

## Relationships

| Direction | Default name | Example |
|-----------|--------------|---------|
| Outbound (FK on this table) | FK **column name** | `account_manager_id` on `customers` |
| Inbound (children) | Child **table name** | `orders` under `customers` |

```bash
# Identifiers only
curl -sS 'http://localhost:8888/v1/data/customers/1' | jq '.data.relationships'

# Full related rows
curl -sS -G 'http://localhost:8888/v1/data/customers/1' \
  --data-urlencode 'include=orders,account_manager_id' | jq .

# Relationship URL (same filter/sort/page params)
curl -sS -G 'http://localhost:8888/v1/data/customers/1/orders' \
  --data-urlencode 'filter[orders]=status=placed'

# Parents that have matching children
curl -sS -G 'http://localhost:8888/v1/data/customers' \
  --data-urlencode 'filter[customers/orders]=status=placed' | jq '.data[].attributes.name'
```

Nested includes are depth-limited (default max 5). Relationship names are preserved across schema rebuilds.

---

## Nested create, bulk, upsert

Parent + children in one POST:

```bash
curl -sS -X POST http://localhost:8888/v1/data/orders \
  -H 'Content-Type: application/json' \
  -d '{
    "data": {
      "type": "orders",
      "attributes": { "customer_id": 1, "status": "draft" },
      "relationships": {
        "order_lines": {
          "data": [
            {
              "type": "order_lines",
              "attributes": { "product_id": 1, "quantity": 2, "unit_price": 9.99 }
            }
          ]
        }
      }
    }
  }' | jq .
```

Bulk insert (array in `data`, default limit 100) and bulk PATCH (array with ids, default limit 50). Bulk DELETE requires a **`filter`**:

```bash
curl -sS -X DELETE -G 'http://localhost:8888/v1/data/notes' \
  --data-urlencode 'filter[notes]=body~=~tutorial 2'
```

`onduplicate` on POST: `error` (default), `ignore`, or `update` (with `update=col1,col2`).

POST to a relationship URL (e.g. `/v1/data/customers/1/notes`) infers the parent FK from the path.

---

## CSV export and import

```bash
# Export
curl -sS -G 'http://localhost:8888/v1/data/customers' \
  --data-urlencode 'fields[customers]=name,email,country_code' \
  --data-urlencode 'format=csv' -o customers.csv

# Import (same limits/transaction as JSON bulk)
curl -sS -X POST 'http://localhost:8888/v1/data/products?onduplicate=ignore' \
  -H 'Content-Type: text/csv' \
  --data-binary $'sku,name,price,is_active\nSKU-CSV-1,Widget,9.99,1\n' | jq .
```

CSV import accepts insertable attribute columns only (not nested relationship columns). Response is still JSON:API.

---

## What you learned

- Writes use the JSON:API envelope; filters/sort/page/fields stay on the server.
- `include` and relationship URLs load related data; `filter[parent/child]` filters by children.
- Nested create, bulk ops, `onduplicate`, and CSV cover import/export flows.

---

## Next step

[Operate](03-operate.md) — auth, provisioning, and security policies.
