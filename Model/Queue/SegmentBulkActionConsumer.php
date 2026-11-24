<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Queue;

use Magento\Framework\Serialize\SerializerInterface;
use Ordo\Automation\Model\Campaign\ActionPool;
use Psr\Log\LoggerInterface;

/**
 * Consumer side of SegmentBulkActionPublisher — decodes the message and loops the already
 * resolved customer_ids, applying the requested action to each through the same ActionPool
 * registry CampaignDispatcher runs actions from, so the bulk path shares the registered
 * action's own input validation instead of re-implementing add_tag/add_points inline. One
 * customer's failure is logged and skipped, never aborts the rest of the batch.
 *
 * Each customer is claimed (SegmentBulkActionClaimStore, keyed by the message's own batch_id)
 * before the action runs - closes a real redelivery gap: Magento's queue can redeliver a message
 * after a consumer crash (worker killed mid-batch, deploy, OOM), and this loop has no other
 * checkpoint, so a redelivered chunk would otherwise re-apply the action (e.g. add_points, purely
 * additive) to every customer already processed on the crashed attempt, not just the ones never
 * reached.
 */
class SegmentBulkActionConsumer
{
    public const ACTION_ADD_TAG = 'add_tag';
    public const ACTION_ADD_POINTS = 'add_points';

    public function __construct(
        private readonly ActionPool $actionPool,
        private readonly SerializerInterface $serializer,
        private readonly LoggerInterface $logger,
        private readonly SegmentBulkActionClaimStore $claimStore
    ) {
    }

    public function execute(string $message): void
    {
        try {
            $decoded = $this->serializer->unserialize($message);
        } catch (\Throwable $e) {
            // Same fail-closed shape CampaignDispatchConsumer/VisitorAggregationConsumer already
            // have for an undecodable message - logged and dropped, not requeued or left to
            // crash the consumer uncaught. Every per-customer failure below this point already
            // has its own try/catch; only the decode step itself was missing one.
            $this->logger->error(sprintf(
                'Ordo_Automation: dropped an undecodable segment bulk action message: %s',
                $e->getMessage()
            ));
            return;
        }

        $actionType = (string) ($decoded['action_type'] ?? '');
        $params = (array) ($decoded['params'] ?? []);
        $customerIds = (array) ($decoded['customer_ids'] ?? []);
        $batchId = (string) ($decoded['batch_id'] ?? '');

        if ($actionType === '' || $customerIds === [] || $batchId === '') {
            $this->logger->error(
                'Ordo_Automation: dropped a segment bulk action message with no action_type, '
                . 'customer_ids, or batch_id.'
            );
            return;
        }

        $action = $this->actionPool->get($actionType);
        if (!$action instanceof \Ordo\Automation\Api\Campaign\ActionInterface) {
            $this->logger->error(sprintf(
                'Ordo_Automation: dropped a segment bulk action message with unknown action type "%s".',
                $actionType
            ));
            return;
        }

        $applied = 0;

        foreach ($customerIds as $customerId) {
            $customerId = (int) $customerId;

            if (!$this->claimStore->claim($batchId, $customerId)) {
                // Already applied on an earlier (now-redelivered) attempt at this exact message -
                // must not run the action again.
                continue;
            }

            try {
                $context = ['customer_id' => $customerId];
                $action->execute($context, $params);
                $applied++;
            } catch (\Throwable $e) {
                // Roll back the claim just made above - otherwise a redelivery of this exact
                // message (the real scenario this claim store exists for) would see this
                // customer as already claimed and skip them forever, even though the action
                // never actually ran for them.
                $this->claimStore->unclaim($batchId, $customerId);
                $this->logger->error(sprintf(
                    'Ordo_Automation: failed to apply bulk action "%s" to customer #%d: %s',
                    $actionType,
                    $customerId,
                    $e->getMessage()
                ));
            }
        }

        $this->logger->info(sprintf(
            'Ordo_Automation: applied bulk action "%s" to %d/%d segment members.',
            $actionType,
            $applied,
            count($customerIds)
        ));
    }
}
