<?php
declare(strict_types=1);

namespace Ordo\Automation\Block\Frontend;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\CustomerScoreManager;
use Ordo\Automation\Model\LoyaltyPointsManager;
use Ordo\Automation\Model\LoyaltyTierCalculator;

/**
 * Feeds the "Add to Google Wallet" section of the storefront "My Offers" page - whether to show
 * it at all (feature flag + a logged-in customer), and the balance/tier text next to the button.
 * The button's own href is a plain Controller\LoyaltyWalletPass\Google route - the JWT/signing
 * happens there, never in this view model or its template.
 */
class LoyaltyWalletPassViewModel implements ArgumentInterface
{
    public function __construct(
        private readonly CustomerSession $customerSession,
        private readonly LoyaltyPointsManager $loyaltyPointsManager,
        private readonly LoyaltyTierCalculator $loyaltyTierCalculator,
        private readonly CustomerScoreManager $customerScoreManager,
        private readonly Config $config
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->config->isWalletPassEnabled() && $this->getCustomerId() > 0;
    }

    public function getPointsBalance(): int
    {
        return $this->loyaltyPointsManager->getBalance($this->getCustomerId());
    }

    public function getTierLabel(): string
    {
        $score = $this->customerScoreManager->getScore($this->getCustomerId());

        return $this->loyaltyTierCalculator->getTierLabel($this->loyaltyTierCalculator->getTierForScore($score));
    }

    private function getCustomerId(): int
    {
        return (int) $this->customerSession->getCustomerId();
    }
}
