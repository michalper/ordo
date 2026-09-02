<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Rfm;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * Recency/Frequency/Monetary, computed live from sales_order — same "no separate ledger,
 * derive it from order data every time" approach as Model\CreditLimitCalculator. Every non
 * canceled order counts toward frequency/monetary; "canceled" is excluded the same way
 * CreditLimitCalculator excludes it from used credit.
 */
class RfmCalculator
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly DateTime $dateTime
    ) {
    }

    /**
     * Days since the customer's most recent non-canceled order, or null if they have none.
     */
    public function getRecencyDays(int $customerId): ?int
    {
        $connection = $this->resourceConnection->getConnection();
        $orderTable = $this->resourceConnection->getTableName('sales_order');

        $lastOrderAt = $connection->fetchOne(
            $connection->select()
                ->from($orderTable, 'MAX(created_at)')
                ->where('customer_id = ?', $customerId)
                ->where('state != ?', 'canceled')
        );

        if (!$lastOrderAt) {
            return null;
        }

        $days = ($this->dateTime->gmtTimestamp() - strtotime((string) $lastOrderAt)) / 86400;

        return max(0, (int) floor($days));
    }

    /**
     * Count of the customer's non-canceled orders.
     */
    public function getFrequency(int $customerId): int
    {
        $connection = $this->resourceConnection->getConnection();
        $orderTable = $this->resourceConnection->getTableName('sales_order');

        $count = $connection->fetchOne(
            $connection->select()
                ->from($orderTable, 'COUNT(*)')
                ->where('customer_id = ?', $customerId)
                ->where('state != ?', 'canceled')
        );

        return (int) $count;
    }

    /**
     * Sum of grand_total across the customer's non-canceled orders.
     */
    public function getMonetaryTotal(int $customerId): float
    {
        $connection = $this->resourceConnection->getConnection();
        $orderTable = $this->resourceConnection->getTableName('sales_order');

        $total = $connection->fetchOne(
            $connection->select()
                ->from($orderTable, 'SUM(grand_total)')
                ->where('customer_id = ?', $customerId)
                ->where('state != ?', 'canceled')
        );

        return (float) $total;
    }

    /**
     * Frequency/monetary/recency for every customer with at least one non-canceled order, in a
     * single grouped query — the aggregate that SegmentMemberResolver's RFM conditions filter in
     * PHP against, instead of running getRecencyDays()/getFrequency()/getMonetaryTotal() once per
     * customer. Recency math is identical to getRecencyDays() so results never drift between the
     * single-customer and set-level paths.
     *
     * @return array<int, array{frequency: int, monetary: float, recency_days: int|null}>
     */
    public function getAggregatesForAllCustomers(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $orderTable = $this->resourceConnection->getTableName('sales_order');

        $rows = $connection->fetchAll(
            $connection->select()
                ->from($orderTable, [
                    'customer_id' => 'customer_id',
                    'frequency' => 'COUNT(*)',
                    'monetary' => 'SUM(grand_total)',
                    'last_order_at' => 'MAX(created_at)',
                ])
                ->where('state != ?', 'canceled')
                ->where('customer_id IS NOT NULL')
                ->group('customer_id')
        );

        $aggregates = [];
        /** @var array<string, mixed> $row */
        foreach ($rows as $row) {
            $customerId = (int) $row['customer_id'];
            $lastOrderAt = $row['last_order_at'] ?? null;
            $recencyDays = null;

            if ($lastOrderAt) {
                $days = ($this->dateTime->gmtTimestamp() - strtotime((string) $lastOrderAt)) / 86400;
                $recencyDays = max(0, (int) floor($days));
            }

            $aggregates[$customerId] = [
                'frequency' => (int) $row['frequency'],
                'monetary' => (float) $row['monetary'],
                'recency_days' => $recencyDays,
            ];
        }

        return $aggregates;
    }
}
