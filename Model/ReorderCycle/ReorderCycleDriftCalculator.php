<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ReorderCycle;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * "Drift ratio" for a reorder cycle: days elapsed since the customer's last order in that SKU,
 * divided by their own historically detected average interval (Cron\CalculateReorderCycle's
 * median, despite the column's "avg_interval_days" name). 1.0 means exactly on schedule, due
 * today; below 1.0 means still within their normal cadence; above 1.0 means already running
 * later than their own pattern predicts. A customer can have more than one (customer_id, sku)
 * cycle — the worst (highest) ratio across all of them drives both the "reorder cycle at risk"
 * segment condition and the sales-rep digest tag, since one drifting SKU is enough to flag the
 * relationship as worth a rep's attention, same reasoning as CreditLimitCalculator using the
 * single worst-case number rather than an average.
 */
class ReorderCycleDriftCalculator
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly DateTime $dateTime
    ) {
    }

    /**
     * The customer's single worst (highest) drift ratio across all of their own reorder cycles,
     * or null if they have none yet (Cron\CalculateReorderCycle hasn't detected a pattern for
     * them — same "no data to compare against" fail-closed reasoning as
     * RfmCalculator::getRecencyDays()).
     */
    public function getDriftRatioForCustomer(int $customerId): ?float
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('ordo_reorder_cycle');

        /** @var array<int, array{avg_interval_days: int|string, last_order_date: string}> $rows */
        $rows = $connection->fetchAll(
            $connection->select()
                ->from($table, ['avg_interval_days', 'last_order_date'])
                ->where('customer_id = ?', $customerId)
        );

        return $this->worstRatio($rows);
    }

    /**
     * Every customer with at least one reorder cycle row, mapped to their own worst drift ratio —
     * the set-level counterpart Segment\SegmentMemberResolver's resolveReorderCycleAtRisk()
     * filters in PHP, same "one grouped query, filter in memory" shape as
     * RfmCalculator::getAggregatesForAllCustomers().
     *
     * @return array<int, float>
     */
    public function getDriftRatiosForAllCustomers(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('ordo_reorder_cycle');

        /** @var array<int, array{customer_id: int|string, avg_interval_days: int|string, last_order_date: string}> $rows */
        $rows = $connection->fetchAll(
            $connection->select()->from($table, ['customer_id', 'avg_interval_days', 'last_order_date'])
        );

        /** @var array<int, array<int, array{avg_interval_days: int|string, last_order_date: string}>> $byCustomer */
        $byCustomer = [];
        foreach ($rows as $row) {
            $byCustomer[(int) $row['customer_id']][] = $row;
        }

        $ratios = [];
        foreach ($byCustomer as $customerId => $customerRows) {
            $ratio = $this->worstRatio($customerRows);
            if ($ratio !== null) {
                $ratios[$customerId] = $ratio;
            }
        }

        return $ratios;
    }

    /**
     * @param array<int, array{avg_interval_days: int|string, last_order_date: string}> $rows
     */
    private function worstRatio(array $rows): ?float
    {
        $worst = null;

        foreach ($rows as $row) {
            $avgIntervalDays = (int) $row['avg_interval_days'];
            if ($avgIntervalDays < 1) {
                continue;
            }

            $elapsedDays = ($this->dateTime->gmtTimestamp() - strtotime((string) $row['last_order_date'])) / 86400;
            $ratio = max(0.0, $elapsedDays) / $avgIntervalDays;

            if ($worst === null || $ratio > $worst) {
                $worst = $ratio;
            }
        }

        return $worst;
    }
}
