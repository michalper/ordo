<?php
declare(strict_types=1);

namespace Ordo\Automation\Observer;

use Magento\Framework\Event\Observer as EventObserver;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Ordo\Automation\Model\PriceWatch\PriceWatchSubscriptionManager;
use Ordo\Automation\Model\Push\PushSubscriptionManager;
use Ordo\Automation\Model\VisitorEventLogger;

/**
 * Fires on login: reads the anonymous visitor cookie the JS tracker set before this person
 * ever had a customer_id, and backfills their pre-login events with the now-known customer_id
 * — attributeVisitorToCustomer() itself publishes the aggregation check (see
 * Model\Queue\VisitorAggregationPublisher), so any threshold already crossed while browsing
 * anonymously still turns into a tag, just off the request thread rather than blocking login.
 *
 * Also stitches any push subscription(s) (Model\Push\PushSubscriptionManager::
 * attributeVisitorToCustomer()) and price-watch subscription(s) (Model\PriceWatch\
 * PriceWatchSubscriptionManager::attributeVisitorToCustomer()) this browser registered before
 * login, so a "send_push"/`price_drop`/`back_in_stock` campaign trigger right after this login
 * can already reach this device/watch.
 */
class StitchVisitorIdentity implements ObserverInterface
{
    public const COOKIE_NAME = 'ordo_visitor_id';

    public function __construct(
        private readonly CookieManagerInterface $cookieManager,
        private readonly VisitorEventLogger $visitorEventLogger,
        private readonly PushSubscriptionManager $pushSubscriptionManager,
        private readonly PriceWatchSubscriptionManager $priceWatchSubscriptionManager
    ) {
    }

    public function execute(EventObserver $observer): void
    {
        $visitorId = $this->cookieManager->getCookie(self::COOKIE_NAME);
        if (!$visitorId) {
            return;
        }

        $customer = $observer->getEvent()->getCustomer();
        if (!$customer || !$customer->getId()) {
            return;
        }

        $this->visitorEventLogger->attributeVisitorToCustomer($visitorId, (int) $customer->getId());
        $this->pushSubscriptionManager->attributeVisitorToCustomer($visitorId, (int) $customer->getId());
        $this->priceWatchSubscriptionManager->attributeVisitorToCustomer($visitorId, (int) $customer->getId());
    }
}
