<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Api;

/**
 * Same "customer token, no id to guess" shape as LoyaltyPointsApiTest's own `mine` endpoint.
 * A real save URL isn't given a dedicated success-path test here: it needs real Google Wallet
 * credentials (issuer id, class id, service account email/private key) configured on the target
 * instance, which this portable client has no way to set up on an arbitrary instance - same
 * "exercise the real not-configured failure path instead" reasoning LoyaltyPointsApiTest already
 * gives for redemption. See docs/adr/0062 for why that gap is permanent, not pending.
 */
class LoyaltyWalletPassApiTest extends AbstractApiTestCase
{
    public function testGetMyGoogleWalletSaveUrlFailsWithARealBusinessReasonWhenNotConfigured(): void
    {
        [$status, $body] = $this->asCustomer('GET', '/rest/V1/ordo/loyalty-wallet-pass/google');

        // Either outcome is a real, specific LocalizedException message (feature disabled, or
        // Google Wallet credentials incomplete) on a fresh instance - never a 200 with no URL,
        // and never a 500.
        self::assertSame(400, $status, json_encode($body));
        self::assertArrayHasKey('message', $body);
    }

    public function testGetMyGoogleWalletSaveUrlRequiresAuthentication(): void
    {
        [$status] = $this->anonymous('GET', '/rest/V1/ordo/loyalty-wallet-pass/google');

        self::assertSame(401, $status);
    }
}
