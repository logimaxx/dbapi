# Using the API
Once you have created an API, you can use it to read, create, update and delete records from the database.

**New to dbAPI?** Start with the [tutorial series](tutorials/README.md).

The main API endpoint is ```http(s)://hostname/installation_path/api_name/data/```.

The OpenAPI specification is generated when the API schema is built or rebuilt (stored as `openapi.json` in the API config directory). It is served without re-running the generator on each request:

```http(s)://hostname/installation_path/apis/api_name/swagger```

If that URL returns 404, run **schema rebuild** for the API (Management API `POST /mgmt/v1/apis/{id}/schema/rebuild` or equivalent) to create `openapi.json`.

You can also use Swagger UI which is bundled within the installation and can be accessed at ```http(s)://hostname/installation_path/swagger.html?url=apis/api_name/swagger```.

While the Swagger UI is a great tool to explore the API, we recommend reading further to understand the API structure and how to use it.

## Working with data

### Read records

Retrieving records from a table or view, can be done by performing a GET request to the table endpoint. ```http(s)://hostname/installation_path/api_name/data/table_name```

For example let's assume we have a table called **`customers`** and we want to retrieve all the records from it.

```shell
curl --location 'https://localhost/dbapi/apis/api_name/data/customers' 
```

Let's assume the table customers which structure is composed of the following columns: `uid, fname, lname, bdate, city, country, account_manager_id`, where `uid` is primary key and `account_manager_id` is a foreign key to the `users` table. A possible response will look like this:

```json
{
  "data": [
    {
      "id":"123",
      "type":"customers",
      "attributes": {
        "uid": "123",
        "fname": "John",
        "lname": "Doe",
        "bdate": "1976-10-01",
        "city": "Washington",
        "country": "USA"
        },
        "relationships": {
            "orders": {
                "data": null
            },
            "account_manager_id": {
                "data": {
                    "id": "a1b2c3d4",
                    "type": "users"
                }
            }
        }
    },
    ...
  ],
  "meta": {
    "total": 214,
    "offset": 0
  }
}
```

The request can be further customized by employing different query parameters for different purposes.

#### Request correlation
Every response includes **`X-Request-Id`**. You may send your own id with the same header; otherwise the server generates one. JSON error payloads include `meta.request_id` when present.

#### Safety limits
The data API enforces configurable guardrails (defaults in `dbapiator.php`, overridable via environment variables):

| Limit | Default env | Default |
|-------|-------------|---------|
| Page size | `MAX_PAGE_SIZE` | 1000 |
| Filter expression length | `MAX_FILTER_EXPRESSION_LENGTH` | 4096 |
| Filter complexity (depth / nodes) | `MAX_FILTER_AST_DEPTH`, `MAX_FILTER_AST_NODES` | 20 / 100 |
| Bulk insert records per request | `BULK_INSERT_LIMIT` | 100 |
| Bulk update records per request | `BULK_UPDATE_LIMIT` | 50 |
| Request / query timeout (seconds) | `REQUEST_TIMEOUT_SECONDS` | 60 |
| Nested `include` depth | `MAX_INCLUDE_DEPTH` | 5 |

Per-API override: add `query_timeout_seconds` to `connection.php`.

#### Filtering <a id="filtering"></a>
Records filtering uses the **`filter`** parameter with a compact expression language.

**Comparison syntax:** `field_name` + `operator` + `value`

The supported operators are:
- `=`  equal to (Eg. `name=John`)
- `~=`  ends with (Eg. `filename~=.gif`)
- `=~`  begins with (Eg. `date=~2020-12`)
- `~=~` contains  (Eg. `filename~=~error`)
- `>` greater than (Eg. `qty>0`)
- `>=` greater than or equal to (Eg. `date>=2025-02-02`)
- `<` less than (Eg. `qty<10`)
- `<=` less than or equal to (Eg. `temp<=-5`)
- `><` one of the semicolon separated values (Eg. `wday><Mon;Tue;Fri`)
- adding `!` in front of any operator negates it (Eg. `city!=London`)

**Combining expressions:**
- **`,` (comma)** — AND (binds tighter)
- **`||`** — OR (binds looser)
- **`( )`** — grouping

Precedence: parentheses first, then AND (comma), then OR (`||`).

Examples:
- `filter[customers]=fname=~John,bdate<2010` — first name starts with John **and** born before 2010
- `filter[customers]=(city=Washington||city=London),active=1` — (Washington **or** London) **and** active
- `filter[customers]=fname=~John,bdate<2010,city><Washington;London` — same as before for city IN list

Use `><` when several values share the same field and operator; use `||` and `()` when combining different fields or conditions.

Values that contain `,`, `||`, or `)` can be escaped with a backslash (e.g. `note~=~line\,two`). Spaces are allowed in values (e.g. `name~=~ECO down`).

When including relationships:
```shell
curl --location 'https://localhost/dbapi/apis/api_name/data/customers?include=orders&filter[customers]=qty>100'
```

Filtering on linked resources: `filter[main_resource_name/relationship_name]=expression`

For example: `filter[customers/orders]=qty>100` returns customers that have at least one order with quantity greater than 100


#### Sparse field selection <a id="sparse-field-selection"></a>
For the records to contain only certain fields, use the **`fields`** parameter and pass it a comma separated list of field names. 

For example, by using **`fields=name,bdate`** all the returned records will contain only the fields `name` and `bdate`

With includes, sparse fieldsets are keyed by **resource type** (JSON:API style). Path keys also work when you need to target a specific include edge. Outbound relationship linkages are always returned (FK columns are not stripped by `fields`); only attributes are sparse-filtered:

