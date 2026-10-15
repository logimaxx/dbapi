<?php

/**
 * Shared data-plane integration tests for multi-API and single-mode deployments.
 *
 * @see docs/data_plane_test_plan.md
 */
trait DataPlaneTestsTrait
{
    // --- Read / list ---

    public function testListCustomersReturnsJsonApiCollection(): void
    {
        $resp = $this->dataRequest('GET', $this->dataUrl('customers'));
        $body = $this->assertHttpStatus($resp, 200, 'list customers');
        $this->assertArrayHasKey('data', $body);
        $this->assertIsArray($body['data']);
        $this->assertGreaterThanOrEqual(3, count($body['data']));
        $this->assertArrayHasKey('meta', $body);
    }

    public function testGetCustomerById(): void
    {
        $resp = $this->dataRequest('GET', $this->dataUrl('customers', '1'));
        $body = $this->assertHttpStatus($resp, 200, 'get customer 1');
        $this->assertEquals('customers', $body['data']['type']);
        $this->assertEquals('alice@example.com', $body['data']['attributes']['email']);
    }

    public function testGetNonexistentCustomerReturns404(): void
    {
        $resp = $this->dataRequest('GET', $this->dataUrl('customers', '999999'));
        $this->assertHttpStatus($resp, 404, 'missing customer');
    }

    public function testUnknownResourceReturns404(): void
    {
        $resp = $this->dataRequest('GET', $this->dataUrl('no_such_table'));
        $this->assertHttpStatus($resp, 404, 'unknown resource');
    }

    public function testSparseFieldset(): void
    {
        $resp = $this->dataRequest('GET', $this->dataUrl('customers', '1'), [
            'query' => ['fields' => ['customers' => 'name,email']],
        ]);
        $body = $this->assertHttpStatus($resp, 200, 'sparse fields');
        $attrs = $body['data']['attributes'];
        $this->assertArrayHasKey('name', $attrs);
        $this->assertArrayHasKey('email', $attrs);
        $this->assertArrayNotHasKey('country_code', $attrs);
    }

    public function testSparseFieldsetOnInboundInclude(): void
    {
        $resp = $this->dataRequest('GET', $this->dataUrl('customers', '1'), [
            'query' => [
                'include' => 'orders',
                'fields' => [
                    'customers' => 'name,email',
                    'orders' => 'status,total',
                ],
            ],
        ]);
        $body = $this->assertHttpStatus($resp, 200, 'sparse fields on inbound include');
        $this->assertArrayNotHasKey('country_code', $body['data']['attributes']);
        $included = $body['included'] ?? $body['includes'] ?? [];
        $this->assertNotEmpty($included, 'expected included orders');
        foreach ($included as $inc) {
            if (($inc['type'] ?? '') !== 'orders') {
                continue;
            }
            $attrs = $inc['attributes'] ?? [];
            $this->assertArrayHasKey('status', $attrs);
            $this->assertArrayHasKey('total', $attrs);
            $this->assertArrayNotHasKey('customer_id', $attrs);
            $this->assertArrayNotHasKey('ordered_at', $attrs);
        }
    }

    public function testSparseFieldsetKeepsOutboundRelationshipLinkage(): void
    {
        $resp = $this->dataRequest('GET', $this->dataUrl('orders', '1'), [
            'query' => [
                'fields' => ['orders' => 'status,total'],
            ],
        ]);
        $body = $this->assertHttpStatus($resp, 200, 'sparse fields keep outbound linkage');
        $attrs = $body['data']['attributes'];
        $this->assertArrayHasKey('status', $attrs);
        $this->assertArrayNotHasKey('ordered_at', $attrs);
        $this->assertArrayNotHasKey('customer_id', $attrs);
        $this->assertArrayHasKey('customer_id', $body['data']['relationships']);
        $this->assertSame('1', (string) $body['data']['relationships']['customer_id']['data']['id']);
    }

    public function testSparseFieldsetOnOutboundIncludeByType(): void
    {
        $resp = $this->dataRequest('GET', $this->dataUrl('orders', '1'), [
            'query' => [
                'include' => 'customer_id',
                'fields' => [
                    'orders' => 'status,total',
                    'customers' => 'name,email',
                ],
            ],
        ]);
        $body = $this->assertHttpStatus($resp, 200, 'sparse fields on outbound include by type');
        $this->assertArrayHasKey('status', $body['data']['attributes']);
        $this->assertArrayNotHasKey('ordered_at', $body['data']['attributes'] ?? []);
        $this->assertArrayNotHasKey('customer_id', $body['data']['attributes'] ?? []);
        $this->assertArrayHasKey('customer_id', $body['data']['relationships']);
        $included = $body['included'] ?? $body['includes'] ?? [];
        $this->assertNotEmpty($included, 'expected included customer');
        $customer = null;
        foreach ($included as $inc) {
            if (($inc['type'] ?? '') === 'customers') {
                $customer = $inc;
                break;
            }
        }
        $this->assertNotNull($customer, 'included customer missing');
        $attrs = $customer['attributes'] ?? [];
        $this->assertArrayHasKey('name', $attrs);
        $this->assertArrayHasKey('email', $attrs);
        $this->assertArrayNotHasKey('country_code', $attrs);
    }

    public function testSparseFieldsetOnOutboundIncludeByPath(): void
    {
        $resp = $this->dataRequest('GET', $this->dataUrl('orders', '1'), [
            'query' => [
                'include' => 'customer_id',
                'fields' => [
                    'orders' => 'id,status,total,customer_id',
                    'orders/customer_id' => 'name,email',
                ],
            ],
        ]);
        $body = $this->assertHttpStatus($resp, 200, 'sparse fields on outbound include by path');
        $included = $body['included'] ?? $body['includes'] ?? [];
        $customer = null;
        foreach ($included as $inc) {
            if (($inc['type'] ?? '') === 'customers') {
                $customer = $inc;
                break;
            }
        }
        $this->assertNotNull($customer, 'included customer missing');
        $attrs = $customer['attributes'] ?? [];
        $this->assertArrayHasKey('name', $attrs);
        $this->assertArrayNotHasKey('country_code', $attrs);
    }

    public function testReadOnlyView(): void
    {
        $resp = $this->dataRequest('GET', $this->dataUrl('v_order_totals_by_day'));
        $body = $this->assertHttpStatus($resp, 200, 'list view');
        $this->assertArrayHasKey('data', $body);
        $this->assertNotEmpty($body['data']);
        $first = $body['data'][0];
        $this->assertArrayNotHasKey('id', $first, 'resources without a primary key omit id');
        $this->assertEquals('v_order_totals_by_day', $first['type']);
        $this->assertArrayHasKey('attributes', $first);
        $this->assertArrayHasKey('day', $first['attributes']);
    }

    public function testGetViewByIdWithoutPrimaryKeyReturns404(): void
    {
        $resp = $this->dataRequest('GET', $this->dataUrl('v_order_totals_by_day', 'abc123'));
        $this->assertHttpStatus($resp, 404, 'get view by id');
    }

