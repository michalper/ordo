<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Api;

class OrderApprovalApiTest extends AbstractApiTestCase
{
    public function testListNeverExposesToken(): void
    {
        [$status, $list] = $this->asAdmin('GET', '/rest/V1/ordo/order-approvals?searchCriteria[pageSize]=5');
        self::assertSame(200, $status, json_encode($list));

        foreach ($list['items'] as $item) {
            self::assertArrayNotHasKey('token', $item);
            self::assertArrayHasKey('status', $item);
            self::assertArrayHasKey('order_id', $item);
        }
    }

    /**
     * Requires ORDO_API_TEST_APPROVAL_TOKEN to point at a freshly seeded, still-pending
     * ordo_order_approval row (this endpoint is intentionally anonymous — no admin/customer
     * token needed, matching the email approve/reject link it mirrors — so there's nothing to
     * authenticate here besides having a valid one-time token to spend).
     */
    public function testApproveByTokenIsOneTimeUse(): void
    {
        $token = getenv('ORDO_API_TEST_APPROVAL_TOKEN');
        if (!$token) {
            self::markTestSkipped('ORDO_API_TEST_APPROVAL_TOKEN not set — see Test/Api/README.md.');
        }

        [$status, $approved] = $this->anonymous('POST', "/rest/V1/ordo/order-approvals/{$token}/approve");
        self::assertSame(200, $status, json_encode($approved));
        self::assertSame('approved', $approved['status']);

        [$status, $reused] = $this->anonymous('POST', "/rest/V1/ordo/order-approvals/{$token}/approve");
        self::assertSame(404, $status);
        self::assertStringContainsString('Invalid or already-used', $reused['message']);
    }

    /**
     * Requires ORDO_API_TEST_APPROVAL_ENTITY_ID to point at a freshly seeded, still-pending
     * ordo_order_approval row's entity_id. Proves the full loop this endpoint exists for: an
     * admin who never saw the original email can still discover the decision link (admin-token
     * protected, unlike approve/reject themselves) and use it to actually decide the order —
     * not just that the URL string looks right.
     */
    public function testGetDecisionLinksByIdReturnsUsableApproveUrl(): void
    {
        $entityId = getenv('ORDO_API_TEST_APPROVAL_ENTITY_ID');
        if (!$entityId) {
            self::markTestSkipped('ORDO_API_TEST_APPROVAL_ENTITY_ID not set — see Test/Api/README.md.');
        }

        [$status, $links] = $this->asAdmin('GET', "/rest/V1/ordo/order-approvals/{$entityId}/decision-links");
        self::assertSame(200, $status, json_encode($links));
        self::assertStringContainsString('/ordo/approval/approve/token/', $links['approve_url']);
        self::assertStringContainsString('/ordo/approval/reject/token/', $links['reject_url']);

        // approve_url/reject_url are the plain web-controller links (same format as the email,
        // meant to be opened in a browser — hence the redirect, not JSON, if hit directly). A
        // headless client extracts the token and calls the REST decision endpoint with it,
        // which is what's actually being proven usable here.
        $token = basename(parse_url($links['approve_url'], PHP_URL_PATH));
        [$status, $approved] = $this->anonymous('POST', "/rest/V1/ordo/order-approvals/{$token}/approve");
        self::assertSame(200, $status, json_encode($approved));
        self::assertSame('approved', $approved['status']);
    }
}
