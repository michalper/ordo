<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Api;

class ReorderCycleApiTest extends AbstractApiTestCase
{
    public function testListAndGetById(): void
    {
        [$status, $list] = $this->asAdmin('GET', '/rest/V1/ordo/reorder-cycles?searchCriteria[pageSize]=1');
        self::assertSame(200, $status, json_encode($list));
        self::assertArrayHasKey('items', $list);

        if (empty($list['items'])) {
            self::markTestSkipped('No reorder cycles in the database to assert against.');
        }

        $first = $list['items'][0];
        self::assertArrayHasKey('sku', $first);
        self::assertArrayHasKey('avg_interval_days', $first);

        [$status, $fetched] = $this->asAdmin('GET', "/rest/V1/ordo/reorder-cycles/{$first['entity_id']}");
        self::assertSame(200, $status);
        self::assertSame($first['sku'], $fetched['sku']);
    }
}
