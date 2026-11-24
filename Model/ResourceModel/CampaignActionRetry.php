<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class CampaignActionRetry extends AbstractDb implements RetryRowResourceInterface
{
    use ClaimsRetryRowTrait;

    protected function _construct(): void
    {
        $this->_init('ordo_campaign_action_retry', 'entity_id');
    }

    /**
     * Raw JSON context blobs for every retry row currently queued against this campaign -
     * Model\Campaign\CampaignEntryGuard::hasPendingEntry() decodes these to also catch a
     * customer whose resume failed and is sitting here (its own ordo_campaign_scheduled_action
     * row's executed_at is already claimed, so that table's own "pending" filter alone can't see
     * it). There's no dedicated customer_id column on this table (see its own db_schema.xml
     * comment - customer_id lives inside the JSON context), and retry rows are rare dead-letter-
     * ish data, so decoding in PHP after one small fetch is simpler than adding a column just for
     * this.
     *
     * @return string[]
     */
    public function fetchContextsForCampaign(int $campaignId): array
    {
        $connection = $this->getConnection();

        $contexts = $connection->fetchCol(
            $connection->select()
                ->from($this->getMainTable(), 'context')
                ->where('campaign_id = ?', $campaignId)
        );

        return array_map(static fn (mixed $context): string => (string) $context, $contexts);
    }
}
