<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Queue;

use Magento\Framework\DataObject\IdentityGeneratorInterface;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\Serialize\SerializerInterface;

/**
 * Publishes a bulk action ("add_tag"/"add_points") to run against a fixed, already-resolved
 * list of customer IDs. The admin controller resolves segment membership synchronously
 * (SegmentMemberResolver) before publishing, so each message carries its customer_ids directly
 * instead of a segment_id the consumer would have to re-resolve — that keeps "queued for N
 * customers" accurate at publish time and keeps SegmentMemberResolver out of the consumer's
 * dependency graph entirely, same trade-off CampaignDispatchPublisher makes for context.
 *
 * The ID list is split into CHUNK_SIZE-sized messages rather than one giant message: a segment
 * matching thousands of customers would otherwise mean a single consumer invocation running one
 * very long, uncheckpointed loop — if the worker process dies partway (deploy, OOM, restart),
 * everything after the last completed iteration is lost with nothing left to retry it. Chunked,
 * each message is its own short-lived unit of work; losing one loses at most CHUNK_SIZE
 * customers' worth of progress, not the whole batch.
 *
 * Each message also carries a fresh batch_id (not derived from segment_id/action_type, which can
 * legitimately repeat across unrelated bulk actions) - SegmentBulkActionConsumer claims
 * (batch_id, customer_id) pairs via SegmentBulkActionClaimStore before acting, so a redelivery of
 * this exact message (the queue can redeliver after a consumer crash) can never re-apply the
 * action to a customer already processed on an earlier attempt.
 */
class SegmentBulkActionPublisher
{
    public const TOPIC = 'ordo.automation.segment.bulk_action';
    public const CHUNK_SIZE = 500;

    public function __construct(
        private readonly PublisherInterface $publisher,
        private readonly SerializerInterface $serializer,
        private readonly IdentityGeneratorInterface $identityGenerator
    ) {
    }

    /**
     * @param array<string, mixed> $params
     * @param int[] $customerIds
     */
    public function publish(int $segmentId, string $actionType, array $params, array $customerIds): void
    {
        foreach (array_chunk(array_values($customerIds), self::CHUNK_SIZE) as $chunk) {
            $payload = $this->serializer->serialize([
                'segment_id' => $segmentId,
                'action_type' => $actionType,
                'params' => $params,
                'customer_ids' => $chunk,
                // Identifies this one message, not the segment/action - the same segment+action
                // can legitimately be re-run later as a fresh bulk action, with a fresh batch_id.
                // The consumer claims (batch_id, customer_id) pairs before acting, so a redelivery
                // of this exact message (consumer crash before ack) never re-applies the action to
                // a customer already processed on an earlier attempt. See
                // Model\Queue\SegmentBulkActionClaimStore.
                'batch_id' => $this->identityGenerator->generateId(),
            ]);

            $this->publisher->publish(self::TOPIC, $payload);
        }
    }
}
