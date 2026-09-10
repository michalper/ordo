<?php
declare(strict_types=1);

namespace Ordo\Automation\Observer;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Event\Observer as EventObserver;
use Magento\Framework\Event\ObserverInterface;
use Magento\Wishlist\Model\Item as WishlistItem;
use Ordo\Automation\Model\VisitorEventLogger;

/**
 * Fires on wishlist_product_add_after (Magento\Wishlist\Model\Wishlist::addNewItem(), the
 * standard "add to wishlist" entry point every storefront add-to-wishlist flow goes through -
 * NOT the lower-level wishlist_add_item, which can fire more than once per request as items get
 * assembled) - logs one `wishlist_add` row per added item into ordo_visitor_event.
 *
 * A Magento wishlist itself requires a logged-in customer to exist at all (there's no anonymous
 * wishlist concept in core), so - unlike TrackCartAdd - there's no logged-in-only carve-out to
 * make here; this observer would simply never fire for a guest.
 *
 * event_key is the SKU, not the product_id - see TrackCartAdd's own docblock for why.
 */
class TrackWishlistAdd implements ObserverInterface
{
    private const string EVENT_TYPE = 'wishlist_add';

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

        $customerId = (int) $this->customerSession->getCustomerId();
        $items = $observer->getEvent()->getItems();
        if (!is_array($items) && !$items instanceof \Traversable) {
            return;
        }

        foreach ($items as $item) {
            if (!$item instanceof WishlistItem) {
                continue;
            }

            $sku = $item->getProduct()->getSku();
            if (!$sku) {
                continue;
            }

            // Same synthetic visitor_id reasoning as TrackCartAdd - see that class's own
            // docblock.
            $this->visitorEventLogger->log('customer_' . $customerId, self::EVENT_TYPE, (string) $sku, $customerId);
        }
    }
}
