<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Queue;

use Magento\Framework\Serialize\SerializerInterface;
use Ordo\Automation\Model\CustomerScoreManager;
use Ordo\Automation\Model\CustomerTagManager;
use Psr\Log\LoggerInterface;

/**
 * Consumer side of SegmentBulkActionPublisher — decodes the message and loops the already
 * resolved customer_ids, applying the requested action to each via the same single-customer
 * methods this module's cron jobs already call in a loop (see Cron\SendCreditLimitAlerts). One
 * customer's failure is logged and skipped, never aborts the rest of the batch.
 */
class SegmentBulkActionConsumer
{
    public const ACTION_ADD_TAG = 'add_tag';
    public const ACTION_ADD_POINTS = 'add_points';

    public function __construct(
        private readonly CustomerTagManager $customerTagManager,
        private readonly CustomerScoreManager $customerScoreManager,
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

        $applied = 0;

        foreach ($customerIds as $customerId) {
            try {
                $this->applyAction($actionType, (int) $customerId, $params);
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

    /**
     * @param array<string, mixed> $params
     */
    private function applyAction(string $actionType, int $customerId, array $params): void
    {
        switch ($actionType) {
            case self::ACTION_ADD_TAG:
                $this->customerTagManager->addTag($customerId, (string) ($params['tag'] ?? ''));
                break;
            case self::ACTION_ADD_POINTS:
                $this->customerScoreManager->addPoints($customerId, (int) ($params['points'] ?? 0));
                break;
            default:
                throw new \InvalidArgumentException(sprintf('Unknown bulk action type "%s".', $actionType));
        }
    }
}
