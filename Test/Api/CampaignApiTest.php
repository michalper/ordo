<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Api;

/**
 * Full CRUD round trip against the real REST API — actually run against a live Magento 2.4.7
 * instance while writing this. See README.md for the four real defects this surfaced and
 * fixed (missing docblocks on service/data interfaces, and the SearchResults item-type
 * problem for the list endpoint).
 */
class CampaignApiTest extends AbstractApiTestCase
{
    public function testFullCrudRoundTrip(): void
    {
        [$status, $created] = $this->asAdmin('POST', '/rest/V1/ordo/campaigns', [
            'campaign' => [
                'name' => 'API Test Campaign',
                'trigger_event' => 'order_placed',
                'enabled' => true,
            ],
        ]);
        self::assertSame(200, $status, json_encode($created));
        self::assertSame('API Test Campaign', $created['name']);
        self::assertTrue($created['enabled']);
        $entityId = $created['entity_id'];

        [$status, $fetched] = $this->asAdmin('GET', "/rest/V1/ordo/campaigns/{$entityId}");
        self::assertSame(200, $status);
        self::assertSame($entityId, $fetched['entity_id']);
        self::assertSame('API Test Campaign', $fetched['name']);

        [$status, $list] = $this->asAdmin('GET', '/rest/V1/ordo/campaigns?searchCriteria[pageSize]=1');
        self::assertSame(200, $status);
        self::assertArrayHasKey('items', $list);
        self::assertNotEmpty($list['items']);
        // Every item must be a real object with fields, not the empty-object regression
        // documented in README.md — this is the assertion that would have caught it.
        self::assertArrayHasKey('entity_id', $list['items'][0]);
        self::assertArrayHasKey('name', $list['items'][0]);

        [$status, $updated] = $this->asAdmin('PUT', "/rest/V1/ordo/campaigns/{$entityId}", [
            'campaign' => [
                'entity_id' => $entityId,
                'name' => 'API Test Campaign Updated',
                'trigger_event' => 'order_placed',
                'enabled' => false,
            ],
        ]);
        self::assertSame(200, $status);
        self::assertSame('API Test Campaign Updated', $updated['name']);
        self::assertFalse($updated['enabled']);

        [$status, $deleteResult] = $this->asAdmin('DELETE', "/rest/V1/ordo/campaigns/{$entityId}");
        self::assertSame(200, $status);
        self::assertTrue($deleteResult);

        [$status, $notFound] = $this->asAdmin('GET', "/rest/V1/ordo/campaigns/{$entityId}");
        self::assertSame(404, $status);
        self::assertStringContainsString('does not exist', $notFound['message']);
    }
}
