<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Wallet;

use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\CustomerScoreManager;
use Ordo\Automation\Model\LoyaltyPointsManager;
use Ordo\Automation\Model\LoyaltyTierCalculator;

/**
 * Builds the Google Wallet LoyaltyObject payload (the "Save to Google Wallet" JWT's own
 * `payload.loyaltyObjects[0]` shape - see
 * https://developers.google.com/wallet/retail/loyalty-cards/rest/v1/loyaltyobject) for a single
 * customer, from this module's own existing loyalty data (Model\LoyaltyPointsManager's points
 * ledger, Model\LoyaltyTierCalculator's bronze/silver/gold tier derivation) - no new balance or
 * tier concept, just a read-only projection of what already exists onto Google's schema.
 *
 * This never calls the real Google Wallet Objects REST API to create the object ahead of time -
 * doing so needs a live Issuer account this project doesn't have (see docs/adr/0059). Instead the
 * full object is embedded inline in the JWT (GoogleWalletJwtBuilder's job), which Google's own
 * "Save to Google Wallet" flow accepts in lieu of a pre-created one - the loyalty *class*
 * (program name/branding) still has to be created once, out of band, in the Google Pay & Wallet
 * Console, and its id configured below (Helper\Config::getWalletPassGoogleClassId()).
 */
class GoogleWalletLoyaltyObjectBuilder
{
    public function __construct(
        private readonly LoyaltyPointsManager $loyaltyPointsManager,
        private readonly LoyaltyTierCalculator $loyaltyTierCalculator,
        private readonly CustomerScoreManager $customerScoreManager,
        private readonly Config $config
    ) {
    }

    /**
     * @return array<string, mixed> a Google Wallet LoyaltyObject resource.
     */
    public function build(int $customerId): array
    {
        $balance = $this->loyaltyPointsManager->getBalance($customerId);
        $score = $this->customerScoreManager->getScore($customerId);
        $tierLabel = $this->loyaltyTierCalculator->getTierLabel($this->loyaltyTierCalculator->getTierForScore($score));
        $issuerId = $this->config->getWalletPassGoogleIssuerId();
        $classId = $this->config->getWalletPassGoogleClassId();

        return [
            // Deterministic from the issuer id + customer id, rather than any stored "wallet
            // object id" - there's nothing to persist, this entire object is rebuilt fresh every
            // time a save URL is requested.
            'id' => $issuerId . '.' . $customerId,
            'classId' => $issuerId . '.' . $classId,
            'state' => 'ACTIVE',
            'accountId' => (string) $customerId,
            'loyaltyPoints' => [
                'label' => 'Points',
                'balance' => ['int' => $balance],
            ],
            // Google's LoyaltyObject has no dedicated "tier" field - secondaryLoyaltyPoints with
            // a string balance is the documented way to show a second, non-numeric line (Google's
            // own sample payloads use it for exactly this).
            'secondaryLoyaltyPoints' => [
                'label' => 'Tier',
                'balance' => ['string' => $tierLabel],
            ],
            'barcode' => [
                'type' => 'QR_CODE',
                'value' => (string) $customerId,
            ],
        ];
    }
}
