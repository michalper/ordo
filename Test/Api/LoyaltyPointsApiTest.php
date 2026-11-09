<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Api;

/**
 * Same "customer token, no id to guess" shape as CreditLimitApiTest's own `mine` endpoint -
 * ORDO_API_CUSTOMER_EMAIL/PASSWORD resolves to whichever real customer that env points at.
 * Redemption isn't given a dedicated success-path test here: it needs a real cart price rule id
 * configured (Stores > Configuration > Ordo Automation > Loyalty Points > Redemption coupon
 * rule), which this portable client has no way to set up on an arbitrary instance - the
 * not-configured/insufficient-balance failure paths are exercised instead, both real responses
 * from a genuinely unconfigured/zero-balance instance.
 */
class LoyaltyPointsApiTest extends AbstractApiTestCase
{
    public function testGetMyBalanceAsCustomer(): void
    {
        [$status, $body] = $this->asCustomer('GET', '/rest/V1/ordo/loyalty-points/mine');

        self::assertSame(200, $status, json_encode($body));
        self::assertIsInt($body);
    }

    public function testGetMyBalanceRequiresAuthentication(): void
    {
        [$status] = $this->anonymous('GET', '/rest/V1/ordo/loyalty-points/mine');

        self::assertSame(401, $status);
    }

    public function testRedeemFailsWithARealBusinessReasonWhenNotEligible(): void
    {
        [$status, $body] = $this->asCustomer('POST', '/rest/V1/ordo/loyalty-points/redeem');

        // Either outcome is a real, specific LocalizedException message (not configured, or not
        // enough points) on a fresh instance - never a 200 with no coupon, and never a 500.
        self::assertSame(400, $status, json_encode($body));
        self::assertArrayHasKey('message', $body);
    }

    public function testRedeemRequiresAuthentication(): void
    {
        [$status] = $this->anonymous('POST', '/rest/V1/ordo/loyalty-points/redeem');

        self::assertSame(401, $status);
    }
}
