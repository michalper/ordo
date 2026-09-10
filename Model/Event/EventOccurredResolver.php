<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Event;

use Magento\Framework\App\ResourceConnection;

/**
 * "Has this customer had event X (optionally for a specific event_key, e.g. a SKU) within the
 * last N days?" - the `event_occurred` condition type (Model\Campaign\Condition\EventOccurred)
 * delegates to hasEventOccurred(), the set-level counterpart Model\Segment\SegmentMemberResolver
 * uses for the same type delegates to getCustomerIdsWithEvent(). Same
 * single-customer-lookup + whole-customer-base-query pairing as
 * Model\Purchase\PurchasedProductResolver, queried live against ordo_visitor_event every time
 * (no separate ledger) the same way that class queries sales_order_item live.
 *
 * Important retention caveat: Cron\PruneVisitorEvents deletes ordo_visitor_event rows older than
 * the configured retention window (Helper\Config::getTrackingRetentionDays(), default 7 days) -
 * a `within_days` value larger than that retention window will silently stop matching rows that
 * have already been pruned. Not validated/capped here in this first pass; the admin form field
 * (Block\Adminhtml\Campaign\Edit\Flow's own field descriptor) carries a notice about it instead.
 */
class EventOccurredResolver
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    public function hasEventOccurred(int $customerId, string $eventType, ?string $eventKey, int $withinDays): bool
    {
        if ($customerId <= 0 || $eventType === '' || $withinDays <= 0) {
            return false;
        }

        $connection = $this->resourceConnection->getConnection();

        $select = $connection->select()
            ->from(
                ['e' => $this->resourceConnection->getTableName('ordo_visitor_event')],
                ['count' => new \Zend_Db_Expr('COUNT(*)')]
            )
            ->where('e.customer_id = ?', $customerId)
            ->where('e.event_type = ?', $eventType)
            ->where('e.created_at >= ?', $this->cutoff($withinDays));

        if ($eventKey !== null && $eventKey !== '') {
            $select->where('e.event_key = ?', $eventKey);
        }

        return (int) $connection->fetchOne($select) > 0;
    }

    /**
     * Every customer_id with at least one matching event within the window - the set-level
     * counterpart to hasEventOccurred(), used by SegmentMemberResolver so an "event_occurred"
     * segment condition resolves to a real membership list.
     *
     * @return int[]
     */
    public function getCustomerIdsWithEvent(string $eventType, ?string $eventKey, int $withinDays): array
    {
        if ($eventType === '' || $withinDays <= 0) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();

        $select = $connection->select()
            ->from(['e' => $this->resourceConnection->getTableName('ordo_visitor_event')], [])
            ->where('e.customer_id IS NOT NULL')
            ->where('e.event_type = ?', $eventType)
            ->where('e.created_at >= ?', $this->cutoff($withinDays))
            ->distinct(true)
            ->columns('e.customer_id');

        if ($eventKey !== null && $eventKey !== '') {
            $select->where('e.event_key = ?', $eventKey);
        }

        return array_map(static fn ($id): int => (int) $id, $connection->fetchCol($select));
    }

    private function cutoff(int $withinDays): string
    {
        return date('Y-m-d H:i:s', (int) strtotime("-{$withinDays} days"));
    }
}