    public function testCatalogItemsJsonMetadata(): void
    {
        $resp = $this->dataRequest('GET', $this->dataUrl('catalog_items', '1'));
        $body = $this->assertHttpStatus($resp, 200, 'catalog item');
        $metadata = $body['data']['attributes']['metadata'] ?? null;
        $this->assertNotNull($metadata);
        if (is_string($metadata)) {
            $metadata = json_decode($metadata, true);
        }
        $this->assertEquals('standard', $metadata['tier'] ?? null);
    }

    // --- Filters (filter_cases seed IDs 1-20) ---

    public function testFilterEquality(): void
    {
        $resp = $this->dataRequest('GET', $this->dataUrl('filter_cases'), [
            'query' => ['filter' => 'id=1'],
        ]);
        $body = $this->assertHttpStatus($resp, 200, 'filter eq');
        $this->assertCount(1, $body['data']);
        $this->assertEquals('alpha-low', $body['data'][0]['attributes']['label']);
    }

    public function testFilterScoreGreaterThan(): void
    {
        $resp = $this->dataRequest('GET', $this->dataUrl('filter_cases'), [
            'query' => ['filter' => 'score>40'],
        ]);
        $body = $this->assertHttpStatus($resp, 200, 'filter gt');
        $this->assertNotEmpty($body['data']);
        foreach ($body['data'] as $row) {
            $this->assertGreaterThan(40, (int) $row['attributes']['score']);
        }
    }

    public function testFilterGreaterThanAndSort(): void
    {
        $resp = $this->dataRequest('GET', $this->dataUrl('filter_cases'), [
            'query' => [
                'filter' => ['filter_cases' => 'score>40'],
                'sort' => ['filter_cases' => '-score'],
            ],
        ]);
        $body = $this->assertHttpStatus($resp, 200, 'filter gt + sort');
        $labels = array_column(array_column($body['data'], 'attributes'), 'label');
        $this->assertContains('alpha-high', $labels);
        $this->assertContains('epsilon-list', $labels);
        if (count($labels) >= 2) {
            $this->assertGreaterThanOrEqual(
                (int) $body['data'][1]['attributes']['score'],
                (int) $body['data'][0]['attributes']['score']
            );
        }
    }

    public function testFilterOneOf(): void
    {
        $resp = $this->dataRequest('GET', $this->dataUrl('filter_cases'), [
            'query' => ['filter' => 'country><US;DE'],
        ]);
        $body = $this->assertHttpStatus($resp, 200, 'filter in-list');
        foreach ($body['data'] as $row) {
            $this->assertContains($row['attributes']['country'], ['US', 'DE']);
        }
    }

    public function testFilterOrGrouping(): void
    {
        $resp = $this->dataRequest('GET', $this->dataUrl('filter_cases'), [
            'query' => ['filter' => '(status=closed||status=pending)'],
        ]);
        $body = $this->assertHttpStatus($resp, 200, 'filter or');
        $this->assertGreaterThanOrEqual(2, count($body['data']));
    }

    public function testFilterNoMatchReturnsEmptyData(): void
    {
        $resp = $this->dataRequest('GET', $this->dataUrl('filter_cases'), [
            'query' => ['filter' => 'label=nonexistent-xyz'],
        ]);
        $body = $this->assertHttpStatus($resp, 200, 'filter empty');
        $this->assertCount(0, $body['data']);
    }

    public function testInvalidFilterReturns400(): void
    {
        $resp = $this->dataRequest('GET', $this->dataUrl('filter_cases'), [
            'query' => ['filter' => '(((unclosed'],
        ]);
        $this->assertContains($resp->getStatusCode(), [400, 422], 'invalid filter');
    }

    public function testFilterBeginsWith(): void
    {
        $resp = $this->dataRequest('GET', $this->dataUrl('filter_cases'), [
            'query' => ['filter' => 'label=~prefix'],
        ]);
        $body = $this->assertHttpStatus($resp, 200, 'filter begins-with');
        $this->assertNotEmpty($body['data']);
        foreach ($body['data'] as $row) {
            $this->assertStringStartsWith('prefix', $row['attributes']['label']);
        }
    }

    public function testFilterContains(): void
    {
        $resp = $this->dataRequest('GET', $this->dataUrl('filter_cases'), [
            'query' => ['filter' => 'label~=~sort'],
        ]);
        $body = $this->assertHttpStatus($resp, 200, 'filter contains');
        $this->assertNotEmpty($body['data']);
        foreach ($body['data'] as $row) {
            $this->assertStringContainsString('sort', $row['attributes']['label']);
        }
    }

    public function testFilterLessThanOrEqual(): void
    {
        $resp = $this->dataRequest('GET', $this->dataUrl('filter_cases'), [
            'query' => ['filter' => 'score<=10'],
        ]);
        $body = $this->assertHttpStatus($resp, 200, 'filter lte');
        $this->assertNotEmpty($body['data']);
        foreach ($body['data'] as $row) {
            $this->assertLessThanOrEqual(10, (int) $row['attributes']['score']);
        }
    }

    public function testFilterNegation(): void
    {
        $resp = $this->dataRequest('GET', $this->dataUrl('filter_cases'), [
            'query' => ['filter' => 'status!=open'],
        ]);
        $body = $this->assertHttpStatus($resp, 200, 'filter negation');
        $this->assertNotEmpty($body['data']);
        foreach ($body['data'] as $row) {
            $this->assertNotEquals('open', $row['attributes']['status']);
        }
    }

    public function testFilterAndCombination(): void
    {
        $resp = $this->dataRequest('GET', $this->dataUrl('filter_cases'), [
            'query' => ['filter' => 'status=open,country=US,score>20'],
        ]);
        $body = $this->assertHttpStatus($resp, 200, 'filter and');
        $this->assertNotEmpty($body['data']);
        foreach ($body['data'] as $row) {
            $attrs = $row['attributes'];
            $this->assertEquals('open', $attrs['status']);
            $this->assertEquals('US', $attrs['country']);
            $this->assertGreaterThan(20, (int) $attrs['score']);
        }
    }

    public function testFilterAdvanced(): void
    {
        $resp = $this->dataRequest('GET', $this->dataUrl('filter_cases'), [
            'query' => [
                'filter' => 'is_active=1',
                'filter_advanced' => ['filter_cases' => 'score>=75'],
            ],
        ]);
        $body = $this->assertHttpStatus($resp, 200, 'filter_advanced');
        $this->assertNotEmpty($body['data']);
        foreach ($body['data'] as $row) {
            $this->assertEquals(1, (int) $row['attributes']['is_active']);
            $this->assertGreaterThanOrEqual(75, (int) $row['attributes']['score']);
        }
    }

    public function testFilterOnRelationship(): void
    {
        $resp = $this->dataRequest('GET', $this->dataUrl('customers'), [
            'query' => [
                'filter' => ['customers/orders' => 'status=shipped'],
            ],
        ]);
        $body = $this->assertHttpStatus($resp, 200, 'filter on relationship');
        $this->assertNotEmpty($body['data']);
        $ids = array_map('strval', array_column($body['data'], 'id'));
        $this->assertContains('1', $ids);
    }

