<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ReorderCycle;

use Magento\Backend\Model\Session\Quote as QuoteSession;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\AdminOrder\Create as OrderCreate;
use Ordo\Automation\Model\ReorderCycle;

/**
 * Backs Controller\Adminhtml\ReorderCycle\BuildCart - closes the ROADMAP.md "no one-click build
 * reorder cart action" gap. Deliberately reuses Magento's own admin order-creation machinery
 * (Backend\Model\Session\Quote + Sales\Model\AdminOrder\Create) rather than inventing a parallel
 * cart-building path - this is the exact same session-backed quote a merchant building an order
 * by hand already goes through (Customer grid's own "Create Order" button sets customer_id/
 * store_id on this same session before forwarding to sales/order_create/index). Setting the
 * customer and product here, then redirecting there, hands off to that real, already-tested
 * order-creation screen with the cart pre-populated instead of this module trying to replicate
 * checkout/pricing/tax logic itself.
 *
 * Uses the customer's own store_id (not the current admin's scope) - Session\Quote::getQuote()
 * needs a store belonging to the customer's website to correctly associate the new quote with
 * them; the admin's current scope could be a different website's store entirely.
 */
class ReorderCartBuilder
{
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly QuoteSession $quoteSession,
        private readonly OrderCreate $orderCreate
    ) {
    }

    /**
     * @throws \Magento\Framework\Exception\NoSuchEntityException the cycle's own sku no longer
     *   resolves to a real product.
     * @throws LocalizedException addProduct() itself rejected it (e.g. out of stock) - the
     *   product exists but can't actually be added to a cart right now.
     */
    public function build(ReorderCycle $cycle, CustomerInterface $customer): void
    {
        // Only used to confirm the sku still resolves to something real before touching the
        // session at all - addProduct() below is given the numeric id, not this instance, so it
        // loads its own copy scoped to the customer's store (set on the session just below),
        // not whatever scope this lookup happened to use.
        $product = $this->productRepository->get($cycle->getSku());

        $this->quoteSession->setCustomerId((int) $customer->getId());
        $this->quoteSession->setStoreId((int) $customer->getStoreId());

        $this->orderCreate->addProduct((int) $product->getId(), ['qty' => 1]);
    }
}
