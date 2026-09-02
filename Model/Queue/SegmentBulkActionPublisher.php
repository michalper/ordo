<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Queue;

use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\Serialize\SerializerInterface;

/**
 * Publishes a bulk action ("add_tag"/"add_points") to run against a fixed, already-resolved
 * list of customer IDs. The admin controller resolves segment membership synchronously
 * (SegmentMemberResolver) before publishing, so this message carries the customer_ids directly
 * instead of a segment_id the consumer would have to re-resolve — that keeps "queued for N
 * customers" accurate at publish time and keeps SegmentMemberResolver out of the consumer's
 * dependency graph entirely, same trade-off CampaignDispatchPublisher makes for context.
 */
class SegmentBulkActionPublisher
{
    public const TOPIC = 'ordo.automation.segment.bulk_action';

    public function __construct(
        private readonly PublisherInterface $publisher,
        private readonly SerializerInterface $serializer
    ) {
    }

    /**
     * @param array<string, mixed> $params
     * @param int[] $customerIds
     */
    public function publish(int $segmentId, string $actionType, array $params, array $customerIds): void
    {
        $payload = $this->serializer->serialize([
            'segment_id' => $segmentId,
            'action_type' => $actionType,
            'params' => $params,
            'customer_ids' => array_values($customerIds),
        ]);

        $this->publisher->publish(self::TOPIC, $payload);
    }
}
