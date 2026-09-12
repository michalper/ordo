<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Clv;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Ordo\Automation\Helper\Config;

/**
 * Customer Lifetime Value — the forward-looking counterpart to RfmCalculator: RFM ranks
 * customers by what they've already done, this projects what a customer is likely worth going
 * forward. Same "derive it from sales_order every time, no separate ledger" approach as
 * RfmCalculator/Model\CreditLimitCalculator; the same non-canceled-order filter is reused so a
 * customer's CLV inputs never disagree with their RFM inputs over which orders count.
 *
 * Model chosen deliberately over anything ML-based: this is a marketing-automation module, not
 * a data-science platform, and a full predictive model (e.g. BG/NBD, gamma-gamma) would need a
 * training pipeline, a lot more historical data than many stores running this module actually
 * have, and would be effectively unauditable to a merchant reading the admin UI. Instead this
 * uses the standard, explainable "textbook" CLV formula:
 *
 *   CLV = average order value  x  purchase frequency (orders/year)  x  projection window (years)
 *
 * - Average order value = monetary total / order count, identical inputs to RfmCalculator's own
 *   getMonetaryTotal()/getFrequency().
 * - Purchase frequency is annualized from the customer's own tenure (time since their first
 *   non-canceled order) rather than a fixed global window, so a customer who ordered 4 times in
 *   2 months projects a much higher run rate than one who ordered 4 times in 4 years. Tenure is
 *   floored at getClvMinTenureMonths() (admin-configurable) so a brand-new customer's very first
 *   order doesn't get annualized into an absurd, near-infinite run rate from a near-zero tenure
 *   denominator.
 * - The projection window (getClvProjectionYears(), admin-configurable) is how many years of
 *   that run rate to project forward — the "lifespan estimate" of the classic AOV x frequency x
 *   lifespan formula, made a tunable instead of a guessed constant since it varies a lot by
 *   vertical (B2B accounts commonly retained for years vs. B2C impulse categories).
 *
 * This is intentionally simple and auditable over statistically fancier: an admin can explain
 * "why is this customer's CLV 4500 PLN" in one sentence, which a black-box model can't offer.
 */
class ClvCalculator
{
    /**
     * Same rationale as RfmCalculator::PERCENTILE_CACHE_TTL_SECONDS - fresh enough that a burst
     * of campaign/condition checks against the same trigger event share one computation, short
     * enough that a long-lived queue consumer never serves data more than this many seconds
     * stale.
     *
     * @var array<int, float>|null
     */
    private ?array $clvScoreCache = null;

    private ?int $clvScoreCachedAt = null;

    private const int CLV_CACHE_TTL_SECONDS = 60;

