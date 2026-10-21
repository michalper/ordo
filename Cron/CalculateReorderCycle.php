<?php
declare(strict_types=1);

namespace Ordo\Automation\Cron;

use Magento\Framework\App\ResourceConnection;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Cron\CronRunLogger;
use Ordo\Automation\Model\ResourceModel\ReorderCycle as ReorderCycleResource;

/**
 * Detects, per registered customer and SKU, a recurring purchase pattern from order history
 * and stores the predicted next order date. This is the data foundation the reminder cron
 * (SendReorderReminders) reads from — it never emails anyone by itself.
 *
 * Also invoked synchronously, outside the normal schedule, by
 * Controller\Adminhtml\ReorderCycle\RecalculateNow — same "on-demand refresh" pattern
 * Controller\Adminhtml\ProductFeed\RefreshNow already established for the shopping feed cron.
 */
class CalculateReorderCycle
{
    private const int LOOKBACK_ORDERS_PER_SKU = 10;

    private const int UPSERT_BATCH_SIZE = 500;

    /**
     * Bounds the SQL scan itself to recent order history, not just the in-PHP slicing
     * LOOKBACK_ORDERS_PER_SKU already does after the fact - without this, every single cron run
     * re-fetches every (customer, sku, order date) row ever placed, regardless of age, which
     * scales with total historical order volume rather than recent activity (ROADMAP.md Tier 4:
     * "unbounded full-table scans ... real memory-exhaustion risk on large stores"). Two years is
     * generous slack past any realistic reorder cadence this module could still usefully act on -
     * a customer whose last order predates this window has, by definition, gone quiet longer than
     * any detected cycle would have predicted, so their older rows contribute nothing a fresher
     * cutoff would have missed for an actually-still-reordering customer.
     */
    private const int MAX_LOOKBACK_DAYS = 730;

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly ReorderCycleResource $reorderCycleResource,
        private readonly CronRunLogger $cronRunLogger,
        private readonly Config $config
    ) {
    }

    /**
     * @return int Number of reorder cycles (customer_id, sku) recalculated — surfaced back to
     *     RecalculateNow's success message; the cron itself only logs it via CronRunLogger.
     */
    public function execute(): int
    {
        $connection = $this->resourceConnection->getConnection();
        $orderTable = $this->resourceConnection->getTableName('sales_order');
        $orderItemTable = $this->resourceConnection->getTableName('sales_order_item');

        // One row per (customer_id, sku, order created_at) — only orders placed by
        // registered customers (guest checkouts have no reliable identity to target).
        $select = $connection->select()
            ->from(['o' => $orderTable], ['customer_id'])
            ->joinInner(
                ['oi' => $orderItemTable],
                'oi.order_id = o.entity_id',
                ['sku' => 'oi.sku', 'created_at' => 'o.created_at']
            )
            ->where('o.customer_id IS NOT NULL')
            ->where('o.state != ?', 'canceled')
            ->where('o.created_at >= ?', $this->lookbackCutoff())
            ->order(['o.customer_id ASC', 'oi.sku ASC', 'o.created_at ASC']);

        /** @var array<int, array{customer_id: int|string, sku: string, created_at: string}> $rows */
        $rows = $connection->fetchAll($select);

        /** @var array<string, array<int, string>> $ordersByCustomerSku */
        $ordersByCustomerSku = [];
        foreach ($rows as $row) {
            $key = $row['customer_id'] . '|' . $row['sku'];
            $ordersByCustomerSku[$key][] = $row['created_at'];
        }

        // Admin-configurable (Stores → Configuration → Ordo Automation → Reorder, default 3) —
        // this was a hardcoded constant for a while, leaving the admin field silently ignored.
        $minOrders = max(2, $this->config->getReorderMinOrders());

        $processed = 0;
        $pendingRows = [];
        foreach ($ordersByCustomerSku as $key => $dates) {
            if (count($dates) < $minOrders) {
                continue;
            }

            [$customerId, $sku] = explode('|', $key, 2);
            $dates = array_slice($dates, -self::LOOKBACK_ORDERS_PER_SKU);

            // $dates always has >= $minOrders (>= 2, clamped above) entries here, so this loop
            // always runs at least once and $intervals is never empty — no empty-guard needed.
            $intervals = [];
            for ($i = 1, $count = count($dates); $i < $count; $i++) {
                $intervals[] = ((int) strtotime($dates[$i]) - (int) strtotime($dates[$i - 1])) / 86400;
            }

            // Median rather than a plain arithmetic mean: a single anomalous gap (a customer
            // pausing for months, or a one-off bulk restock that skips several normal cycles)
            // would otherwise skew the whole prediction disproportionately, since the mean has
            // no resistance to outliers. The median is the simplest well-understood estimator
            // that doesn't have that problem, and needs nothing beyond a sort here.
            sort($intervals);
            $intervalCount = count($intervals);
            $middle = intdiv($intervalCount, 2);
            $median = $intervalCount % 2 === 0
                ? ($intervals[$middle - 1] + $intervals[$middle]) / 2
                : $intervals[$middle];

            $avgIntervalDays = (int) round($median);
            if ($avgIntervalDays < 1) {
                // Same-day repeat purchases don't make sense as a "reorder cycle" — skip.
                continue;
            }

            // end() only returns false on an empty array, which $dates never is here (the
            // count() guard above and array_slice() both preserve that) — PHPStan can't verify
            // that itself, so the cast documents it instead of reintroducing a dead check.
            $lastOrderDate = (string) end($dates);
            $nextExpectedDate = date('Y-m-d', (int) strtotime($lastOrderDate . ' + ' . $avgIntervalDays . ' days'));

            $pendingRows[] = [
                'customer_id' => (int) $customerId,
                'sku' => $sku,
                'avg_interval_days' => $avgIntervalDays,
                'last_order_date' => date('Y-m-d', (int) strtotime($lastOrderDate)),
                'next_expected_date' => $nextExpectedDate,
                'orders_considered' => count($dates),
            ];
            $processed++;

            if (count($pendingRows) >= self::UPSERT_BATCH_SIZE) {
                $this->flushCycles($pendingRows);
                $pendingRows = [];
            }
        }
        $this->flushCycles($pendingRows);

        $this->cronRunLogger->logSummary(sprintf('recalculated %d reorder cycles', $processed));

        return $processed;
    }

    private function lookbackCutoff(): string
    {
        return date('Y-m-d H:i:s', strtotime('-' . self::MAX_LOOKBACK_DAYS . ' days'));
    }

    /**
     * One multi-row INSERT ... ON DUPLICATE KEY UPDATE per batch against the
     * (customer_id, sku) unique key, instead of the previous SELECT-id + load() + save()
     * triple per row — same approach ResourceModel\Campaign\ScheduledTriggerState::markFired()
     * already uses.
     *
     * @param array<int, array<string, int|string>> $rows
     */
    private function flushCycles(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $this->resourceConnection->getConnection()->insertOnDuplicate(
            $this->reorderCycleResource->getMainTable(),
            $rows,
            ['avg_interval_days', 'last_order_date', 'next_expected_date', 'orders_considered']
        );
    }
}
