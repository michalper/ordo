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
}
