<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Queue;

use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\Serialize\SerializerInterface;

/**
 * VisitorEventLogger used to run VisitorAggregator's GROUP BY/HAVING query (plus any resulting
 * tag writes) synchronously, inline in the /ordo/track/event request or the customer_login
 * request — the same class of problem CampaignDispatchPublisher already solved for campaign
 * dispatch. This is that same fix applied here: hand off "go check this customer/visitor's
 * aggregation" to the queue and return immediately; VisitorAggregationConsumer does the actual
 * work off the request thread.
 */
class VisitorAggregationPublisher
{
    public const TOPIC = 'ordo.automation.visitor.aggregate';

    public function __construct(
        private readonly PublisherInterface $publisher,
        private readonly SerializerInterface $serializer
    ) {
    }

    public function publishForCustomer(int $customerId): void
    {
        $this->publisher->publish(self::TOPIC, $this->serializer->serialize(['customer_id' => $customerId]));
    }

    public function publishForVisitor(string $visitorId): void
    {
        $this->publisher->publish(self::TOPIC, $this->serializer->serialize(['visitor_id' => $visitorId]));
    }
}
