<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Track;

use Magento\Framework\App\ResourceConnection;

/**
 * A live COUNT(*) against ordo_visitor_event, not Cron\CalculateProductPerformance's own
 * ordo_product_performance.view_count - that table is a running all-time total with no date
 * bucket at all, so it can't answer "how many views today" the social proof widget needs. Safe
 * to query ordo_visitor_event directly despite Cron\PruneVisitorEvents' own pruning: "today" is
 * always well inside the shortest realistic retention window (PruneVisitorEvents defaults to 7
 * days), unlike the product performance report's own reasoning for materializing instead of
 * reading this table live.
 */
class ProductViewCounter
{
    private const string EVENT_TYPE_PRODUCT_VIEW = 'product_view';

    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    public function countViewsToday(string $sku): int
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('ordo_visitor_event');

        $select = $connection->select()
            ->from($table, ['COUNT(*)'])
            ->where('event_type = ?', self::EVENT_TYPE_PRODUCT_VIEW)
            ->where('event_key = ?', $sku)
            ->where('created_at >= ?', date('Y-m-d 00:00:00'));

        return (int) $connection->fetchOne($select);
    }
}