    /**
     * Same rationale as RfmCalculator::SCAN_PAGE_SIZE - bounds the SQL round-trip size for the
     * whole-customer-base scan, not the final in-memory array (which a whole-base ranking
     * inherently needs regardless).
     */
    private const int SCAN_PAGE_SIZE = 5000;

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly DateTime $dateTime,
        private readonly Config $config
    ) {
    }

    /**
     * Average grand_total across the customer's non-canceled orders, or 0.0 with no orders.
     */
    public function getAverageOrderValue(int $customerId): float
    {
        $connection = $this->resourceConnection->getConnection();
        $orderTable = $this->resourceConnection->getTableName('sales_order');

        $row = $connection->fetchRow(
            $connection->select()
                ->from($orderTable, [
                    'frequency' => 'COUNT(*)',
                    'monetary' => 'SUM(grand_total)',
                ])
                ->where('customer_id = ?', $customerId)
                ->where('state != ?', 'canceled')
        );
        /** @var array<string, mixed> $row */

        $frequency = (int) ($row['frequency'] ?? 0);
        if ($frequency === 0) {
            return 0.0;
        }

        return (float) $row['monetary'] / $frequency;
    }

    /**
     * Years since the customer's first non-canceled order, floored at getClvMinTenureMonths() so
     * a very new customer's annualized frequency isn't inflated by a near-zero denominator. Null
     * with no orders at all.
     */
    public function getTenureYears(int $customerId): ?float
    {
        $connection = $this->resourceConnection->getConnection();
        $orderTable = $this->resourceConnection->getTableName('sales_order');

        $firstOrderAt = $connection->fetchOne(
            $connection->select()
                ->from($orderTable, 'MIN(created_at)')
                ->where('customer_id = ?', $customerId)
                ->where('state != ?', 'canceled')
        );

        if (!$firstOrderAt) {
            return null;
        }

        return $this->tenureYearsFromFirstOrderAt((string) $firstOrderAt);
    }

    private function tenureYearsFromFirstOrderAt(string $firstOrderAt): float
    {
        $days = ($this->dateTime->gmtTimestamp() - strtotime($firstOrderAt)) / 86400;
        $minTenureYears = $this->config->getClvMinTenureMonths() / 12;

        return max($minTenureYears, $days / 365);
    }

    /**
     * Live projected CLV for one customer: average order value x annualized purchase frequency
     * x the configured projection window. 0.0 for a customer with no non-canceled orders — a
     * prospect with zero purchase history has no historical basis to project from, same
     * "zero-order customer is the floor, not an unknown" convention as
     * RfmCalculator::computePercentileRanks().
     */
    public function getProjectedClv(int $customerId): float
    {
        $connection = $this->resourceConnection->getConnection();
        $orderTable = $this->resourceConnection->getTableName('sales_order');

        $row = $connection->fetchRow(
            $connection->select()
                ->from($orderTable, [
                    'frequency' => 'COUNT(*)',
                    'monetary' => 'SUM(grand_total)',
                    'first_order_at' => 'MIN(created_at)',
                ])
                ->where('customer_id = ?', $customerId)
                ->where('state != ?', 'canceled')
        );
        /** @var array<string, mixed> $row */

        $frequency = (int) ($row['frequency'] ?? 0);
        if ($frequency === 0 || !($row['first_order_at'] ?? null)) {
            return 0.0;
        }

        $averageOrderValue = (float) $row['monetary'] / $frequency;
        $tenureYears = $this->tenureYearsFromFirstOrderAt((string) $row['first_order_at']);
        $annualFrequency = $frequency / $tenureYears;

        return $averageOrderValue * $annualFrequency * $this->config->getClvProjectionYears();
    }

    /**
     * Projected CLV for every customer with at least one non-canceled order, in a single grouped
     * query — the set-level counterpart to getProjectedClv(), the same "one query instead of one
     * per customer" tradeoff as RfmCalculator::getAggregatesForAllCustomers().
     *
     * @return array<int, float>
     */
    public function computeClvForAllCustomers(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $orderTable = $this->resourceConnection->getTableName('sales_order');

        $scores = [];
        $offset = 0;
        $projectionYears = $this->config->getClvProjectionYears();

        do {
            $rows = $connection->fetchAll(
                $connection->select()
                    ->from($orderTable, [
                        'customer_id' => 'customer_id',
                        'frequency' => 'COUNT(*)',
                        'monetary' => 'SUM(grand_total)',
                        'first_order_at' => 'MIN(created_at)',
                    ])
                    ->where('state != ?', 'canceled')
                    ->where('customer_id IS NOT NULL')
                    ->group('customer_id')
                    ->order('customer_id ASC')
                    ->limit(self::SCAN_PAGE_SIZE, $offset)
            );

            /** @var array<string, mixed> $row */
            foreach ($rows as $row) {
                $customerId = (int) $row['customer_id'];
                $frequency = (int) $row['frequency'];
                $firstOrderAt = $row['first_order_at'] ?? null;

                if ($frequency === 0 || !$firstOrderAt) {
                    $scores[$customerId] = 0.0;
                    continue;
                }

                $averageOrderValue = (float) $row['monetary'] / $frequency;
                $tenureYears = $this->tenureYearsFromFirstOrderAt((string) $firstOrderAt);
                $annualFrequency = $frequency / $tenureYears;

                $scores[$customerId] = $averageOrderValue * $annualFrequency * $projectionYears;
            }

            $offset += self::SCAN_PAGE_SIZE;
        } while (count($rows) === self::SCAN_PAGE_SIZE);

        return $scores;
    }

    /**
     * Time-bounded-cached CLV scores, read from Cron\RecomputeClvScores's precomputed table when
     * populated and falling back to a live compute otherwise — same shape as
     * RfmCalculator::getPercentileRanks().
     *
     * @return array<int, float>
     */
    public function getClvScores(): array
    {
        $now = $this->dateTime->gmtTimestamp();

        if ($this->clvScoreCache !== null
            && $this->clvScoreCachedAt !== null
            && ($now - $this->clvScoreCachedAt) < self::CLV_CACHE_TTL_SECONDS
        ) {
            return $this->clvScoreCache;
        }

        $scores = $this->readStoredClvScores() ?? $this->computeClvForAllCustomers();

        $this->clvScoreCache = $scores;
        $this->clvScoreCachedAt = $now;

        return $scores;
    }

    /**
     * @return array<int, float>|null null when the table has never been populated yet, so
     *   getClvScores() can fall back to computing live rather than treating "no cron run yet" as
     *   "no customers exist" (identical convention to RfmCalculator::readStoredPercentileRanks()).
     */
    private function readStoredClvScores(): ?array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('ordo_customer_clv_score');
        $customerTable = $this->resourceConnection->getTableName('customer_entity');

        // Same stale-row concern (and the same join-based fix) as
        // RfmCalculator::readStoredPercentileRanks(): ordo_customer_clv_score has no FK to
        // customer_entity, so a customer deleted after the last recompute would otherwise still
        // match a segment condition against a stale cached score.
        $rows = $connection->fetchAll(
            $connection->select()
                ->from(['s' => $table], ['customer_id', 'clv_score'])
                ->join(['c' => $customerTable], 's.customer_id = c.entity_id', [])
        );

        if ($rows === []) {
            return null;
        }

        $scores = [];
        /** @var array<string, mixed> $row */
        foreach ($rows as $row) {
            $scores[(int) $row['customer_id']] = (float) $row['clv_score'];
        }

        return $scores;
    }

    /**
     * Computes fresh CLV projections for every customer with order history and replaces
     * ordo_customer_clv_score wholesale — called by Cron\RecomputeClvScores, never on the
     * campaign-dispatch/segment-resolve hot path. Full replace rather than a per-row diff for the
     * same reason as RfmCalculator::recomputeAndStoreScores(): a projection depends only on that
     * one customer's own order history, but keeping the write path structurally identical to RFM
     * keeps both crons easy to reason about together.
     */
    public function recomputeAndStoreScores(): void
    {
        $scores = $this->computeClvForAllCustomers();

        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('ordo_customer_clv_score');

        $connection->delete($table);

        if ($scores === []) {
            return;
        }

        $rows = [];
        foreach ($scores as $customerId => $score) {
            $rows[] = [
                'customer_id' => $customerId,
                'clv_score' => $score,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            $connection->insertMultiple($table, $chunk);
        }
    }

    /**
     * Drops the precomputed rows for the given customers from ordo_customer_clv_score — same
     * "no stored primary entity to mass-delete" rationale as
     * RfmCalculator::resetScoresForCustomers().
     *
     * @param int[] $customerIds
     */
    public function resetScoresForCustomers(array $customerIds): int
    {
        if ($customerIds === []) {
            return 0;
        }

        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('ordo_customer_clv_score');

        return (int) $connection->delete($table, ['customer_id IN (?)' => $customerIds]);
    }

    /**
     * Projected CLV for one customer, read from the cached/stored scores. Null when the
     * customer has no stored (or, with an empty table, no computable) score.
     */
    public function getClvScore(int $customerId): ?float
    {
        $scores = $this->getClvScores();

        return $scores[$customerId] ?? null;
    }

    /**
     * Average projected CLV across every customer with a stored/computed score — the dashboard
     * KPI's aggregate number. 0.0 with no customers to average.
     */
    public function getAverageClvScore(): float
    {
        $scores = $this->getClvScores();
        if ($scores === []) {
            return 0.0;
        }

        return array_sum($scores) / count($scores);
    }
}
