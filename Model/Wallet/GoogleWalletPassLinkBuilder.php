<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Wallet;

use Magento\Framework\Exception\LocalizedException;
use Ordo\Automation\Helper\Config;

/**
 * The single place a "Save to Google Wallet" URL is actually assembled for a customer - both the
 * REST endpoint (Model\LoyaltyWalletPassManagement) and the storefront redirect controller
 * (Controller\LoyaltyWalletPass\Google) call this, so a future change to the payload/signing flow
 * can't leave the two disagreeing, the same reasoning
 * Model\OrderApprovalManagement::buildDecisionLinks() gives for building its own approve/reject
 * links in one place rather than each caller assembling a URL itself.
 */
class GoogleWalletPassLinkBuilder
{
    public function __construct(
        private readonly GoogleWalletLoyaltyObjectBuilder $loyaltyObjectBuilder,
        private readonly GoogleWalletJwtBuilder $jwtBuilder,
        private readonly Config $config
    ) {
    }

    /**
     * @throws LocalizedException if the feature is disabled, or Google Wallet credentials
     *   (issuer id, class id, service account email/private key) aren't fully configured yet -
     *   reported as an ordinary business outcome by both callers, not a hard error.
     */
    public function buildSaveUrl(int $customerId): string
    {
        if (!$this->config->isWalletPassEnabled()) {
            throw new LocalizedException(__('Loyalty wallet pass is not enabled.'));
        }

        $issuerId = $this->config->getWalletPassGoogleIssuerId();
        $classId = $this->config->getWalletPassGoogleClassId();
        $serviceAccountEmail = $this->config->getWalletPassGoogleServiceAccountEmail();
        $privateKeyPem = $this->config->getWalletPassGooglePrivateKey();

        if ($issuerId === '' || $classId === '' || $serviceAccountEmail === '' || $privateKeyPem === '') {
            throw new LocalizedException(__('Google Wallet is not fully configured.'));
        }

        return $this->jwtBuilder->buildSaveUrl(
            $this->loyaltyObjectBuilder->build($customerId),
            $serviceAccountEmail,
            $privateKeyPem
        );
    }
}
