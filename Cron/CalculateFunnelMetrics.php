<?php
declare(strict_types=1);

namespace Ordo\Automation\Cron;

use Magento\Framework\App\ResourceConnection;
use Ordo\Automation\Model\Cron\CronRunLogger;

/**
 * Keeps ordo_funnel_daily_stats current by reading ordo_visitor_event before PruneVisitorEvents
 * deletes it (daily, ~7 day retention - see that table's own db_schema.xml comment), same
 * "materialize before the source is pruned" reasoning Cron\CalculateProductPerformance already
 * established for product views.
 *
 * Unlike CalculateProductPerformance's own irreversible, ever-advancing watermark, this
 * recomputes one whole calendar day from scratch (UPSERT), picking `today - self::SETTLE_DAYS`
 * rather than "yesterday" or "today" - a visitor-event row's own customer_id CAN change after the
 * fact (Observer\StitchVisitorIdentity backfills it on a later login), so reading/finalizing a
 * day too early would permanently undercount anyone who logged in after their first browsing
 * activity that same day. See docs/adr/0031 for the full reasoning and the deliberate scope
 * (registered customers only, every stage, not just the last one).
 *
 * No column/row for the "completed order" funnel stage - sales_order is never pruned, so
 * Model\ResourceModel\FunnelReport\Grid\Collection joins it live at report-render time instead,
 * the same precedent ProductPerformance\Grid\Collection already set for purchase counts.
 */
class CalculateFunnelMetrics
{
    private const string TABLE = 'ordo_funnel_daily_stats';
    private const int SETTLE_DAYS = 2;

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly CronRunLogger $cronRunLogger
    ) {
    }

    public function execute(): void
    {
        $date = date('Y-m-d', strtotime('-' . self::SETTLE_DAYS . ' days'));

        $connection = $this->resourceConnection->getConnection();
        $eventTable = $this->resourceConnection->getTableName('ordo_visitor_event');

        $counts = [
            'product_view_visitors' => $this->countDistinctCustomers($connection, $eventTable, 'product_view', $date),
            'cart_add_visitors' => $this->countDistinctCustomers($connection, $eventTable, 'cart_add', $date),
            'checkout_start_visitors' => $this->countDistinctCustomers($connection, $eventTable, 'checkout_start', $date),
        ];

        $table = $this->resourceConnection->getTableName(self::TABLE);
        $connection->query(
            sprintf(
                'INSERT INTO %s (date, product_view_visitors, cart_add_visitors, checkout_start_visitors) '
                . 'VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE '
                . 'product_view_visitors = VALUES(product_view_visitors), '
                . 'cart_add_visitors = VALUES(cart_add_visitors), '
                . 'checkout_start_visitors = VALUES(checkout_start_visitors)',
                $connection->quoteIdentifier($table)
            ),
            [$date, $counts['product_view_visitors'], $counts['cart_add_visitors'], $counts['checkout_start_visitors']]
        );

        $this->cronRunLogger->logSummary(sprintf(
            'recomputed funnel stats for %s: %d product_view, %d cart_add, %d checkout_start',
            $date,
            $counts['product_view_visitors'],
            $counts['cart_add_visitors'],
            $counts['checkout_start_visitors']
        ));
    }

    private function countDistinctCustomers(
        \Magento\Framework\DB\Adapter\AdapterInterface $connection,
        string $eventTable,
        string $eventType,
        string $date
    ): int {
        $select = $connection->select()
            ->from($eventTable, [new \Zend_Db_Expr('COUNT(DISTINCT customer_id)')])
            ->where('event_type = ?', $eventType)
            ->where('customer_id IS NOT NULL')
            ->where('DATE(created_at) = ?', $date);

        return (int) $connection->fetchOne($select);
    }
}