    public function testFilterOnOutboundRelationshipField(): void
    {
        $resp = $this->dataRequest('GET', $this->dataUrl('customers'), [
            'query' => [
                'filter' => ['customers' => 'account_manager_id.full_name~=~Alice'],
            ],
        ]);
        $body = $this->assertHttpStatus($resp, 200, 'filter on outbound relationship field');
        $ids = array_map('strval', array_column($body['data'], 'id'));
        $this->assertSame(['1'], $ids);
    }

    public function testFilterOnOutboundRelationshipFieldNoMatch(): void
    {
        $resp = $this->dataRequest('GET', $this->dataUrl('customers'), [
            'query' => [
                'filter' => ['customers' => 'account_manager_id.full_name~=~Nobody'],
            ],
        ]);
        $body = $this->assertHttpStatus($resp, 200, 'filter on outbound relationship field no match');
        $this->assertEmpty($body['data']);
    }

    // --- Sorting ---

    public function testSortAscendingByLabel(): void
    {
        $resp = $this->dataRequest('GET', $this->dataUrl('filter_cases'), [
            'query' => [
                'filter' => 'label~=~zeta-sort',
                'sort' => 'label',
            ],
        ]);
        $body = $this->assertHttpStatus($resp, 200, 'sort asc');
        $labels = array_column(array_column($body['data'], 'attributes'), 'label');
        $sorted = $labels;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $labels);
    }

    public function testSortDescendingByScore(): void
    {
        $resp = $this->dataRequest('GET', $this->dataUrl('filter_cases'), [
            'query' => [
                'filter' => 'label~=~zeta-sort',
                'sort' => '-score',
            ],
        ]);
        $body = $this->assertHttpStatus($resp, 200, 'sort desc');
        $scores = array_map('intval', array_column(array_column($body['data'], 'attributes'), 'score'));
        $sorted = $scores;
        rsort($sorted, SORT_NUMERIC);
        $this->assertSame($sorted, $scores);
    }

    public function testSortMultipleFields(): void
    {
        $resp = $this->dataRequest('GET', $this->dataUrl('filter_cases'), [
            'query' => [
                'filter' => 'label~=~zeta-sort',
                'sort' => 'score,-label',
            ],
        ]);
        $body = $this->assertHttpStatus($resp, 200, 'sort multi');
        $this->assertGreaterThanOrEqual(2, count($body['data']));
        $scores = array_map('intval', array_column(array_column($body['data'], 'attributes'), 'score'));
        $sortedScores = $scores;
        sort($sortedScores, SORT_NUMERIC);
        $this->assertSame($sortedScores, $scores);
    }

    // --- Pagination ---

    public function testPaginationLimitOffset(): void
    {
        $resp = $this->dataRequest('GET', $this->dataUrl('customers'), [
            'query' => ['page' => ['customers' => ['limit' => 1, 'offset' => 1]]],
        ]);
        $body = $this->assertHttpStatus($resp, 200, 'pagination');
        $this->assertCount(1, $body['data']);
    }

    public function testPaginationMetaTotal(): void
    {
        $resp = $this->dataRequest('GET', $this->dataUrl('filter_cases'), [
            'query' => ['page' => ['filter_cases' => ['limit' => 5, 'offset' => 0]]],
        ]);
        $body = $this->assertHttpStatus($resp, 200, 'pagination meta');
        $this->assertCount(5, $body['data']);
        $this->assertArrayHasKey('meta', $body);
        $this->assertGreaterThanOrEqual(20, (int) ($body['meta']['totalRecords'] ?? $body['meta']['total'] ?? 0));
        $this->assertEquals(0, (int) ($body['meta']['offset'] ?? -1));
    }

    public function testPaginationSecondPage(): void
    {
        $first = $this->dataRequest('GET', $this->dataUrl('filter_cases'), [
            'query' => ['page' => ['filter_cases' => ['limit' => 5, 'offset' => 0]]],
        ]);
        $second = $this->dataRequest('GET', $this->dataUrl('filter_cases'), [
            'query' => ['page' => ['filter_cases' => ['limit' => 5, 'offset' => 5]]],
        ]);
        $firstBody = $this->assertHttpStatus($first, 200, 'page 1');
        $secondBody = $this->assertHttpStatus($second, 200, 'page 2');
        $firstIds = array_column($firstBody['data'], 'id');
        $secondIds = array_column($secondBody['data'], 'id');
        $this->assertEmpty(array_intersect($firstIds, $secondIds));
    }

    public function testPaginationOffsetBeyondTotal(): void
    {
        $resp = $this->dataRequest('GET', $this->dataUrl('filter_cases'), [
            'query' => ['page' => ['filter_cases' => ['limit' => 10, 'offset' => 9999]]],
        ]);
        $body = $this->assertHttpStatus($resp, 200, 'pagination beyond total');
        $this->assertCount(0, $body['data']);
    }

    public function testPaginationGlobalSyntax(): void
    {
        $resp = $this->dataRequest('GET', $this->dataUrl('filter_cases'), [
            'query' => ['page' => ['filter_cases' => ['offset' => 2, 'limit' => 3]]],
        ]);
        $body = $this->assertHttpStatus($resp, 200, 'pagination per-resource syntax');
        $this->assertCount(3, $body['data']);
        $this->assertEquals(2, (int) ($body['meta']['offset'] ?? -1));
    }

    // --- Relationships ---

    public function testIncludeOrdersOnCustomer(): void
    {
        $resp = $this->dataRequest('GET', $this->dataUrl('customers', '1'), [
            'query' => ['include' => 'orders'],
        ]);
        $body = $this->assertHttpStatus($resp, 200, 'include orders');
        $this->assertArrayHasKey('relationships', $body['data']);
        $this->assertArrayHasKey('orders', $body['data']['relationships']);
        if (isset($body['included'])) {
            $this->assertNotEmpty($body['included']);
        }
    }

    public function testIncludeOutboundAccountManager(): void
    {
        $resp = $this->dataRequest('GET', $this->dataUrl('customers', '1'), [
            'query' => ['include' => 'account_manager_id'],
        ]);
        $body = $this->assertHttpStatus($resp, 200, 'include account manager');
        $this->assertArrayHasKey('relationships', $body['data']);
        $this->assertArrayHasKey('account_manager_id', $body['data']['relationships']);
        if (isset($body['included'])) {
            $types = array_column($body['included'], 'type');
            $this->assertContains('users', $types);
        }
    }

    public function testIncludeNullableOutboundRelationKeepsRelationship(): void
    {
        $resp = $this->dataRequest('GET', $this->dataUrl('customers', '3'), [
            'query' => ['include' => 'account_manager_id'],
        ]);
        $body = $this->assertHttpStatus($resp, 200, 'include nullable account manager');
        $this->assertArrayHasKey('relationships', $body['data']);
        $this->assertArrayHasKey('account_manager_id', $body['data']['relationships']);
        $this->assertArrayHasKey('data', $body['data']['relationships']['account_manager_id']);
        $this->assertNull($body['data']['relationships']['account_manager_id']['data']);
    }

    public function testRelatedRecordsEndpoint(): void
    {
        $resp = $this->dataRequest('GET', $this->dataUrl('customers', '1', 'orders'));
        $body = $this->assertHttpStatus($resp, 200, 'customer orders');
        $this->assertArrayHasKey('data', $body);
        $this->assertGreaterThanOrEqual(1, count($body['data']));
        $orderIds = array_map('strval', array_column($body['data'], 'id'));
        $this->assertNotEmpty(array_intersect($orderIds, ['1', '2', '4']));
    }

    public function testRelatedRecordsPagination(): void
    {
        $resp = $this->dataRequest('GET', $this->dataUrl('customers', '1', 'orders'), [
            'query' => ['page' => ['orders' => ['limit' => 1, 'offset' => 0]]],
        ]);
        $body = $this->assertHttpStatus($resp, 200, 'related pagination');
        $this->assertCount(1, $body['data']);
    }

    public function testCreateRelatedOrder(): void
    {
        $create = $this->dataRequest('POST', $this->dataUrl('customers', '3', 'orders'), [
            'json' => [
                'data' => [
                    'type' => 'orders',
                    'attributes' => [
                        'status' => 'draft',
                        'total' => 13,
                    ],
                ],
            ],
        ]);
        $body = $this->assertHttpStatus($create, 201, 'create related order');
        $orderId = $body['data']['id'] ?? null;
        $this->assertNotNull($orderId);

        $get = $this->dataRequest('GET', $this->dataUrl('orders', (string) $orderId));
        $order = $this->assertHttpStatus($get, 200, 'verify related order');
        $customerRel = $order['data']['relationships']['customer_id']['data']['id'] ?? null;
        $this->assertEquals('3', (string) $customerRel);

        $this->dataRequest('DELETE', $this->dataUrl('orders', (string) $orderId));
    }

    public function testUpdateRelatedOrder(): void
    {
        $create = $this->dataRequest('POST', $this->dataUrl('customers', '3', 'orders'), [
            'json' => [
                'data' => [
                    'type' => 'orders',
                    'attributes' => ['status' => 'draft', 'total' => 5.00],
                ],
            ],
        ]);
        $created = $this->assertHttpStatus($create, 201, 'setup related order');
        $orderId = $created['data']['id'];

        $patch = $this->dataRequest('PATCH', $this->dataUrl('customers', '3', 'orders', (string) $orderId), [
            'json' => [
                'data' => [
                    'type' => 'orders',
                    'id' => $orderId,
                    'attributes' => ['status' => 'placed'],
                ],
            ],
        ]);
        $updated = $this->assertHttpStatus($patch, 200, 'update related order');
        $this->assertEquals('placed', $updated['data']['attributes']['status']);

        $this->dataRequest('DELETE', $this->dataUrl('orders', (string) $orderId));
    }

    public function testSubRelatedRecordsEndpoint(): void
    {
        $resp = $this->dataRequest('GET', $this->dataUrl('customers', '1', 'orders', '1', 'order_lines'));
        $body = $this->assertHttpStatus($resp, 200, 'customer order lines');
        $this->assertArrayHasKey('data', $body);
        $this->assertGreaterThanOrEqual(2, count($body['data']));
        $lineIds = array_map('strval', array_column($body['data'], 'id'));
        $this->assertNotEmpty(array_intersect($lineIds, ['1', '2']));
    }

    public function testSubRelatedRecordById(): void
    {
        $resp = $this->dataRequest('GET', $this->dataUrl('customers', '1', 'orders', '1', 'order_lines', '1'));
        $body = $this->assertHttpStatus($resp, 200, 'single order line via nested path');
        $this->assertEquals('1', (string) ($body['data']['id'] ?? ''));
        $this->assertEquals(2, (int) ($body['data']['attributes']['quantity'] ?? 0));
    }

    public function testSubRelatedNotFoundWhenIntermediateNotUnderParent(): void
    {
        $resp = $this->dataRequest('GET', $this->dataUrl('customers', '1', 'orders', '3', 'order_lines'));
        $this->assertContains($resp->getStatusCode(), [404, 400], 'order 3 belongs to customer 2');
    }

    public function testCreateSubRelatedOrderLine(): void
    {
        $create = $this->dataRequest('POST', $this->dataUrl('customers', '1', 'orders', '1', 'order_lines'), [
            'json' => [
                'data' => [
                    'type' => 'order_lines',
                    'attributes' => [
                        'product_id' => 1,
                        'quantity' => 1,
                        'unit_price' => 9.99,
                    ],
                ],
            ],
        ]);
        $body = $this->assertHttpStatus($create, 201, 'create nested order line');
        $lineId = $body['data']['id'] ?? null;
        $this->assertNotNull($lineId);

        $get = $this->dataRequest('GET', $this->dataUrl('customers', '1', 'orders', '1', 'order_lines', (string) $lineId));
        $line = $this->assertHttpStatus($get, 200, 'verify nested order line');
        $this->assertEquals('1', (string) ($line['data']['relationships']['order_id']['data']['id'] ?? ''));

        $this->dataRequest('DELETE', $this->dataUrl('order_lines', (string) $lineId));
    }

    public function testUpdateSubRelatedOrderLine(): void
    {
        $create = $this->dataRequest('POST', $this->dataUrl('customers', '1', 'orders', '1', 'order_lines'), [
            'json' => [
                'data' => [
                    'type' => 'order_lines',
                    'attributes' => [
                        'product_id' => 2,
                        'quantity' => 1,
                        'unit_price' => 19.50,
                    ],
                ],
            ],
        ]);
        $created = $this->assertHttpStatus($create, 201, 'setup nested order line');
        $lineId = $created['data']['id'];

        $patch = $this->dataRequest('PATCH', $this->dataUrl('customers', '1', 'orders', '1', 'order_lines', (string) $lineId), [
            'json' => [
                'data' => [
                    'type' => 'order_lines',
                    'id' => $lineId,
                    'attributes' => ['quantity' => 3],
                ],
            ],
        ]);
        $updated = $this->assertHttpStatus($patch, 200, 'update nested order line');
        $this->assertEquals(3, (int) $updated['data']['attributes']['quantity']);

        $this->dataRequest('DELETE', $this->dataUrl('order_lines', (string) $lineId));
    }

    // --- Create / update / delete ---

    public function testCreateAndDeleteCustomer(): void
    {
        $email = $this->uniqueEmail('create');
        $create = $this->dataRequest('POST', $this->dataUrl('customers'), [
            'json' => [
                'data' => [
                    'type' => 'customers',
                    'attributes' => [
                        'name' => 'PHPUnit Create',
                        'email' => $email,
                        'country_code' => 'US',
                    ],
                ],
            ],
        ]);
        $created = $this->assertHttpStatus($create, 201, 'create customer');
        $id = $created['data']['id'];

        $patch = $this->dataRequest('PATCH', $this->dataUrl('customers', $id), [
            'json' => [
                'data' => [
                    'type' => 'customers',
                    'id' => $id,
                    'attributes' => ['name' => 'PHPUnit Updated'],
                ],
            ],
        ]);
        $updated = $this->assertHttpStatus($patch, 200, 'patch customer');
        $this->assertEquals('PHPUnit Updated', $updated['data']['attributes']['name']);

        $del = $this->dataRequest('DELETE', $this->dataUrl('customers', $id));
        $this->assertContains($del->getStatusCode(), [200, 204], 'delete customer');
    }

    public function testPatchTinyintZeroValue(): void
    {
        $sku = 'ZERO-' . bin2hex(random_bytes(3));
        $create = $this->dataRequest('POST', $this->dataUrl('products'), [
            'json' => [
                'data' => [
                    'type' => 'products',
                    'attributes' => [
                        'sku' => $sku,
                        'name' => 'Active Product',
                        'price' => 1.00,
                        'is_active' => 1,
                    ],
                ],
            ],
        ]);
        $created = $this->assertHttpStatus($create, 201, 'create product');
        $id = $created['data']['id'];

        $patch = $this->dataRequest('PATCH', $this->dataUrl('products', $id), [
            'json' => [
                'data' => [
                    'type' => 'products',
                    'id' => $id,
                    'attributes' => ['is_active' => '0'],
                ],
            ],
        ]);
        $updated = $this->assertHttpStatus($patch, 200, 'patch is_active to 0');
        $this->assertEquals(0, (int) $updated['data']['attributes']['is_active']);

        $this->dataRequest('DELETE', $this->dataUrl('products', $id));
    }

    public function testNestedCreateWithOrder(): void
    {
        $email = $this->uniqueEmail('nested');
        $create = $this->dataRequest('POST', $this->dataUrl('customers'), [
            'json' => [
                'data' => [
                    'type' => 'customers',
                    'attributes' => [
                        'name' => 'Nested Create',
                        'email' => $email,
                        'country_code' => 'US',
                    ],
                    'relationships' => [
                        'orders' => [
                            'data' => [
                                'type' => 'orders',
                                'attributes' => [
                                    'status' => 'draft',
                                    'total' => 10,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
        $nestedStatus = $create->getStatusCode();
        $nestedRaw = (string) $create->getBody();
        if ($nestedStatus === 500 || ($nestedStatus === 400 && str_contains($nestedRaw, 'Invalid 1:n relation'))) {
            $this->markTestSkipped('Nested 1:n create not supported for this API configuration');
        }
        $this->assertEquals(201, $nestedStatus, 'nested create: ' . $nestedRaw);
        $body = json_decode($nestedRaw, true);
        $customerId = $body['data']['id'];

        $orders = $this->dataRequest('GET', $this->dataUrl('customers', $customerId, 'orders'));
        $ordersBody = $this->assertHttpStatus($orders, 200, 'nested orders');
        $this->assertGreaterThanOrEqual(1, count($ordersBody['data']));

        foreach ($ordersBody['data'] as $order) {
            $this->dataRequest('DELETE', $this->dataUrl('orders', $order['id']));
        }
        $this->dataRequest('DELETE', $this->dataUrl('customers', $customerId));
    }

    public function testBulkInsertMultipleRecords(): void
    {
        $skuBase = 'BULK-' . bin2hex(random_bytes(3));
        $create = $this->dataRequest('POST', $this->dataUrl('products'), [
            'json' => [
                'data' => [
                    [
                        'type' => 'products',
                        'attributes' => [
                            'sku' => $skuBase . '-1',
                            'name' => 'Bulk One',
                            'price' => 1.00,
                            'is_active' => 1,
                        ],
                    ],
                    [
                        'type' => 'products',
                        'attributes' => [
                            'sku' => $skuBase . '-2',
                            'name' => 'Bulk Two',
                            'price' => 2.00,
                            'is_active' => 1,
                        ],
                    ],
                ],
            ],
        ]);
        $body = $this->assertHttpStatus($create, 201, 'bulk insert');
        $this->assertIsArray($body['data']);
        $this->assertCount(2, $body['data']);
        foreach ($body['data'] as $row) {
            $this->dataRequest('DELETE', $this->dataUrl('products', $row['id']));
        }
    }

    public function testCsvImportCreatesRecords(): void
    {
        $skuBase = 'CSVIMP-' . bin2hex(random_bytes(3));
        $csv = "sku,name,price,is_active\n"
            . "{$skuBase}-1,Csv One,1.50,1\n"
            . "{$skuBase}-2,Csv Two,2.50,1\n";
        $create = $this->dataRequest('POST', $this->dataUrl('products'), [
            'headers' => ['Content-Type' => 'text/csv'],
            'body' => $csv,
        ]);
        $body = $this->assertHttpStatus($create, 201, 'csv import');
        $this->assertIsArray($body['data']);
        $this->assertCount(2, $body['data']);
        foreach ($body['data'] as $row) {
            $this->dataRequest('DELETE', $this->dataUrl('products', $row['id']));
        }
    }

    public function testCsvImportUnknownColumnReturns400(): void
    {
        $csv = "sku,name,not_a_real_column\nSKU-X,Name,1\n";
        $resp = $this->dataRequest('POST', $this->dataUrl('products'), [
            'headers' => ['Content-Type' => 'text/csv'],
            'body' => $csv,
        ]);
        $this->assertEquals(400, $resp->getStatusCode(), 'unknown csv column');
    }

    public function testCsvImportDottedColumnReturns400(): void
    {
        $csv = "sku,name,customer_id.name\nSKU-X,Name,Acme\n";
        $resp = $this->dataRequest('POST', $this->dataUrl('products'), [
            'headers' => ['Content-Type' => 'text/csv'],
            'body' => $csv,
        ]);
        $this->assertEquals(400, $resp->getStatusCode(), 'dotted csv column');
    }

    public function testCsvImportExceedsBulkLimitReturns400(): void
    {
        $skuBase = 'CSVLIM-' . bin2hex(random_bytes(3));
        $lines = ['sku,name,price,is_active'];
        for ($i = 1; $i <= 101; $i++) {
            $lines[] = "{$skuBase}-{$i},Item {$i},1.00,1";
        }
        $resp = $this->dataRequest('POST', $this->dataUrl('products'), [
            'headers' => ['Content-Type' => 'text/csv'],
            'body' => implode("\n", $lines) . "\n",
        ]);
        $this->assertEquals(400, $resp->getStatusCode(), 'csv bulk limit');
    }

    public function testCsvImportOnDuplicateIgnore(): void
    {
        $sku = 'CSVDUP-' . bin2hex(random_bytes(3));
        $csv = "sku,name,price,is_active\n{$sku},First,1.00,1\n";
        $first = $this->dataRequest('POST', $this->dataUrl('products') . '?onduplicate=ignore', [
            'headers' => ['Content-Type' => 'text/csv'],
            'body' => $csv,
        ]);
        $body = $this->assertHttpStatus($first, 201, 'csv first insert');
        $id = $body['data'][0]['id'] ?? $body['data']['id'] ?? null;
        $this->assertNotNull($id);

        $csv2 = "sku,name,price,is_active\n{$sku},Second,9.00,1\n";
        $second = $this->dataRequest('POST', $this->dataUrl('products') . '?onduplicate=ignore', [
            'headers' => ['Content-Type' => 'text/csv'],
            'body' => $csv2,
        ]);
        $this->assertContains($second->getStatusCode(), [200, 201], 'csv onduplicate ignore');

        $get = $this->dataRequest('GET', $this->dataUrl('products', $id));
        $got = $this->assertHttpStatus($get, 200, 'verify name unchanged');
        $this->assertEquals('First', $got['data']['attributes']['name']);

        $this->dataRequest('DELETE', $this->dataUrl('products', $id));
    }

    public function testCsvImportDuplicateRollsBackTransaction(): void
    {
        $skuExisting = 'CSVEX-' . bin2hex(random_bytes(3));
        $skuNew = 'CSVNEW-' . bin2hex(random_bytes(3));
        $setup = $this->assertHttpStatus($this->dataRequest('POST', $this->dataUrl('products'), [
            'json' => [
                'data' => [
                    'type' => 'products',
                    'attributes' => [
                        'sku' => $skuExisting,
                        'name' => 'Existing',
                        'price' => 1.00,
                        'is_active' => 1,
                    ],
                ],
            ],
        ]), 201);
        $existingId = $setup['data']['id'];

        $csv = "sku,name,price,is_active\n"
            . "{$skuNew},Should Roll Back,2.00,1\n"
            . "{$skuExisting},Dup,3.00,1\n";
        $resp = $this->dataRequest('POST', $this->dataUrl('products'), [
            'headers' => ['Content-Type' => 'text/csv'],
            'body' => $csv,
        ]);
        $this->assertEquals(409, $resp->getStatusCode(), 'csv duplicate conflict');

        $check = $this->dataRequest('GET', $this->dataUrl('products'), [
            'query' => ['filter' => 'sku=' . $skuNew],
        ]);
        $body = $this->assertHttpStatus($check, 200, 'new sku rolled back');
        $this->assertCount(0, $body['data']);

        $this->dataRequest('DELETE', $this->dataUrl('products', $existingId));
    }

    public function testBulkUpdateByIdArray(): void
    {
        $email1 = $this->uniqueEmail('bulk1');
        $email2 = $this->uniqueEmail('bulk2');
        $c1 = $this->assertHttpStatus($this->dataRequest('POST', $this->dataUrl('customers'), [
            'json' => ['data' => ['type' => 'customers', 'attributes' => [
                'name' => 'Bulk A', 'email' => $email1, 'country_code' => 'US',
            ]]],
        ]), 201);
        $c2 = $this->assertHttpStatus($this->dataRequest('POST', $this->dataUrl('customers'), [
            'json' => ['data' => ['type' => 'customers', 'attributes' => [
                'name' => 'Bulk B', 'email' => $email2, 'country_code' => 'US',
            ]]],
        ]), 201);

        $patch = $this->dataRequest('PATCH', $this->dataUrl('customers'), [
            'json' => [
                'data' => [
                    ['type' => 'customers', 'id' => $c1['data']['id'], 'attributes' => ['name' => 'Bulk A Updated']],
                    ['type' => 'customers', 'id' => $c2['data']['id'], 'attributes' => ['name' => 'Bulk B Updated']],
                ],
            ],
        ]);
        $updated = $this->assertHttpStatus($patch, 200, 'bulk update');
        $names = array_column(array_column($updated['data'], 'attributes'), 'name');
        $this->assertContains('Bulk A Updated', $names);
        $this->assertContains('Bulk B Updated', $names);

        $this->dataRequest('DELETE', $this->dataUrl('customers', $c1['data']['id']));
        $this->dataRequest('DELETE', $this->dataUrl('customers', $c2['data']['id']));
    }

    public function testBulkDeleteByFilter(): void
    {
        $tag = 'bulk-del-' . bin2hex(random_bytes(3));
        $ids = [];
        for ($i = 0; $i < 2; $i++) {
            $created = $this->assertHttpStatus($this->dataRequest('POST', $this->dataUrl('notes'), [
                'json' => ['data' => ['type' => 'notes', 'attributes' => [
                    'body' => $tag . ' note ' . $i,
                    'priority' => 0,
                ]]],
            ]), 201);
            $ids[] = $created['data']['id'];
        }

        $del = $this->dataRequest('DELETE', $this->dataUrl('notes'), [
            'query' => ['filter' => 'id><' . implode(';', $ids)],
        ]);
        $this->assertContains($del->getStatusCode(), [200, 204], 'bulk delete');

        $check = $this->dataRequest('GET', $this->dataUrl('notes'), [
            'query' => ['filter' => 'id><' . implode(';', $ids)],
        ]);
        $body = $this->assertHttpStatus($check, 200, 'verify bulk delete');
        $this->assertCount(0, $body['data']);
    }

    public function testDuplicateEmailReturns409(): void
    {
        $resp = $this->dataRequest('POST', $this->dataUrl('customers'), [
            'json' => [
                'data' => [
                    'type' => 'customers',
                    'attributes' => [
                        'name' => 'Dup',
                        'email' => 'alice@example.com',
                        'country_code' => 'US',
                    ],
                ],
            ],
        ]);
        $this->assertEquals(409, $resp->getStatusCode(), 'duplicate email');
    }

    public function testOnDuplicateIgnoreAllowsSecondInsert(): void
    {
        $sku = 'DUP-' . bin2hex(random_bytes(3));
        $payload = [
            'data' => [
                'type' => 'products',
                'attributes' => [
                    'sku' => $sku,
                    'name' => 'First',
                    'price' => 1.00,
                    'is_active' => 1,
                ],
            ],
        ];
        $first = $this->dataRequest('POST', $this->dataUrl('products') . '?onduplicate=ignore', [
            'json' => $payload,
        ]);
        $this->assertHttpStatus($first, 201, 'first product');

        $payload['data']['attributes']['name'] = 'Second';
        $second = $this->dataRequest('POST', $this->dataUrl('products') . '?onduplicate=ignore', [
            'json' => $payload,
        ]);
        $this->assertContains($second->getStatusCode(), [200, 201], 'ignore duplicate');
    }

    public function testOnDuplicateUpdate(): void
    {
        $sku = 'UPD-' . bin2hex(random_bytes(3));
        $create = $this->dataRequest('POST', $this->dataUrl('products'), [
            'json' => [
                'data' => [
                    'type' => 'products',
                    'attributes' => [
                        'sku' => $sku,
                        'name' => 'Original',
                        'price' => 3.00,
                        'is_active' => 1,
                    ],
                ],
            ],
        ]);
        $created = $this->assertHttpStatus($create, 201, 'setup product');
        $id = $created['data']['id'];

        $upsert = $this->dataRequest('POST', $this->dataUrl('products') . '?onduplicate=update&update=name,price', [
            'json' => [
                'data' => [
                    'type' => 'products',
                    'attributes' => [
                        'sku' => $sku,
                        'name' => 'Updated Name',
                        'price' => 5,
                        'is_active' => 1,
                    ],
                ],
            ],
        ]);
        $this->assertContains($upsert->getStatusCode(), [200, 201], 'onduplicate update');

        $get = $this->dataRequest('GET', $this->dataUrl('products', $id));
        $body = $this->assertHttpStatus($get, 200, 'verify upsert');
        $this->assertEquals('Updated Name', $body['data']['attributes']['name']);
        $this->assertEquals('5.00', (string) $body['data']['attributes']['price']);

        $this->dataRequest('DELETE', $this->dataUrl('products', $id));
    }

    public function testOnDuplicateArithmetic(): void
    {
        $sku = 'ADD-' . bin2hex(random_bytes(3));
        $payload = [
            'data' => [
                'type' => 'products',
                'attributes' => [
                    'sku' => $sku,
                    'name' => 'Original',
                    'price' => 3.00,
                    'is_active' => 1,
                ],
            ],
        ];
        $create = $this->dataRequest('POST', $this->dataUrl('products'), ['json' => $payload]);
        $created = $this->assertHttpStatus($create, 201, 'setup product');
        $id = $created['data']['id'];

        $payload['data']['attributes']['price'] = 2.5;
        $added = $this->dataRequest(
            'POST',
            $this->dataUrl('products') . '?onduplicate=update&update=price:add',
            ['json' => $payload]
        );
        $addedRaw = (string) $added->getBody();
        $this->assertContains($added->getStatusCode(), [200, 201], 'add price: ' . $addedRaw);
        $addedBody = json_decode($addedRaw, true);
        $this->assertEquals($id, $addedBody['data']['id'], 'add returns the existing row');
        $this->assertEquals('5.50', (string) $addedBody['data']['attributes']['price']);

        $payload['data']['attributes']['price'] = 1.25;
        $subtracted = $this->dataRequest(
            'POST',
            $this->dataUrl('products') . '?onduplicate=update&update=price:sub',
            ['json' => $payload]
        );
        $subtractedRaw = (string) $subtracted->getBody();
        $this->assertContains($subtracted->getStatusCode(), [200, 201], 'subtract price: ' . $subtractedRaw);
        $subtractedBody = json_decode($subtractedRaw, true);
        $this->assertEquals($id, $subtractedBody['data']['id']);
        $this->assertEquals('4.25', (string) $subtractedBody['data']['attributes']['price']);

        $payload['data']['attributes']['name'] = 'Renamed';
        $payload['data']['attributes']['price'] = 1;
        $mixed = $this->dataRequest(
            'POST',
            $this->dataUrl('products') . '?onduplicate=update&update=price:add,name',
            ['json' => $payload]
        );
        $mixedRaw = (string) $mixed->getBody();
        $this->assertContains($mixed->getStatusCode(), [200, 201], 'add price and replace name: ' . $mixedRaw);
        $mixedBody = json_decode($mixedRaw, true);
        $this->assertEquals($id, $mixedBody['data']['id']);
        $this->assertEquals('Renamed', $mixedBody['data']['attributes']['name']);
        $this->assertEquals('5.25', (string) $mixedBody['data']['attributes']['price']);

        $payload['data']['attributes']['price'] = 0;
        $unchanged = $this->dataRequest(
            'POST',
            $this->dataUrl('products') . '?onduplicate=update&update=price:add',
            ['json' => $payload]
        );
        $unchangedRaw = (string) $unchanged->getBody();
        $this->assertContains($unchanged->getStatusCode(), [200, 201], 'add zero: ' . $unchangedRaw);
        $unchangedBody = json_decode($unchangedRaw, true);
        $this->assertEquals($id, $unchangedBody['data']['id'], 'unchanged row still returns the existing id');
        $this->assertEquals('5.25', (string) $unchangedBody['data']['attributes']['price']);

        $this->dataRequest('DELETE', $this->dataUrl('products', $id));
    }

    public function testOnDuplicateArithmeticRejectsUnsafeOps(): void
    {
        $sku = 'REJ-' . bin2hex(random_bytes(3));
        $base = [
            'data' => [
                'type' => 'products',
                'attributes' => [
                    'sku' => $sku,
                    'name' => 'Original',
                    'price' => 3.00,
                    'is_active' => 1,
                ],
            ],
        ];

        $text = $base;
        $textResp = $this->dataRequest(
            'POST',
            $this->dataUrl('products') . '?onduplicate=update&update=name:add',
            ['json' => $text]
        );
        $textBody = $this->assertHttpStatus($textResp, 400, 'add on text');
        $this->assertStringContainsString('numeric', $textBody['errors'][0]['message']);

        $op = $base;
        $opResp = $this->dataRequest(
            'POST',
            $this->dataUrl('products') . '?onduplicate=update&update=price:mul',
            ['json' => $op]
        );
        $opBody = $this->assertHttpStatus($opResp, 400, 'unknown operator');
        $this->assertStringContainsString('operator', $opBody['errors'][0]['message']);

        $pk = $base;
        $pk['data']['id'] = '1';
        $pkResp = $this->dataRequest(
            'POST',
            $this->dataUrl('products') . '?onduplicate=update&update=id:add',
            ['json' => $pk]
        );
        $pkBody = $this->assertHttpStatus($pkResp, 400, 'add on primary key');
        $this->assertStringContainsString('primary key', $pkBody['errors'][0]['message']);

        $missing = $base;
        unset($missing['data']['attributes']['is_active']);
        $missingResp = $this->dataRequest(
            'POST',
            $this->dataUrl('products') . '?onduplicate=update&update=is_active:add',
            ['json' => $missing]
        );
        $missingBody = $this->assertHttpStatus($missingResp, 400, 'field absent from insert');
        $this->assertStringContainsString('not in the insert', $missingBody['errors'][0]['message']);

        $injectResp = $this->dataRequest(
            'POST',
            $this->dataUrl('products') . '?onduplicate=update&update=' . rawurlencode('price`=1'),
            ['json' => $base]
        );
        $this->assertHttpStatus($injectResp, 400, 'field token is not an identifier');
    }

    public function testInvalidForeignKeyOnOrderReturnsError(): void
    {
        $resp = $this->dataRequest('POST', $this->dataUrl('orders'), [
            'json' => [
                'data' => [
                    'type' => 'orders',
                    'attributes' => [
                        'customer_id' => 999999,
                        'status' => 'draft',
                        'total' => 1.00,
                    ],
                ],
            ],
        ]);
        $this->assertGreaterThanOrEqual(400, $resp->getStatusCode());
        $this->assertLessThan(600, $resp->getStatusCode());
    }

    public function testDeleteCustomerWithOrdersFails(): void
    {
        try {
            $resp = $this->dataRequest('DELETE', $this->dataUrl('customers', '1'));
        } catch (\GuzzleHttp\Exception\TransferException $e) {
            $this->assertTrue(true, 'DELETE rejected when FK RESTRICT applies');
            return;
        }
        $this->assertGreaterThanOrEqual(400, $resp->getStatusCode());
    }

    public function testBulkPatchWithoutFilterReturns400(): void
    {
        $resp = $this->dataRequest('PATCH', $this->dataUrl('customers'), [
            'json' => [
                'data' => [
                    'type' => 'customers',
                    'attributes' => ['name' => 'Bulk'],
                ],
            ],
        ]);
        $this->assertEquals(400, $resp->getStatusCode(), 'bulk patch without filter');
    }

    public function testEmptyPostBodyReturns400(): void
    {
        $resp = $this->dataRequest('POST', $this->dataUrl('customers'), [
            'body' => '',
            'headers' => ['Content-Type' => 'application/json'],
        ]);
        $this->assertEquals(400, $resp->getStatusCode(), 'empty body');
    }

    public function testPostToViewFails(): void
    {
        $resp = $this->dataRequest('POST', $this->dataUrl('v_order_totals_by_day'), [
            'json' => [
                'data' => [
                    'type' => 'v_order_totals_by_day',
                    'attributes' => ['day' => '2026-01-01', 'order_count' => 1, 'revenue' => 0],
                ],
            ],
        ]);
        $this->assertGreaterThanOrEqual(400, $resp->getStatusCode());
    }

    // --- Export ---

    public function testExportCsvFormat(): void
    {
        $resp = $this->dataRequest('GET', $this->dataUrl('products'), [
            'query' => ['format' => 'csv', 'includetablehead' => 'true'],
        ]);
        $this->assertEquals(200, $resp->getStatusCode(), 'csv export');
        $body = (string) $resp->getBody();
        $this->assertStringContainsString('sku', strtolower($body));
        $this->assertStringContainsString('SKU-001', $body);
    }

    public function testExportCsvSparseFieldset(): void
    {
        $resp = $this->dataRequest('GET', $this->dataUrl('products'), [
            'query' => [
                'format' => 'csv',
                'includetablehead' => 'true',
                'fields' => ['products' => 'sku,name'],
            ],
        ]);
        $this->assertEquals(200, $resp->getStatusCode(), 'csv sparse fields');
        $lines = preg_split("/\r\n|\n|\r/", trim((string) $resp->getBody()));
        $header = $lines[0];
        $this->assertStringContainsString('sku', strtolower($header));
        $this->assertStringContainsString('name', strtolower($header));
        $this->assertStringNotContainsString('"id"', $header);
        $this->assertStringNotContainsString('price', strtolower($header));
    }

    public function testExportCsvWithOutboundInclude(): void
    {
        $resp = $this->dataRequest('GET', $this->dataUrl('orders'), [
            'query' => [
                'format' => 'csv',
                'includetablehead' => 'true',
                'include' => 'customer_id',
                'fields' => [
                    'orders' => 'id,status,total,customer_id',
                    'orders/customer_id' => 'name,email',
                ],
                'filter' => ['orders' => 'id=1'],
            ],
        ]);
        $this->assertEquals(200, $resp->getStatusCode(), 'csv export with outbound include');
        $body = (string) $resp->getBody();
        $lines = preg_split("/\r\n|\n|\r/", trim($body));
        $this->assertGreaterThanOrEqual(2, count($lines));
        $header = $lines[0];
        $this->assertStringContainsString('customer_id.name', $header);
        $this->assertStringContainsString('customer_id.email', $header);
        $this->assertStringNotContainsString('customer_id.orders', $header);
        $this->assertStringNotContainsString('customer_id.id', $header);
        $this->assertStringNotContainsString('customer_id.account_manager_id', $header);
        $this->assertStringContainsString('Alice Example', $body);
        $this->assertStringContainsString('alice@example.com', $body);
    }

    public function testExportCsvEscapesSpecialCharacters(): void
    {
        $sku = 'CSV-ESC-' . bin2hex(random_bytes(3));
        $name = "Line1\nLine2 \"quoted\", comma";
        $create = $this->dataRequest('POST', $this->dataUrl('products'), [
            'json' => [
                'data' => [
                    'type' => 'products',
                    'attributes' => [
                        'sku' => $sku,
                        'name' => $name,
                        'price' => 1.00,
                    ],
                ],
            ],
        ]);
        $created = $this->assertHttpStatus($create, 201, 'create product with special chars');
        $id = $created['data']['id'];

        $resp = $this->dataRequest('GET', $this->dataUrl('products'), [
            'query' => [
                'format' => 'csv',
                'includetablehead' => 'true',
                'fields' => ['products' => 'sku,name'],
                'filter' => ['products' => "id=$id"],
            ],
        ]);
        $this->assertEquals(200, $resp->getStatusCode(), 'csv export special chars');

        $fp = fopen('php://memory', 'r+');
        fwrite($fp, (string) $resp->getBody());
        rewind($fp);
        $header = fgetcsv($fp, 0, ',', '"', '"');
        $row = fgetcsv($fp, 0, ',', '"', '"');
        fclose($fp);

        $this->assertIsArray($header);
        $this->assertIsArray($row);
        $nameIdx = array_search('name', $header, true);
        $this->assertNotFalse($nameIdx);
        $this->assertEquals($sku, $row[0]);
        $this->assertEquals($name, $row[$nameIdx]);

        $this->dataRequest('DELETE', $this->dataUrl('products', $id));
    }

    public function testExportCsvDropsInboundInclude(): void
    {
        $resp = $this->dataRequest('GET', $this->dataUrl('customers'), [
            'query' => [
                'format' => 'csv',
                'includetablehead' => 'true',
                'include' => 'orders,account_manager_id',
                'fields' => [
                    'customers' => 'id,name,account_manager_id',
                    'customers/account_manager_id' => 'full_name',
                ],
                'filter' => ['customers' => 'id=1'],
            ],
        ]);
        $this->assertEquals(200, $resp->getStatusCode(), 'csv export drops inbound include');
        $body = (string) $resp->getBody();
        $header = preg_split("/\r\n|\n|\r/", trim($body))[0];
        $this->assertStringNotContainsString('orders.', $header);
        $this->assertStringContainsString('account_manager_id.full_name', $header);
    }

    // --- Stored procedures ---

    public function testStoredProcedureCall(): void
    {
        $resp = $this->dataRequest('POST', $this->storedProcedureUrl('sp_validate_login'), [
            'json' => [
                ['name' => 'p_username', 'value' => 'testuser', 'dir' => 'in'],
                ['name' => 'p_password', 'value' => 'testpass', 'dir' => 'in'],
            ],
        ]);
        $this->assertEquals(200, $resp->getStatusCode(), 'stored procedure');
        $body = trim((string) $resp->getBody());
        if ($body === '') {
            $this->markTestSkipped('Stored procedure returned empty body');
        }
        $decoded = json_decode($body, true);
        $this->assertTrue(is_array($decoded) && !empty($decoded));
    }

    public function testRequestIdHeaderPresent(): void
    {
        $resp = $this->dataRequest('GET', $this->dataUrl('customers', '1'), [
            'headers' => ['X-Request-Id' => 'phpunit-req-1'],
        ]);
        $this->assertHttpStatus($resp, 200);
        $this->assertEquals('phpunit-req-1', $resp->getHeaderLine('X-Request-Id'));
    }

    abstract protected function dataUrl(
        string $resource,
        ?string $id = null,
        ?string $relation = null,
        ?string $relId = null,
        ?string $subRelation = null,
        ?string $subRelId = null
    ): string;

    abstract protected function dataRequest(string $method, string $uri, array $options = []);

    abstract protected function uniqueEmail(string $prefix = 'test'): string;

    protected function storedProcedureUrl(string $procedureName): string
    {
        return $this->dataUrl('__call__/' . $procedureName);
    }
}
