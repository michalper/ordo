<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Queue;

use Magento\Framework\Serialize\SerializerInterface;
use Ordo\Automation\Model\VisitorAggregator;
use Psr\Log\LoggerInterface;

/**
 * Consumer side of VisitorAggregationPublisher — decodes the message and runs the same
 * VisitorAggregator call VisitorEventLogger/StitchVisitorIdentity used to make directly, just
 * off the request thread. A malformed message, or a genuine failure aggregating it (a DB
 * hiccup), is logged and dropped, not requeued — same fail-closed pattern as
 * CampaignDispatchConsumer, minus that one's own additional dead-letter persistence (visitor
 * aggregation is a recomputable cache, not a one-shot campaign trigger - losing one message here
 * self-heals on the next qualifying event, so logging is enough).
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
        try {
            $decoded = $this->serializer->unserialize($message);

            if (isset($decoded['customer_id'])) {
                $this->visitorAggregator->aggregateForCustomer((int) $decoded['customer_id']);
                return;
            }

            if (isset($decoded['visitor_id']) && (string) $decoded['visitor_id'] !== '') {
                $this->visitorAggregator->aggregateForVisitor((string) $decoded['visitor_id']);
                return;
            }
        } catch (\Throwable $e) {
            $this->logger->error(sprintf(
                'Ordo_Automation: dropped a visitor aggregation message: %s',
                $e->getMessage()
            ));
            return;
        }

        $this->logger->error(
            'Ordo_Automation: dropped a visitor aggregation message with no customer_id or visitor_id.'
        );
    }
}
