<?php
declare(strict_types=1);

namespace Ordo\Automation\Observer;

use Magento\Framework\Event\Observer as EventObserver;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Ordo\Automation\Model\VisitorAggregator;
use Ordo\Automation\Model\VisitorEventLogger;

/**
 * Fires on login: reads the anonymous visitor cookie the JS tracker set before this person
 * ever had a customer_id, backfills their pre-login events with the now-known customer_id,
 * and runs aggregation immediately so any threshold already crossed while browsing anonymously
 * turns into a tag right away instead of waiting for the next event or a cron.
 */
class StitchVisitorIdentity implements ObserverInterface
{
    public const COOKIE_NAME = 'ordo_visitor_id';

    public function __construct(
        private readonly CookieManagerInterface $cookieManager,
        private readonly VisitorEventLogger $visitorEventLogger,
        private readonly VisitorAggregator $visitorAggregator
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

        $customerId = (int) $customer->getId();
        $this->visitorEventLogger->attributeVisitorToCustomer($visitorId, $customerId);
        $this->visitorAggregator->aggregateForCustomer($customerId);
    }
}
