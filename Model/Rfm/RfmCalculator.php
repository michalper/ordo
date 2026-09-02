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

    /**
     * Percentile rank (0-100) per customer on each of the three RFM metrics, over the WHOLE
     * customer base — every row in customer_entity, not just customers who have ordered. This is
     * the relative counterpart to getAggregatesForAllCustomers()'s absolute numbers: a segment
     * condition can ask for "top 20% by spend" instead of having to know that the store's top
     * 20% happens to start at 4,300 PLN.
     *
     * Definition, for a customer c out of N total customers:
     *  - monetary/frequency (higher raw value = better):
     *      percentile(c) = count(customers with metric <= metric(c)) / N * 100
     *  - recency (LOWER days since last order = better):
     *      percentile(c) = count(customers with days >= days(c)) / N * 100,
     *    where a customer with no orders at all has "days since last order" = infinity.
     * So percentile 100 is always the best end of the metric, and a zero-order customer lands at
     * or near percentile 0 on all three.
     *
     * Computed in PHP from the two existing queries (getAggregatesForAllCustomers() +
     * getAllCustomerIds()) rather than a third raw-SQL window-function query: it costs no extra
     * round trip, keeps the recency day-math byte-identical to getRecencyDays(), and the sorting
     * is O(N log N) over customer IDs, which is the same order of data the resolver already
     * holds in memory anyway.
     *
     * @return array<int, array{recency_percentile: float, frequency_percentile: float, monetary_percentile: float}>
     */
    public function getPercentileRanks(): array
    {
        $customerIds = $this->getAllCustomerIds();
        $total = count($customerIds);

        if ($total === 0) {
            return [];
        }

        $aggregates = $this->getAggregatesForAllCustomers();

        $frequencies = [];
        $monetaries = [];
        $recencies = [];

        foreach ($customerIds as $customerId) {
            $aggregate = $aggregates[$customerId] ?? null;
            $frequencies[$customerId] = (float) ($aggregate['frequency'] ?? 0);
            $monetaries[$customerId] = (float) ($aggregate['monetary'] ?? 0.0);
            // No orders (or an aggregate row with no usable last_order_at) means "infinitely
            // stale", which is the worst possible recency — INF compares correctly against every
            // real day count, so it needs no special-casing below.
            $recencies[$customerId] = $aggregate === null || $aggregate['recency_days'] === null
                ? INF
                : (float) $aggregate['recency_days'];
        }

        $sortedFrequencies = array_values($frequencies);
        $sortedMonetaries = array_values($monetaries);
        $sortedRecencies = array_values($recencies);
        sort($sortedFrequencies);
        sort($sortedMonetaries);
        sort($sortedRecencies);

        $ranks = [];
        foreach ($customerIds as $customerId) {
            $ranks[$customerId] = [
                'recency_percentile' =>
                    $this->countAtLeast($sortedRecencies, $recencies[$customerId]) / $total * 100.0,
                'frequency_percentile' =>
                    $this->countAtMost($sortedFrequencies, $frequencies[$customerId]) / $total * 100.0,
                'monetary_percentile' =>
                    $this->countAtMost($sortedMonetaries, $monetaries[$customerId]) / $total * 100.0,
            ];
        }

        return $ranks;
    }

    /**
     * How many entries of an ascending-sorted list are <= $value (binary upper bound).
     *
     * @param float[] $sortedValues ascending
     */
    private function countAtMost(array $sortedValues, float $value): int
    {
        $low = 0;
        $high = count($sortedValues);

        while ($low < $high) {
            $mid = intdiv($low + $high, 2);
            if ($sortedValues[$mid] <= $value) {
                $low = $mid + 1;
            } else {
                $high = $mid;
            }
        }

        return $low;
    }

    /**
     * How many entries of an ascending-sorted list are >= $value (binary lower bound, inverted).
     *
     * @param float[] $sortedValues ascending
     */
    private function countAtLeast(array $sortedValues, float $value): int
    {
        $low = 0;
        $high = count($sortedValues);

        while ($low < $high) {
            $mid = intdiv($low + $high, 2);
            if ($sortedValues[$mid] < $value) {
                $low = $mid + 1;
            } else {
                $high = $mid;
            }
        }

        return count($sortedValues) - $low;
    }

    /**
     * Every customer ID in customer_entity, regardless of order history — used by
     * SegmentMemberResolver's order_frequency_at_least/monetary_total_at_least handling for a
     * threshold <= 0. getAggregatesForAllCustomers() only returns rows for customers with at
     * least one non-canceled order (it's a GROUP BY over sales_order), but a customer with zero
     * orders still satisfies "frequency >= 0" / "monetary >= 0" under the single-customer path
     * (RfmCalculator::getFrequency()/getMonetaryTotal() return 0/0.0 for them, and 0 >= 0 is
     * true) — so resolving a threshold <= 0 against the aggregate map alone would silently drop
     * every zero-order customer instead of matching everyone, same as it should.
     *
     * @return int[]
     */
    public function getAllCustomerIds(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $customerTable = $this->resourceConnection->getTableName('customer_entity');

        $ids = $connection->fetchCol(
            $connection->select()->from($customerTable, 'entity_id')
        );

        return array_map('intval', $ids);
    }
}
