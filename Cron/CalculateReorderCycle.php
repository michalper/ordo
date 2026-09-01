<?php
declare(strict_types=1);

namespace Ordo\Automation\Cron;

use Magento\Framework\App\ResourceConnection;
use Ordo\Automation\Model\ReorderCycleFactory;
use Ordo\Automation\Model\ResourceModel\ReorderCycle as ReorderCycleResource;
use Psr\Log\LoggerInterface;

/**
 * Detects, per registered customer and SKU, a recurring purchase pattern from order history
 * and stores the predicted next order date. This is the data foundation the reminder cron
 * (SendReorderReminders) reads from — it never emails anyone by itself.
 */
class CalculateReorderCycle
{
    private const MIN_ORDERS_TO_DETECT_PATTERN = 3;
    private const LOOKBACK_ORDERS_PER_SKU = 10;

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly ReorderCycleFactory $reorderCycleFactory,
        private readonly ReorderCycleResource $reorderCycleResource,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        $connection = $this->resourceConnection->getConnection();
        $orderTable = $this->resourceConnection->getTableName('sales_order');
        $orderItemTable = $this->resourceConnection->getTableName('sales_order_item');

        // One row per (customer_id, sku, order created_at) — only orders placed by
        // registered customers (guest checkouts have no reliable identity to target).
        $select = $connection->select()
            ->from(['o' => $orderTable], ['customer_id'])
            ->joinInner(['oi' => $orderItemTable], 'oi.order_id = o.entity_id', ['sku' => 'oi.sku', 'created_at' => 'o.created_at'])
            ->where('o.customer_id IS NOT NULL')
            ->where('o.state != ?', 'canceled')
            ->order(['o.customer_id ASC', 'oi.sku ASC', 'o.created_at ASC']);

        /** @var array<int, array{customer_id: int|string, sku: string, created_at: string}> $rows */
        $rows = $connection->fetchAll($select);

        /** @var array<string, array<int, string>> $ordersByCustomerSku */
        $ordersByCustomerSku = [];
        foreach ($rows as $row) {
            $key = $row['customer_id'] . '|' . $row['sku'];
            $ordersByCustomerSku[$key][] = $row['created_at'];
        }

        $processed = 0;
        foreach ($ordersByCustomerSku as $key => $dates) {
            if (count($dates) < self::MIN_ORDERS_TO_DETECT_PATTERN) {
                continue;
            }

            [$customerId, $sku] = explode('|', $key, 2);
            $dates = array_slice($dates, -self::LOOKBACK_ORDERS_PER_SKU);

            $intervals = [];
            for ($i = 1, $count = count($dates); $i < $count; $i++) {
                $intervals[] = ((int) strtotime($dates[$i]) - (int) strtotime($dates[$i - 1])) / 86400;
            }

            if (empty($intervals)) {
                continue;
            }

            $avgIntervalDays = (int) round(array_sum($intervals) / count($intervals));
            if ($avgIntervalDays < 1) {
                // Same-day repeat purchases don't make sense as a "reorder cycle" — skip.
                continue;
            }

            $lastOrderDate = end($dates);
            $nextExpectedDate = date('Y-m-d', (int) strtotime($lastOrderDate . ' + ' . $avgIntervalDays . ' days'));

            $this->upsertCycle((int) $customerId, $sku, $avgIntervalDays, $lastOrderDate, $nextExpectedDate, count($dates));
            $processed++;
        }

        $this->logger->info(sprintf('Ordo_Automation: recalculated %d reorder cycles.', $processed));
    }

    private function upsertCycle(
        int $customerId,
        string $sku,
        int $avgIntervalDays,
        string $lastOrderDate,
        string $nextExpectedDate,
        int $ordersConsidered
    ): void {
        // ResourceConnection::getConnection() (unlike a ResourceModel's own getConnection())
        // is typed AdapterInterface, never AdapterInterface|false — same underlying connection
        // in practice, just a narrower, accurate signature.
        $connection = $this->resourceConnection->getConnection();
        $table = $this->reorderCycleResource->getMainTable();

        $existingId = $connection->fetchOne(
            $connection->select()
                ->from($table, 'entity_id')
                ->where('customer_id = ?', $customerId)
                ->where('sku = ?', $sku)
        );

        $model = $existingId
            ? $this->reorderCycleFactory->create()->load((int) $existingId)
            : $this->reorderCycleFactory->create();

        $model->setData([
            'customer_id' => $customerId,
            'sku' => $sku,
            'avg_interval_days' => $avgIntervalDays,
            'last_order_date' => date('Y-m-d', (int) strtotime($lastOrderDate)),
            'next_expected_date' => $nextExpectedDate,
            'orders_considered' => $ordersConsidered,
        ]);

        $this->reorderCycleResource->save($model);
    }
}
