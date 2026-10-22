<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\PriceWatch;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Framework\Exception\AlreadyExistsException;
use Ordo\Automation\Model\ResourceModel\PriceWatch\PriceWatchSubscription as PriceWatchSubscriptionResource;
use Ordo\Automation\Model\ResourceModel\PriceWatch\PriceWatchSubscription\CollectionFactory;

/**
 * CRUD for ordo_price_watch_subscription — idempotent by (customer_id or visitor_id) +
 * product_id + watch_type, same "look up first, update if found" shape
 * Push\PushSubscriptionManager uses for endpoint_hash. register()/unregister() don't need
 * AlreadyExistsException race handling: both race on a unique index scoped to an identity
 * already known to the caller (session/cookie), not on server-generated data an attacker could
 * fabricate, so the window is narrower and re-registering is harmless either way.
 * attributeVisitorToCustomer() is the exception — see its own docblock.
 */
class PriceWatchSubscriptionManager
{
    public function __construct(
        private readonly PriceWatchSubscriptionResource $priceWatchSubscriptionResource,
        private readonly CollectionFactory $collectionFactory,
        private readonly ProductRepositoryInterface $productRepository
    ) {
    }

    /**
     * Registers (or re-arms) a watch. Re-registering an existing watch refreshes its captured
     * last_known_price/last_known_in_stock to the product's current state and clears
     * notified_at — a customer explicitly re-subscribing after already being notified once
     * wants to be notified again on the next real change, not have that earlier claim silently
     * suppress it forever.
     */
    public function register(
        int $productId,
        string $watchType,
        ?int $customerId,
        ?string $visitorId,
        ?string $guestEmail = null
    ): void {
        $subscription = $this->findExisting($productId, $watchType, $customerId, $visitorId);
        $isNew = !$subscription->getId();

        /** @var Product $product ProductRepositoryInterface::getById() always returns the
         *  concrete Product model in practice — only its interface is declared. */
        $product = $this->productRepository->getById($productId);

        $subscription->setProductId($productId);
        $subscription->setWatchType($watchType);
        $subscription->setLastKnownPrice((float) $product->getFinalPrice());
        $subscription->setLastKnownInStock($product->isSalable());
        $subscription->setNotifiedAt(null);

        if ($isNew) {
            $subscription->setCustomerId($customerId);
            $subscription->setVisitorId($customerId === null ? $visitorId : null);
            $subscription->setGuestEmail($customerId === null ? $guestEmail : null);
            $subscription->setCreatedAt(date('Y-m-d H:i:s'));
        } else {
            if ($customerId !== null) {
                // Same one-way transition PushSubscriptionManager::populate() applies — a
                // previously anonymous watch becoming identified is never reversed back to null.
                $subscription->setCustomerId($customerId);
            }
            if ($customerId === null && $guestEmail !== null) {
                // A re-registration only ever refreshes the guest email when a new one is
                // actually given - never erases an address already on file just because a later
                // call happened not to include one.
                $subscription->setGuestEmail($guestEmail);
            }
        }

        $this->priceWatchSubscriptionResource->save($subscription);
    }

    public function unregister(int $productId, string $watchType, ?int $customerId, ?string $visitorId): void
    {
        $subscription = $this->findExisting($productId, $watchType, $customerId, $visitorId);
        if ($subscription->getId()) {
            $this->priceWatchSubscriptionResource->delete($subscription);
        }
    }

    /**
     * Called by StitchVisitorIdentity on login, same shape as
     * PushSubscriptionManager::attributeVisitorToCustomer() - except this table's unique index is
     * (customer_id, product_id, watch_type), not a server-generated value like endpoint_hash, so
     * a customer who already watches the same product/type under their own account (from an
     * earlier logged-in session) collides with the anonymous visitor row being attributed here.
     * The anonymous watch is redundant once that's true - drop it rather than let save() throw.
     */
    public function attributeVisitorToCustomer(string $visitorId, int $customerId): void
    {
        $collection = $this->collectionFactory->create();
        $collection->addVisitorFilter($visitorId);
        $collection->addFieldToFilter('customer_id', ['null' => true]);
        foreach ($collection as $subscription) {
            /** @var PriceWatchSubscription $subscription */
            $productId = $subscription->getProductId();
            $watchType = $subscription->getWatchType();

            $existing = $this->findExisting($productId, $watchType, $customerId, null);
            if ($existing->getId()) {
                $this->priceWatchSubscriptionResource->delete($subscription);
                continue;
            }

            $subscription->setCustomerId($customerId);
            try {
                $this->priceWatchSubscriptionResource->save($subscription);
            } catch (AlreadyExistsException) {
                // Another request attributed (or the customer re-registered) the same
                // product/type between the check above and this save - the customer-owned row
                // already covers it, so the now-redundant visitor row is dropped, not retried.
                $this->priceWatchSubscriptionResource->delete($subscription);
            }
        }
    }

    /**
     * getFirstItem() always returns a model instance - a fresh, id-less one when nothing
     * matches, never false/null.
     */
    private function findExisting(
        int $productId,
        string $watchType,
        ?int $customerId,
        ?string $visitorId
    ): PriceWatchSubscription {
        $collection = $this->collectionFactory->create();
        $collection->addProductFilter($productId);
        $collection->addWatchTypeFilter($watchType);
        if ($customerId !== null) {
            $collection->addCustomerFilter($customerId);
        } elseif ($visitorId !== null) {
            $collection->addVisitorFilter($visitorId);
        }

        /** @var PriceWatchSubscription $subscription */
        $subscription = $collection->getFirstItem();
        return $subscription;
    }
}
