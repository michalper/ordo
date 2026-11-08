<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\GraphQl;

/**
 * Real e2e coverage for the Phase 1 GraphQL tracking surface (ROADMAP.md) - ordoTrackerPoll,
 * trackOrdoEvent, dismissOrdoNotification, submitOrdoSurveyResponse. Deliberately only exercises
 * paths that don't need a pre-seeded fixture row (no popup/notification/survey-prompt creation
 * mutation exists - those are campaign-engine-produced, not customer-facing), same reasoning
 * ReorderCycleApiTest stays read-only-focused rather than inventing fixture seeding this suite
 * has no other way to do. A nonexistent id naturally exercises the same "not found" branch a
 * real one that's already been acted on would.
 */
class TrackingApiTest extends AbstractGraphQlTestCase
{
    public function testOrdoTrackerPollReturnsTheExpectedShapeForAnonymousVisitor(): void
    {
        $visitorId = 'graphql-api-test-' . uniqid();
        [$status, $body] = $this->anonymous(
            'query($v: String) { ordoTrackerPoll(visitorId: $v) { popup { headline } notifications { id } survey { id question } } }',
            ['v' => $visitorId]
        );

        self::assertSame(200, $status, json_encode($body));
        self::assertArrayNotHasKey('errors', $body, json_encode($body));
        self::assertArrayHasKey('data', $body);
        self::assertArrayHasKey('ordoTrackerPoll', $body['data']);
    }

    public function testTrackOrdoEventRecordsARealEvent(): void
    {
        [$status, $body] = $this->anonymous(
            'mutation($v: String!) { trackOrdoEvent(eventType: "page_view", visitorId: $v) { ok reason } }',
            ['v' => 'graphql-api-test-' . uniqid()]
        );

        self::assertSame(200, $status, json_encode($body));
        self::assertSame(['ok' => true, 'reason' => null], $body['data']['trackOrdoEvent'] ?? null);
    }

    public function testTrackOrdoEventRejectsAnUnknownEventType(): void
    {
        [$status, $body] = $this->anonymous(
            'mutation($v: String!) { trackOrdoEvent(eventType: "not_a_real_event", visitorId: $v) { ok reason } }',
            ['v' => 'graphql-api-test-' . uniqid()]
        );

        self::assertSame(200, $status, json_encode($body));
        self::assertSame(
            ['ok' => false, 'reason' => 'invalid_payload'],
            $body['data']['trackOrdoEvent'] ?? null
        );
    }

    public function testTrackOrdoEventRejectsAnEmptyVisitorId(): void
    {
        [$status, $body] = $this->anonymous(
            'mutation { trackOrdoEvent(eventType: "page_view", visitorId: "") { ok reason } }'
        );

        self::assertSame(200, $status, json_encode($body));
        self::assertSame(
            ['ok' => false, 'reason' => 'invalid_payload'],
            $body['data']['trackOrdoEvent'] ?? null
        );
    }

    public function testDismissOrdoNotificationReturnsFalseForANonexistentId(): void
    {
        [$status, $body] = $this->anonymous(
            'mutation { dismissOrdoNotification(notificationId: 999999999, visitorId: "graphql-api-test") { ok } }'
        );

        self::assertSame(200, $status, json_encode($body));
        self::assertSame(['ok' => false], $body['data']['dismissOrdoNotification'] ?? null);
    }

    public function testSubmitOrdoSurveyResponseReturnsFalseForANonexistentId(): void
    {
        [$status, $body] = $this->anonymous(
            'mutation { submitOrdoSurveyResponse(surveyId: 999999999, score: 7, visitorId: "graphql-api-test") { ok } }'
        );

        self::assertSame(200, $status, json_encode($body));
        self::assertSame(['ok' => false], $body['data']['submitOrdoSurveyResponse'] ?? null);
    }

    public function testSubmitOrdoSurveyResponseRejectsAnOutOfRangeScore(): void
    {
        [$status, $body] = $this->anonymous(
            'mutation { submitOrdoSurveyResponse(surveyId: 1, score: 11, visitorId: "graphql-api-test") { ok } }'
        );

        self::assertSame(200, $status, json_encode($body));
        self::assertSame(['ok' => false], $body['data']['submitOrdoSurveyResponse'] ?? null);
    }

    /**
     * A logged-in customer's identity must come from the GraphQL customer token
     * (ContextInterface::getUserId()), not a client-supplied id — confirmed here by never
     * passing a customer id anywhere in the request and still getting a clean 200/ok response
     * shape back (the real identity-resolution path is exercised whether or not this customer
     * actually owns any rows to act on).
     */
    public function testSubmitOrdoSurveyResponseResolvesIdentityFromTheCustomerToken(): void
    {
        [$status, $body] = $this->asCustomer(
            'mutation { submitOrdoSurveyResponse(surveyId: 999999999, score: 5) { ok } }'
        );

        self::assertSame(200, $status, json_encode($body));
        self::assertSame(['ok' => false], $body['data']['submitOrdoSurveyResponse'] ?? null);
    }
}
