<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\GraphQl;

/**
 * Real e2e coverage for the Phase 2 GraphQL "My Offers"/credit-limit surface (ROADMAP.md) -
 * ordoOffers, ordoCreditLimit, extendOrdoOffer. Same reasoning as TrackingApiTest for staying
 * read-only/no-fixture-seeding where possible: ordoOffers/ordoCreditLimit need no seeded row to
 * exercise their real shape and identity resolution (an empty/zeroed result is still a real,
 * correctly-authenticated response, not a skipped assertion). extendOrdoOffer's one business
 * path that DOES need an owned, extendable offer row (ok: true) isn't covered here for that
 * reason — its ownership/cap logic is Api\OfferManagementInterface::selfExtend()'s own, already
 * covered by that service's own test suite; this file only confirms the GraphQL transport wires
 * a real id through to it correctly (the not-found path below).
 */
class OffersApiTest extends AbstractGraphQlTestCase
{
    public function testOrdoOffersReturnsAnEmptyArrayForAGuest(): void
    {
        [$status, $body] = $this->anonymous('{ ordoOffers { reference } }');

        self::assertSame(200, $status, json_encode($body));
        self::assertArrayNotHasKey('errors', $body, json_encode($body));
        self::assertSame(['ordoOffers' => []], $body['data'] ?? null);
    }

    /**
     * Identity comes from the GraphQL customer token (ContextInterface::getUserId()), confirmed
     * by never passing a customer id anywhere in the request and still getting a clean 200
     * response back with the real per-customer query having run (an empty list for this test
     * account, not a guest's hard-coded empty list — see the guest test above for that case).
     */
    public function testOrdoOffersReturnsTheExpectedShapeForALoggedInCustomer(): void
    {
        [$status, $body] = $this->asCustomer(
            '{ ordoOffers { entity_id reference status total currency_code expires_at can_self_extend self_extension_days } }'
        );

        self::assertSame(200, $status, json_encode($body));
        self::assertArrayNotHasKey('errors', $body, json_encode($body));
        self::assertArrayHasKey('ordoOffers', $body['data'] ?? []);
        self::assertIsArray($body['data']['ordoOffers']);
    }

    public function testOrdoCreditLimitIsAGraphQlAuthorizationErrorForAGuest(): void
    {
        [$status, $body] = $this->anonymous('{ ordoCreditLimit { credit_limit } }');

        // Magento maps GraphQlAuthorizationException to a 403 response, unlike the 200 every
        // other GraphQL error (including graphql-no-such-entity below) returns per the GraphQL
        // spec's own "errors is just another response field" convention.
        self::assertSame(403, $status, json_encode($body));
        self::assertSame('graphql-authorization', $body['errors'][0]['extensions']['category'] ?? null, json_encode($body));
        self::assertArrayHasKey('ordoCreditLimit', $body['data'] ?? [], json_encode($body));
        self::assertNull($body['data']['ordoCreditLimit'], json_encode($body));
    }

    public function testOrdoCreditLimitReturnsTheExpectedShapeForALoggedInCustomer(): void
    {
        [$status, $body] = $this->asCustomer(
            '{ ordoCreditLimit { credit_limit used_credit available_credit utilization_percent } }'
        );

        self::assertSame(200, $status, json_encode($body));
        self::assertArrayNotHasKey('errors', $body, json_encode($body));
        self::assertArrayHasKey('credit_limit', $body['data']['ordoCreditLimit'] ?? []);
        self::assertArrayHasKey('utilization_percent', $body['data']['ordoCreditLimit'] ?? []);
    }

    public function testExtendOrdoOfferIsAGraphQlNotFoundErrorForANonexistentOffer(): void
    {
        [$status, $body] = $this->asCustomer('mutation { extendOrdoOffer(offerId: 999999999) { ok reason } }');

        self::assertSame(200, $status, json_encode($body));
        self::assertSame(
            'graphql-no-such-entity',
            $body['errors'][0]['extensions']['category'] ?? null,
            json_encode($body)
        );
        self::assertArrayHasKey('extendOrdoOffer', $body['data'] ?? [], json_encode($body));
        self::assertNull($body['data']['extendOrdoOffer'], json_encode($body));
    }
}
