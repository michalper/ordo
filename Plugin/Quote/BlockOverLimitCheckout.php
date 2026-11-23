<?php
declare(strict_types=1);

namespace Ordo\Automation\Plugin\Quote;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\PaymentInterface;
use Magento\Quote\Model\Quote;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\CreditLimitCalculator;

/**
 * Hard-blocks order placement for a logged-in B2B customer who has already reached (>=100%)
 * their configured credit limit. Wired on CartManagementInterface::placeOrder (the interface
 * QuoteManagement implements) as an around-plugin so it runs ahead of order persistence —
 * unlike Observer\HoldOrderForApproval, which only reacts after the order already exists.
 * Guest checkouts (no customer_id on the quote) are never affected — credit limits are a
 * B2B/logged-in-customer concept here.
 *
 * Around, not before: CreditLimitCalculator::getUsedCredit() only sees already-PLACED orders, so
 * two near-simultaneous placeOrder() calls for the same customer (two tabs, or two buyers on a
 * shared B2B account) could each pass the check before either order actually commits, together
 * pushing the real used credit past the limit even though the gate exists specifically to
 * prevent that. A MySQL named lock, held per-customer across the real $proceed() call (not just
 * the check), serializes the whole check-then-place sequence so a second concurrent call's check
 * only runs once the first call's order has actually been persisted.
 */
class BlockOverLimitCheckout
{
    public function __construct(
        private readonly Config $config,
        private readonly CartRepositoryInterface $cartRepository,
        private readonly CreditLimitCalculator $creditLimitCalculator,
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * @param callable(int, ?PaymentInterface): array{int, PaymentInterface|null} $proceed
     * @return array{int, PaymentInterface|null}
     * @throws LocalizedException
     */
    public function aroundPlaceOrder(
        CartManagementInterface $subject,
        callable $proceed,
        int $cartId,
        ?PaymentInterface $payment = null
    ): array {
        if (!$this->config->isCreditLimitCheckoutBlockEnabled()) {
            return $proceed($cartId, $payment);
        }

        /** @var Quote $quote */
        $quote = $this->cartRepository->get($cartId);
        $customerId = (int) $quote->getCustomerId();

        if ($customerId <= 0) {
            return $proceed($cartId, $payment);
        }

        $connection = $this->resourceConnection->getConnection();
        $lockName = 'ordo_credit_limit_checkout_customer_' . $customerId;

        // 10s is generous for a real order-placement call (payment, inventory, etc.) under
        // contention from the same customer - fail closed (never place) rather than risk two
        // orders racing past the limit together.
        if (!(bool) $connection->fetchOne('SELECT GET_LOCK(?, 10)', [$lockName])) {
            throw new LocalizedException(
                __('Your order could not be placed right now - please try again.')
            );
        }

        try {
            // getUtilizationPercent() alone only sees already-placed orders, not the order
            // currently being placed - folding the quote's own grand total into "used" here is
            // what actually blocks a single order that would itself blow past the limit, not
            // just a later one once this order has already posted.
            $projectedUsage = $this->creditLimitCalculator->getProjectedUtilizationPercent(
                $customerId,
                (float) $quote->getBaseGrandTotal()
            );

            if ($projectedUsage >= 100.0) {
                throw new LocalizedException(
                    __(
                        'Your order could not be placed because your account has reached its '
                        . 'credit limit. Please contact your sales representative.'
                    )
                );
            }

            return $proceed($cartId, $payment);
        } finally {
            // phpcs:ignore Magento2.SQL.RawQuery.FoundRawSql
            $connection->query('SELECT RELEASE_LOCK(?)', [$lockName]);
        }
    }
}
