<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Ordo\Automation\Model\CampaignActionRetry as CampaignActionRetryModel;

class CampaignActionRetry extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('ordo_campaign_action_retry', 'entity_id');
    }

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
    public function claim(CampaignActionRetryModel $model, string $now, string $tentativeNextRetryAt): bool
    {
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
