<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ChurnRisk;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * "Churn risk ratio" for a customer: days elapsed since their last non-canceled order, divided
 * by the median interval between their own past orders (any SKU, unlike
 * Model\ReorderCycle\ReorderCycleDriftCalculator, which only has a ratio for a customer with an
 * already-detected per-(customer, sku) cyclical pattern — Cron\CalculateReorderCycle requires a
 * configurable minimum number of orders of the *same* SKU before it writes a row at all, so an
 * irregular or mixed-basket buyer never gets one). 1.0 means exactly on their own historical
 * schedule; above 1.0 means already running later than that pattern predicts. A customer needs
 * at least 2 non-canceled orders to have a baseline to drift from at all — one order has no
 * interval to measure, so this fails closed for them, same "no data to compare against"
 * reasoning as RfmCalculator::getRecencyDays() and ReorderCycleDriftCalculator.
 */
class ChurnRiskCalculator
{
    /**
     * Per-process snapshot cache for getChurnRiskRatiosForAllCustomers(), reset only via
     * resetSnapshotCache() — same lifecycle as RfmCalculator::$aggregatesSnapshot and
     * ReorderCycleDriftCalculator::$driftRatiosSnapshot, driven by
     * Segment\SegmentMemberResolver for the same reason: resolveCustomerIds() below calls this
     * directly, not just a resolver method.
     *
     * @var array<int, float>|null
     */
    private ?array $churnRiskRatiosSnapshot = null;

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly DateTime $dateTime
    ) {
    }

    /**
     * The customer's own churn-risk ratio, or null if they have fewer than 2 non-canceled
     * orders (nothing to derive a baseline interval from).
     */
    public function getChurnRiskRatioForCustomer(int $customerId): ?float
    {
        $connection = $this->resourceConnection->getConnection();
        $orderTable = $this->resourceConnection->getTableName('sales_order');

        /** @var string[] $dates */
        $dates = $connection->fetchCol(
            $connection->select()
                ->from($orderTable, 'created_at')
                ->where('customer_id = ?', $customerId)
                ->where('state != ?', 'canceled')
                ->order('created_at ASC')
        );

        return $this->ratioFromOrderDates($dates);
    }

    /**
     * Every customer with at least 2 non-canceled orders, mapped to their own churn-risk ratio —
     * the set-level counterpart Segment\SegmentMemberResolver's resolveChurnRiskAtLeast() filters
     * in PHP, same "one grouped query, filter in memory" shape as
     * RfmCalculator::getAggregatesForAllCustomers()/ReorderCycleDriftCalculator::
     * getDriftRatiosForAllCustomers().
     *
     * @return array<int, float>
     */
    public function getChurnRiskRatiosForAllCustomers(): array
    {
        if ($this->churnRiskRatiosSnapshot !== null) {
            return $this->churnRiskRatiosSnapshot;
        }

        $connection = $this->resourceConnection->getConnection();
        $orderTable = $this->resourceConnection->getTableName('sales_order');

        /** @var array<int, array{customer_id: int|string, created_at: string}> $rows */
        $rows = $connection->fetchAll(
            $connection->select()
                ->from($orderTable, ['customer_id', 'created_at'])
                ->where('customer_id IS NOT NULL')
                ->where('state != ?', 'canceled')
                ->order(['customer_id ASC', 'created_at ASC'])
        );

        /** @var array<int, array<int, string>> $datesByCustomer */
        $datesByCustomer = [];
        foreach ($rows as $row) {
            $datesByCustomer[(int) $row['customer_id']][] = (string) $row['created_at'];
        }

        $ratios = [];
        foreach ($datesByCustomer as $customerId => $dates) {
            /** @var string[] $dates */
            $ratio = $this->ratioFromOrderDates($dates);
            if ($ratio !== null) {
                $ratios[$customerId] = $ratio;
            }
        }

        $this->churnRiskRatiosSnapshot = $ratios;

        return $ratios;
    }

    /**
     * Drops the getChurnRiskRatiosForAllCustomers() snapshot — see RfmCalculator::
     * resetSnapshotCache()'s own docblock for why this exists and who calls it.
     */
    public function resetSnapshotCache(): void
    {
        $this->churnRiskRatiosSnapshot = null;
    }

    /**
     * @param string[] $dates ascending order, one per non-canceled order
     */
    private function ratioFromOrderDates(array $dates): ?float
    {
        $count = count($dates);
        if ($count < 2) {
            return null;
        }

        // Median interval, same outlier-resistance reasoning as
        // Cron\CalculateReorderCycle's own median-over-mean choice: a single anomalous gap
        // (a long pause, or a burst of back-to-back orders) shouldn't skew the whole baseline.
        $intervals = [];
        for ($i = 1; $i < $count; $i++) {
            $intervals[] = (strtotime($dates[$i]) - strtotime($dates[$i - 1])) / 86400;
        }
        sort($intervals);

        $middle = intdiv(count($intervals), 2);
        $medianInterval = count($intervals) % 2 === 0
            ? ($intervals[$middle - 1] + $intervals[$middle]) / 2
            : $intervals[$middle];

        if ($medianInterval < 1.0) {
            // Same-day-repeat-dominated history has no meaningful cadence to drift from.
            return null;
        }

        $elapsedDays = ($this->dateTime->gmtTimestamp() - strtotime((string) end($dates))) / 86400;

        return max(0.0, $elapsedDays) / $medianInterval;
    }
}
