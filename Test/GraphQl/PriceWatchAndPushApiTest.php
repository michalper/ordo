<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\GraphQl;

/**
 * Real e2e coverage for the Phase 3 GraphQL price-watch/push surface (ROADMAP.md) -
 * registerOrdoPriceWatch/unregisterOrdoPriceWatch, registerOrdoPushSubscription/
 * unregisterOrdoPushSubscription. Both register mutations are gated by their own feature
 * toggle (Helper\Config::isPriceWatchEnabled()/isPushEnabled()), off by default - unlike Phase
 * 1's trackOrdoEvent, which has no equivalent gate (CLAUDE.md: every feature has its own config,
 * no single master switch, and defaults differ per feature). Register tests below only assert
 * the response SHAPE (a real 200 with a boolean ok, reason one of the known string values), not
 * a specific `ok` value, so this suite passes regardless of whether the target instance has the
 * feature toggle on - exactly like testOrdoTrackerPollReturnsTheExpectedShapeForAnonymousVisitor
 * in TrackingApiTest already does for the same reason. The unregister mutations have no such
 * gate (idempotent, safe either way), so those assert the real ok: true outcome directly.
 */
class PriceWatchAndPushApiTest extends AbstractGraphQlTestCase
{
    public function testRegisterOrdoPriceWatchReturnsTheExpectedShape(): void
    {
        [$status, $body] = $this->anonymous(
            'mutation($v: String!) { registerOrdoPriceWatch(productId: 1, watchType: "price_drop", visitorId: $v) { ok reason } }',
            ['v' => 'graphql-api-test-' . uniqid()]
        );

        self::assertSame(200, $status, json_encode($body));
        self::assertArrayNotHasKey('errors', $body, json_encode($body));
        $result = $body['data']['registerOrdoPriceWatch'] ?? null;
        self::assertIsArray($result, json_encode($body));
        self::assertIsBool($result['ok'], json_encode($body));
        if ($result['ok'] === false) {
            self::assertContains(
                $result['reason'],
                ['price_watch_disabled', 'invalid_payload', 'invalid_product', 'invalid_email'],
                json_encode($body)
            );
        }
    }

    public function testRegisterOrdoPriceWatchRejectsAnUnknownWatchType(): void
    {
        [$status, $body] = $this->anonymous(
            'mutation($v: String!) { registerOrdoPriceWatch(productId: 1, watchType: "not_a_real_type", visitorId: $v) { ok reason } }',
            ['v' => 'graphql-api-test-' . uniqid()]
        );

        self::assertSame(200, $status, json_encode($body));
        $result = $body['data']['registerOrdoPriceWatch'] ?? null;
        self::assertFalse($result['ok'] ?? null, json_encode($body));
        // Either the toggle is off (checked first) or the bad watch_type is - both are correct.
        self::assertContains($result['reason'], ['price_watch_disabled', 'invalid_payload'], json_encode($body));
    }

    public function testUnregisterOrdoPriceWatchIsIdempotentForANonexistentWatch(): void
    {
        [$status, $body] = $this->anonymous(
            'mutation($v: String!) { unregisterOrdoPriceWatch(productId: 999999999, watchType: "price_drop", visitorId: $v) { ok } }',
            ['v' => 'graphql-api-test-' . uniqid()]
        );

        self::assertSame(200, $status, json_encode($body));
        self::assertSame(['ok' => true], $body['data']['unregisterOrdoPriceWatch'] ?? null);
    }

    public function testRegisterOrdoPushSubscriptionReturnsTheExpectedShape(): void
    {
        [$status, $body] = $this->anonymous(
            'mutation($v: String!) { registerOrdoPushSubscription(endpoint: "https://fcm.googleapis.com/fcm/send/graphql-api-test", p256dh: "k", auth: "a", visitorId: $v) { ok reason } }',
            ['v' => 'graphql-api-test-' . uniqid()]
        );

        self::assertSame(200, $status, json_encode($body));
        self::assertArrayNotHasKey('errors', $body, json_encode($body));
        $result = $body['data']['registerOrdoPushSubscription'] ?? null;
        self::assertIsArray($result, json_encode($body));
        self::assertIsBool($result['ok'], json_encode($body));
        if ($result['ok'] === false) {
            self::assertContains(
                $result['reason'],
                ['push_disabled', 'invalid_payload', 'invalid_endpoint'],
                json_encode($body)
            );
        }
    }

    public function testRegisterOrdoPushSubscriptionRejectsADisallowedEndpoint(): void
    {
        [$status, $body] = $this->anonymous(
            'mutation($v: String!) { registerOrdoPushSubscription(endpoint: "http://169.254.169.254/latest/meta-data/", p256dh: "k", auth: "a", visitorId: $v) { ok reason } }',
            ['v' => 'graphql-api-test-' . uniqid()]
        );

        self::assertSame(200, $status, json_encode($body));
        $result = $body['data']['registerOrdoPushSubscription'] ?? null;
        self::assertFalse($result['ok'] ?? null, json_encode($body));
        // Either the toggle is off (checked first) or the SSRF-blocked endpoint is - both correct.
        self::assertContains($result['reason'], ['push_disabled', 'invalid_endpoint'], json_encode($body));
    }

    public function testUnregisterOrdoPushSubscriptionIsIdempotentForANonexistentEndpoint(): void
    {
        [$status, $body] = $this->anonymous(
            'mutation($v: String!) { unregisterOrdoPushSubscription(endpoint: "https://fcm.googleapis.com/fcm/send/nonexistent", visitorId: $v) { ok } }',
            ['v' => 'graphql-api-test-' . uniqid()]
        );

        self::assertSame(200, $status, json_encode($body));
        self::assertSame(['ok' => true], $body['data']['unregisterOrdoPushSubscription'] ?? null);
    }

    /**
     * Identity comes from the GraphQL customer token, confirmed the same way
     * TrackingApiTest::testSubmitOrdoSurveyResponseResolvesIdentityFromTheCustomerToken does -
     * never passing a customer id anywhere in the request and still getting a clean response.
     */
    public function testUnregisterOrdoPushSubscriptionResolvesIdentityFromTheCustomerToken(): void
    {
        [$status, $body] = $this->asCustomer(
            'mutation { unregisterOrdoPushSubscription(endpoint: "https://fcm.googleapis.com/fcm/send/nonexistent") { ok } }'
        );

        self::assertSame(200, $status, json_encode($body));
        self::assertSame(['ok' => true], $body['data']['unregisterOrdoPushSubscription'] ?? null);
    }
}
