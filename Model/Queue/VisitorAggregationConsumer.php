<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Queue;

use Magento\Framework\Serialize\SerializerInterface;
use Ordo\Automation\Model\VisitorAggregator;
use Psr\Log\LoggerInterface;

/**
 * Consumer side of VisitorAggregationPublisher — decodes the message and runs the same
 * VisitorAggregator call VisitorEventLogger/StitchVisitorIdentity used to make directly, just
 * off the request thread. A malformed message is logged and dropped, not requeued — same
 * fail-closed pattern as CampaignDispatchConsumer.
 */
class VisitorAggregationConsumer
{
    public function __construct(
        private readonly VisitorAggregator $visitorAggregator,
        private readonly SerializerInterface $serializer,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(string $message): void
    {
        $decoded = $this->serializer->unserialize($message);

        if (isset($decoded['customer_id'])) {
            $this->visitorAggregator->aggregateForCustomer((int) $decoded['customer_id']);
            return;
        }

        if (isset($decoded['visitor_id']) && (string) $decoded['visitor_id'] !== '') {
            $this->visitorAggregator->aggregateForVisitor((string) $decoded['visitor_id']);
            return;
        }

        $this->logger->error('Ordo_Automation: dropped a visitor aggregation message with no customer_id or visitor_id.');
    }
}
