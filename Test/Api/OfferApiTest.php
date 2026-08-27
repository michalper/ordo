<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Api;

class OfferApiTest extends AbstractApiTestCase
{
    public function testCreateUpdateDelete(): void
    {
        [$status, $created] = $this->asAdmin('POST', '/rest/V1/ordo/offers', [
            'offer' => [
                'customer_id' => 1,
                'reference' => 'API-TEST-OFR',
                'status' => 'draft',
                'total' => 250,
                'currency_code' => 'EUR',
                'expires_at' => '2027-01-01 00:00:00',
            ],
        ]);
        self::assertSame(200, $status, json_encode($created));
        $entityId = $created['entity_id'];
        self::assertSame(0, $created['extension_count']);

        [$status, $updated] = $this->asAdmin('PUT', "/rest/V1/ordo/offers/{$entityId}", [
            'offer' => [
                'entity_id' => $entityId,
                'customer_id' => 1,
                'reference' => 'API-TEST-OFR',
                'status' => 'sent',
                'total' => 250,
                'currency_code' => 'EUR',
                'expires_at' => '2027-01-01 00:00:00',
            ],
        ]);
        self::assertSame(200, $status);
        self::assertSame('sent', $updated['status']);

        [$status] = $this->asAdmin('DELETE', "/rest/V1/ordo/offers/{$entityId}");
        self::assertSame(200, $status);

        [$status] = $this->asAdmin('GET', "/rest/V1/ordo/offers/{$entityId}");
        self::assertSame(404, $status);
    }

    /**
     * Requires ORDO_API_CUSTOMER_EMAIL/PASSWORD to own at least one offer with
     * extension_count < the configured max (Helper\Config::getOfferMaxSelfExtensions()).
     */
    public function testSelfExtendPushesExpiryAndRejectsWrongOwner(): void
    {
        [$status, $created] = $this->asAdmin('POST', '/rest/V1/ordo/offers', [
            'offer' => [
                'customer_id' => 1,
                'reference' => 'API-SELF-EXTEND',
                'status' => 'sent',
                'total' => 100,
                'currency_code' => 'PLN',
                'expires_at' => '2027-06-01 00:00:00',
            ],
        ]);
        self::assertSame(200, $status);
        $entityId = $created['entity_id'];

        [$status, $extended] = $this->asCustomer('POST', "/rest/V1/ordo/offers/{$entityId}/self-extend");
        self::assertSame(200, $status, json_encode($extended));
        self::assertSame(1, $extended['extension_count']);
        self::assertNotSame('2027-06-01 00:00:00', $extended['expires_at']);

        // A nonexistent (or not-owned) offer must not leak which case it is.
        [$status, $notFound] = $this->asCustomer('POST', '/rest/V1/ordo/offers/999999/self-extend');
        self::assertSame(404, $status);
        self::assertStringContainsString('does not exist', $notFound['message']);

        $this->asAdmin('DELETE', "/rest/V1/ordo/offers/{$entityId}");
    }
}
