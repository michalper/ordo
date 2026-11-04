<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Email;

use Magento\Framework\App\ResourceConnection;

/**
 * "Did this customer open/click a specific campaign's emails within the last N days?" - the
 * `message_engaged` condition type (Model\Campaign\Condition\MessageEngaged) delegates to
 * hasEngaged(), the set-level counterpart Model\Segment\SegmentMemberResolver uses via
 * Api\Campaign\BulkResolvableConditionInterface delegates to getCustomerIdsWithEngagement().
 * Same single-customer-lookup + whole-customer-base-query pairing as
 * Model\Event\EventOccurredResolver.
 *
 * Queries the same ordo_message_log_event -> ordo_message_log join
 * Model\CampaignFunnelStats::getSendStats() already uses for the campaign edit page's own
 * open/click-rate reporting - that data is the source of truth this condition reads, not a
 * separate ledger.
 */
class MessageEngagementResolver
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    public function hasEngaged(int $customerId, int $campaignId, string $eventType, int $withinDays): bool
    {
        if ($customerId <= 0 || $campaignId <= 0 || $eventType === '' || $withinDays <= 0) {
            return false;
        }

        $connection = $this->resourceConnection->getConnection();

        $select = $connection->select()
            ->from(['e' => $this->resourceConnection->getTableName('ordo_message_log_event')], [])
            ->joinInner(
                ['l' => $this->resourceConnection->getTableName('ordo_message_log')],
                'l.entity_id = e.message_log_id',
                ['count' => new \Zend_Db_Expr('COUNT(*)')]
            )
            ->where('l.customer_id = ?', $customerId)
            ->where('l.campaign_id = ?', $campaignId)
            ->where('e.event_type = ?', $eventType)
            ->where('e.created_at >= ?', $this->cutoff($withinDays));

        return (int) $connection->fetchOne($select) > 0;
    }

    /**
     * Every customer_id who opened/clicked the given campaign within the window - the set-level
     * counterpart to hasEngaged(), used by SegmentMemberResolver so a "message_engaged" segment
     * condition resolves to a real membership list.
     *
     * @return int[]
     */
    public function getCustomerIdsWithEngagement(int $campaignId, string $eventType, int $withinDays): array
    {
        if ($campaignId <= 0 || $eventType === '' || $withinDays <= 0) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();

        $select = $connection->select()
            ->from(['e' => $this->resourceConnection->getTableName('ordo_message_log_event')], [])
            ->joinInner(
                ['l' => $this->resourceConnection->getTableName('ordo_message_log')],
                'l.entity_id = e.message_log_id',
                []
            )
            ->where('l.customer_id IS NOT NULL')
            ->where('l.campaign_id = ?', $campaignId)
            ->where('e.event_type = ?', $eventType)
            ->where('e.created_at >= ?', $this->cutoff($withinDays))
            ->distinct(true)
            ->columns('l.customer_id');

        return array_map(static fn ($id): int => (int) $id, $connection->fetchCol($select));
    }

    private function cutoff(int $withinDays): string
    {
        return date('Y-m-d H:i:s', (int) strtotime("-{$withinDays} days"));
    }
}
