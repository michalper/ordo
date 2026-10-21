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
 */
class SegmentBulkActionConsumer
{
    public const ACTION_ADD_TAG = 'add_tag';
    public const ACTION_ADD_POINTS = 'add_points';

    public function __construct(
        private readonly ActionPool $actionPool,
        private readonly SerializerInterface $serializer,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(string $message): void
    {
        $decoded = $this->serializer->unserialize($message);
        $actionType = (string) ($decoded['action_type'] ?? '');
        $params = (array) ($decoded['params'] ?? []);
        $customerIds = (array) ($decoded['customer_ids'] ?? []);

        if ($actionType === '' || $customerIds === []) {
            $this->logger->error(
                'Ordo_Automation: dropped a segment bulk action message with no action_type or customer_ids.'
            );
            return;
        }

        $action = $this->actionPool->get($actionType);
        if ($action === null) {
            $this->logger->error(sprintf(
                'Ordo_Automation: dropped a segment bulk action message with unknown action type "%s".',
                $actionType
            ));
            return;
        }

        $applied = 0;

        foreach ($customerIds as $customerId) {
            try {
                $context = ['customer_id' => (int) $customerId];
                $action->execute($context, $params);
                $applied++;
            } catch (\Throwable $e) {
                $this->logger->error(sprintf(
                    'Ordo_Automation: failed to apply bulk action "%s" to customer #%d: %s',
                    $actionType,
                    (int) $customerId,
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