```shell
curl --location 'https://localhost/dbapi/apis/api_name/data/customers/1?include=orders&fields[customers]=name,email&fields[orders]=status,total'
curl --location 'https://localhost/dbapi/apis/api_name/data/orders/1?include=customer_id&fields[orders]=status,total&fields[customers]=name,email'
# equivalent path key for the outbound include:
# fields[orders/customer_id]=name,email
```

#### Sorting <a id="sorting"></a>
Sorting is performed using the **`sort`** paramenter and passing a comma separated list of fields on which to perform the sort. The default sorting direction is ascending. For sorting descending, prepend a - in front of the field name.

Example:
```shell
curl --location 'https://localhost/dbapi/apis/api_name/data/persons?sort=name,-bdate'
```
In the above example the records will be sorted ascending by name and descending byt bdate (birth date).

When using relationships, the sorting parameters should be used like this: 
```shell
curl --location 'https://localhost/dbapi/apis/api_name/data/customers?include=orders&sort[persons]=name,-bdate&sort[orders]=name,-qty'
```


#### Pagination <a id="pagination"></a>
Pagination is performed using the **`page`** parameter which is to be used as as an array.

The following parameters are supported:

- **page[offset]**: record set offset for primary record set. Default 0
- **page[limit]**: number of records per page for primary record set. Default limit is configured in ```installation_path/application/config/dbapiator.php``` by variable ```$config["default_page_size"]``` for main record set and by ```$config["default_relationships_page_size"]``` for linked resources record set

Example:
```shell
curl --location 'https://localhost/dbapi/apis/api_name/data/persons?page[offset]=10&page[limit]=10'
```

When using relationships, the pagination parameters should be used like this: 
```shell
curl --location 'https://localhost/dbapi/apis/api_name/data/persons?page[persons][offset]=10&page[persons][limit]=10&include=orders'
```




### Relationship names (stable public API)

Relationship names are part of the API contract. They are used in URLs, the `include` query parameter, and JSON:API `relationships` objects. **Schema rebuild does not rename existing relationships**; names are preserved per foreign-key edge unless you change them in `patch.php` or the management schema overrides.

| Direction | Default name | Notes |
|-----------|--------------|--------|
| Outbound (many-to-one) | FK **column name** on the current resource | Example: `account_manager_id` on `customers` |
| Inbound (one-to-many) | Child **table name** when unambiguous | Example: `orders` under `customers` |
| Inbound (ambiguous) | `{childTable}_{fkColumn}` | When several FKs share the same child table or the short name is already used |

Examples:

- `GET .../data/customers/1/orders` — inbound relationship `orders`
- `include=account_manager_id` — outbound relationship keyed by column `account_manager_id`
- After regen, a removed FK may leave an **orphan** relationship (still accepted with a warning) so clients are not broken until you clean up `patch.php`.

Rename relationships for clarity using schema overrides (`patch.php` / management API), not by relying on introspection order.

Foreign keys are stored as **outbound relations** on each resource (relation name = FK column name). The older `fields[].foreignKey` duplicate is no longer generated; runtime derives column FK metadata from relations when needed.

### Reading data from a relationship

To read data from a relationship, you can use the include query parameter. For example, if you have a table called ```vendors``` and a table called ```orders``` that has a foreign key to the ```vendors``` table, you can read the orders for a vendor by using the following request:    
```shell
curl --location 'https://localhost/dbapi/apis/api_name/data/vendors/{vedorId}/orders'
```

The ```{vedorId}``` is the id of the vendor you want to read the orders for. The API will return the orders for the vendor.

The same parameters used to read data from a resource can be used to read data from a relationship.

### Creating a record

To create a record, you can use the POST method to the resource endpoint. For example, to create a new vendor, you can use the following request:
```shell
curl --location 'https://localhost/dbapi/apis/api_name/data/vendors' \
--data '{
    "data": {
        "type": "vendors",
        "attributes": {
            "name": "Vendor Name"
        }
    }
}'  
```

You can also create records from a CSV body on the same endpoint (`Content-Type: text/csv`, or `multipart/form-data` with a `file`/`csv` field). The header row must use insertable attribute names; behaviour matches JSON bulk create (transaction + `BULK_INSERT_LIMIT` + `onduplicate`). See [Tutorial 2 — CSV import](tutorials/02-data-plane.md#csv-export-and-import).

In the same POST request you can include the relationships to be created. For example, to create a new vendor with an order, you can use the following request:
```shell
curl --location 'https://localhost/dbapi/apis/api_name/data/vendors' \
--data '{
    "data": {
        "type": "vendors",
        "attributes": {
            "name": "Vendor Name"
        },
        "relationships": {
            "orders": {
                "data": {
                    "type": "orders",
                    "attributes": {
                        "name": "Order Name"
                    }
                }
            }
        }
    }
}'  
```

### Updating a record

To update a record, you can use the PATCH method to the resource endpoint. For example, to update the name of a vendor, you can use the following request:
```shell
curl --location 'https://localhost/dbapi/apis/api_name/data/vendors/{vendorId}' \
--data '{
    "data": {
        "type": "vendors",
        "id": "{vendorId}",
        "attributes": {
            "name": "New Vendor Name"
        }   
    }
}'  
```

### Deleting a record

To delete a record, you can use the DELETE method to the resource endpoint. For example, to delete a vendor, you can use the following request:
```shell
curl --location -X DELETE 'https://localhost/dbapi/apis/api_name/data/vendors/{vendorId}'
```


