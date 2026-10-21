<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel;

use Magento\Framework\Model\AbstractModel;
use Ordo\Automation\Model\RetryRecordInterface;

/**
 * Shared claim() for the three retry-row resources (CampaignActionRetry, MessageSendRetry,
 * PushSendRetry), each of which keeps its own table (ADRs 0003/0004) but claims identically. A
 * trait rather than a shared base class for the same reason Model\RetryRecordFieldsTrait is one:
 * these otherwise extend Magento's AbstractDb directly with no other common behavior.
 */
trait ClaimsRetryRowTrait
{
    /**
     * Claims a due row for this retry attempt as a single conditional UPDATE - the same
     * atomic-claim-before-execute pattern as ResourceModel\Campaign\ScheduledAction::claim(),
     * adapted for a row that gets retried more than once instead of claimed exactly once:
     * bumping next_retry_at forward immediately (to $tentativeNextRetryAt) is itself the claim,
     * since it takes the row out of any concurrent cron run's "due" filter (next_retry_at <=
     * $now) the instant this UPDATE commits. If the retry then fails, the caller corrects
     * next_retry_at to the real backoff delay afterwards; if it succeeds, the caller deletes the
     * row instead.
     */
    public function claim(
        AbstractModel&RetryRecordInterface $model,
        string $now,
        string $tentativeNextRetryAt
    ): bool {
        $connection = $this->getConnection();

        $affectedRows = $connection->update(
            $this->getMainTable(),
            ['next_retry_at' => $tentativeNextRetryAt],
            $connection->quoteInto('entity_id = ?', (int) $model->getEntityId())
                . $connection->quoteInto(' AND next_retry_at <= ?', $now)
        );

        if ($affectedRows > 0) {
            $model->setNextRetryAt($tentativeNextRetryAt);
        }

        return $affectedRows > 0;
    }
}
