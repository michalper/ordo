<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Push;

use Magento\Framework\Exception\AlreadyExistsException;
use Ordo\Automation\Model\PushSubscription;
use Ordo\Automation\Model\ResourceModel\PushSubscription as PushSubscriptionResource;
use Ordo\Automation\Model\ResourceModel\PushSubscription\CollectionFactory as PushSubscriptionCollectionFactory;

/**
 * CRUD for ordo_push_subscription — deliberately upsert-by-endpoint rather than always-insert:
 * a browser can silently rotate an existing subscription's endpoint/keys (key rotation, service
 * worker update) without ever calling unsubscribe() first, so re-registering the "same" logical
 * subscription must update the existing row, not accumulate duplicates. Endpoints are matched by
 * endpoint_hash (see db_schema.xml) since the raw endpoint is a `text` column MySQL can't
 * uniquely index directly.
 */
class PushSubscriptionManager
{
    /**
     * Caps how many distinct devices a single customer/visitor can register - without this, a
     * client hitting Controller\Track\RegisterPushSubscription in a loop with fabricated
     * endpoints could grow this table without bound, and (before Model\Push\PushEndpointValidator
     * existed) fan that out into real outbound HTTP calls on every future campaign send.
     */
    private const int MAX_SUBSCRIPTIONS_PER_OWNER = 20;

    public function __construct(
        private readonly PushSubscriptionResource $pushSubscriptionResource,
        private readonly PushSubscriptionCollectionFactory $collectionFactory
    ) {
    }

    public function register(
        string $endpoint,
        string $p256dhKey,
        string $authKey,
        ?int $customerId,
        ?string $visitorId
    ): void {
        $endpointHash = $this->hashEndpoint($endpoint);
        $now = date('Y-m-d H:i:s');

        $subscription = $this->findByEndpointHash($endpointHash);
        $isNew = !$subscription->getId();

        $this->populate(
            $subscription,
            $endpoint,
            $endpointHash,
            $p256dhKey,
            $authKey,
            $customerId,
            $isNew ? $visitorId : null,
            $now
        );

        try {
            $this->pushSubscriptionResource->save($subscription);
        } catch (AlreadyExistsException) {
            // Lost a race with a concurrent register() call for the same brand-new endpoint
            // (e.g. push-sw.js's own pushsubscriptionchange handler firing at the same moment
            // tracker.js's subscribeToPush() resolves) - the other call's insert already went
            // through, so re-load its row and update it instead of surfacing an error for what
            // is, from the visitor's point of view, a single successful registration.
            $subscription = $this->findByEndpointHash($endpointHash);
            $isNew = false;
            $this->populate($subscription, $endpoint, $endpointHash, $p256dhKey, $authKey, $customerId, null, $now);
            $this->pushSubscriptionResource->save($subscription);
        }

        if ($isNew) {
            $this->enforceSubscriptionCap($customerId, $visitorId, (int) $subscription->getId());
        }
    }

    /**
     * Only deletes a matching row that actually belongs to the caller (PushSubscription::
     * belongsTo()) - the endpoint alone is not proof of ownership: a request bearing an endpoint
     * it found or was given (logs, a shared device, a separate disclosure) must not be able to
     * delete a stranger's subscription.
     */
    public function unregister(string $endpoint, ?int $customerId, string $visitorId): void
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('endpoint_hash', $this->hashEndpoint($endpoint));
        foreach ($collection as $subscription) {
            /** @var PushSubscription $subscription */
            if ($subscription->belongsTo($customerId, $visitorId)) {
                $this->pushSubscriptionResource->delete($subscription);
            }
        }
    }

    /**
     * Called by StitchVisitorIdentity on login, same shape as
     * VisitorEventLogger::attributeVisitorToCustomer() - backfills customer_id onto this
     * visitor's previously-anonymous push subscriptions.
     */
    public function attributeVisitorToCustomer(string $visitorId, int $customerId): void
    {
        $collection = $this->collectionFactory->create();
        $collection->addVisitorFilter($visitorId);
        $collection->addFieldToFilter('customer_id', ['null' => true]);
        foreach ($collection as $subscription) {
            /** @var PushSubscription $subscription */
            $subscription->setCustomerId($customerId);
            $this->pushSubscriptionResource->save($subscription);
        }
    }

    /**
     * @return PushSubscription[] every subscription (across all of this customer's devices) that
     *     a "send_push" campaign action should deliver to.
     */
    public function getForCustomer(int $customerId): array
    {
        $collection = $this->collectionFactory->create();
        $collection->addCustomerFilter($customerId);

        /** @var PushSubscription[] $items */
        $items = array_values($collection->getItems());
        return $items;
    }

    public function delete(PushSubscription $subscription): void
    {
        $this->pushSubscriptionResource->delete($subscription);
    }

    /**
     * getFirstItem() always returns a model instance - a fresh, id-less one when nothing
     * matches, never false/null.
     */
    private function findByEndpointHash(string $endpointHash): PushSubscription
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('endpoint_hash', $endpointHash);

        /** @var PushSubscription $subscription */
        $subscription = $collection->getFirstItem();
        return $subscription;
    }

    private function populate(
        PushSubscription $subscription,
        string $endpoint,
        string $endpointHash,
        string $p256dhKey,
        string $authKey,
        ?int $customerId,
        ?string $visitorIdIfNew,
        string $now
    ): void {
        $subscription->setEndpoint($endpoint);
        $subscription->setEndpointHash($endpointHash);
        $subscription->setP256dhKey($p256dhKey);
        $subscription->setAuthKey($authKey);
        $subscription->setLastSeenAt($now);
        if ($customerId !== null) {
            // A previously-anonymous subscription becoming identified is a one-way transition -
            // never overwrite an already-known customer_id with null just because a later
            // register() call happened to run logged out (e.g. a second tab).
            $subscription->setCustomerId($customerId);
        }
        if (!$subscription->getId()) {
            $subscription->setVisitorId($visitorIdIfNew);
            $subscription->setCreatedAt($now);
        }
    }

    /**
     * Deletes the oldest (by last_seen_at) subscriptions belonging to the same owner
     * (customer_id if known, else visitor_id) beyond MAX_SUBSCRIPTIONS_PER_OWNER, keeping the
     * newly-created one - a customer/visitor registering one more device just quietly evicts
     * their own least-recently-active one rather than the request failing outright.
     */
    private function enforceSubscriptionCap(?int $customerId, ?string $visitorId, int $justCreatedId): void
    {
        $collection = $this->collectionFactory->create();
        if ($customerId !== null) {
            $collection->addCustomerFilter($customerId);
        } elseif ($visitorId !== null) {
            $collection->addVisitorFilter($visitorId);
        } else {
            return;
        }

        $collection->setOrder('last_seen_at', 'ASC');
        $overflow = count($collection->getItems()) - self::MAX_SUBSCRIPTIONS_PER_OWNER;
        if ($overflow <= 0) {
            return;
        }

        $deleted = 0;
        foreach ($collection as $subscription) {
            if ($deleted >= $overflow) {
                break;
            }
            /** @var PushSubscription $subscription */
            if ((int) $subscription->getId() === $justCreatedId) {
                continue;
            }
            $this->pushSubscriptionResource->delete($subscription);
            $deleted++;
        }
    }

    private function hashEndpoint(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }
}
