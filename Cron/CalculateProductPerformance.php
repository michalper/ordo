<?php
declare(strict_types=1);

namespace Ordo\Automation\Cron;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\FlagManager;
use Ordo\Automation\Model\Cron\CronRunLogger;

/**
 * Keeps ordo_product_performance.view_count current by reading ordo_visitor_event
 * incrementally — not a full rescan like CalculateReorderCycle, since this only needs a running
 * SUM per SKU, not per-order date history. Must stay well ahead of PruneVisitorEvents (runs once
 * daily at 04:00, this cron every 30 minutes — see etc/crontab.xml), or a view could be deleted
 * from ordo_visitor_event before ever being counted.
 *
 * Purchase counts are deliberately NOT computed here — sales_order_item is never pruned, so
 * Model\ResourceModel\ProductPerformance\Grid\Collection joins it live at report-render time
 * instead (same reasoning Model\Recommendation\ProductRecommender's own best-seller query
 * already relies on). See docs/adr/0015.
 */
class CalculateProductPerformance
{
    private const string TABLE = 'ordo_product_performance';
    private const string FLAG_CODE = 'ordo_automation_product_performance_watermark';
    private const int BATCH_SIZE = 1000;

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly FlagManager $flagManager,
        private readonly CronRunLogger $cronRunLogger
    ) {
    }

    public function execute(): void
    {
        $connection = $this->resourceConnection->getConnection();
        $eventTable = $this->resourceConnection->getTableName('ordo_visitor_event');

        $watermark = (int) ($this->flagManager->getFlagData(self::FLAG_CODE) ?? 0);
        $processed = 0;

        while (true) {
            $select = $connection->select()
                ->from($eventTable, ['entity_id', 'event_key'])
                ->where('event_type = ?', 'product_view')
                ->where('event_key IS NOT NULL')
                ->where('entity_id > ?', $watermark)
                ->order('entity_id ASC')
                ->limit(self::BATCH_SIZE);

            /** @var array<int, array{entity_id: int|string, event_key: string}> $rows */
            $rows = $connection->fetchAll($select);
            if ($rows === []) {
                break;
            }

            $viewCountsBySku = [];
            foreach ($rows as $row) {
                $viewCountsBySku[$row['event_key']] = ($viewCountsBySku[$row['event_key']] ?? 0) + 1;
                $watermark = max($watermark, (int) $row['entity_id']);
            }

            $this->flushCounts($viewCountsBySku);
            $processed += count($rows);

            $this->flagManager->saveFlag(self::FLAG_CODE, $watermark);

            if (count($rows) < self::BATCH_SIZE) {
                break;
            }
        }

        $this->cronRunLogger->logSummary(sprintf('counted %d product view(s)', $processed));
    }

    /**
     * @param array<string, int> $viewCountsBySku
     */
    private function flushCounts(array $viewCountsBySku): void
    {
        if ($viewCountsBySku === []) {
            return;
        }

        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);

        foreach ($viewCountsBySku as $sku => $count) {
            // Can't fold this into one multi-row insertOnDuplicate() the way
            // CalculateReorderCycle::flushCycles() does - ON DUPLICATE KEY UPDATE replaces
            // view_count with the row's own VALUES(), it doesn't add to the existing one, and a
            // SKU already present in ordo_product_performance must be incremented, not
            // overwritten. One upsert per SKU per batch (at most BATCH_SIZE distinct SKUs) is
            // still bounded, not a per-event query.
            $connection->query(
                sprintf(
                    'INSERT INTO %s (sku, view_count) VALUES (?, ?) '
                    . 'ON DUPLICATE KEY UPDATE view_count = view_count + VALUES(view_count)',
                    $connection->quoteIdentifier($table)
                ),
                [$sku, $count]
            );
        }
    }
}
