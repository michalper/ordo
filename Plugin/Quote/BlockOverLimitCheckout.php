<?php
declare(strict_types=1);

namespace Ordo\Automation\Plugin\Quote;

use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\PaymentInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\CreditLimitCalculator;

/**
 * Hard-blocks order placement for a logged-in B2B customer who has already reached (>=100%)
 * their configured credit limit. Wired on CartManagementInterface::placeOrder (the interface
 * QuoteManagement implements) as a before-plugin so it runs ahead of order persistence —
 * unlike Observer\HoldOrderForApproval, which only reacts after the order already exists.
 * Guest checkouts (no customer_id on the quote) are never affected — credit limits are a
 * B2B/logged-in-customer concept here.
 */
class BlockOverLimitCheckout
{
    public function __construct(
        private readonly Config $config,
        private readonly CartRepositoryInterface $cartRepository,
        private readonly CreditLimitCalculator $creditLimitCalculator
    ) {
    }

    /**
     * @throws LocalizedException
     */
    public function beforePlaceOrder(
        CartManagementInterface $subject,
        int $cartId,
        ?PaymentInterface $payment = null
    ): array {
        if (!$this->config->isCreditLimitCheckoutBlockEnabled()) {
            return [$cartId, $payment];
        }

        $quote = $this->cartRepository->get($cartId);
        $customerId = (int) $quote->getCustomerId();

        if ($customerId <= 0) {
            return [$cartId, $payment];
        }

        if ($this->creditLimitCalculator->getUtilizationPercent($customerId) >= 100.0) {
            throw new LocalizedException(
                __(
                    'Your order could not be placed because your account has reached its credit '
                    . 'limit. Please contact your sales representative.'
                )
            );
        }

        return [$cartId, $payment];
    }
}
