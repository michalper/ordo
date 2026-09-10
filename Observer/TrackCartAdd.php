<?php
declare(strict_types=1);

namespace Ordo\Automation\Observer;

use Magento\Catalog\Model\Product;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Event\Observer as EventObserver;
use Magento\Framework\Event\ObserverInterface;
use Ordo\Automation\Model\VisitorEventLogger;

/**
 * Fires on checkout_cart_product_add_after (Magento\Checkout\Model\Cart::addProduct(), the
 * standard "add to cart" entry point every storefront add-to-cart flow already goes through) -
 * logs a `cart_add` row into ordo_visitor_event, the same table page_view/product_view/
 * category_view already populate (see Controller\Track\Event / Model\VisitorEventLogger).
 *
 * Logged-in customers only, deliberately, for v1 - an anonymous cart-add has no campaign use
 * case yet (there's no channel to message an anonymous visitor through), so this skips the
 * cross-cutting complexity of reading the frontend's ordo_visitor_id cookie from a server-side
 * observer for a signal nothing would consume. Reusing that cookie for anonymous cart-add
 * tracking is a reasonable follow-up once there's a concrete feature that needs it (e.g.
 * abandoned-cart popup targeting), not built ahead of one.
 *
 * event_key is the SKU, not the product_id - matches purchased_sku's own identity choice
 * (Model\Purchase\PurchasedProductResolver), since SKU is what admins already type into every
 * other product-identity condition/field in this module.
 */
class TrackCartAdd implements ObserverInterface
{
    private const string EVENT_TYPE = 'cart_add';

    public function __construct(
        private readonly CustomerSession $customerSession,
        private readonly VisitorEventLogger $visitorEventLogger
    ) {
    }

    public function execute(EventObserver $observer): void
    {
        if (!$this->customerSession->isLoggedIn()) {
            return;
        }

        /** @var Product|null $product */
        $product = $observer->getEvent()->getProduct();
        $sku = $product?->getSku();
        if (!$sku) {
            return;
        }

        $customerId = (int) $this->customerSession->getCustomerId();

        // ordo_visitor_event.visitor_id is NOT NULL - a logged-in-only event has no real
        // first-party cookie value to put here (see the class docblock on why anonymous
        // cart-adds are out of scope for v1), so a stable synthetic value derived from the
        // customer_id stands in, same shape StitchVisitorIdentity's own backfill would produce
        // for this customer's other events.
        $this->visitorEventLogger->log('customer_' . $customerId, self::EVENT_TYPE, (string) $sku, $customerId);
    }
}
