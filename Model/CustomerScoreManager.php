<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Magento\Framework\App\ResourceConnection;

/**
 * Lead scoring, kept as intentionally dumb as CustomerTagManager: one running points total per
 * customer, no separate ledger of individual point-earning events (that history lives in
 * ordo_campaign_log / whatever action/trigger awarded the points, not here — this table only
 * ever holds the current balance). addPoints() upserts via INSERT ... ON DUPLICATE KEY UPDATE
 * so concurrent awards to the same customer accumulate correctly instead of racing on a
 * read-then-write.
 */
class CustomerScoreManager
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    public function addPoints(int $customerId, int $points): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('ordo_customer_score');

        // ON DUPLICATE KEY UPDATE has no equivalent in Magento's query builder API; parameters
        // are still bound below, not interpolated, and the table name comes from
        // getTableName()/quoteIdentifier(), never from user input.
        $connection->query(
            // phpcs:ignore Magento2.SQL.RawQuery.FoundRawSql
            'INSERT INTO ' . $connection->quoteIdentifier($table) . ' (customer_id, score) VALUES (?, ?) '
            . 'ON DUPLICATE KEY UPDATE score = score + VALUES(score)',
            [$customerId, $points]
        );
    }

    public function getScore(int $customerId): int
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('ordo_customer_score');

        $score = $connection->fetchOne(
            $connection->select()
                ->from($table, 'score')
                ->where('customer_id = ?', $customerId)
        );

        return $score !== false ? (int) $score : 0;
    }

    /**
     * All customers currently at or above a given score — the set-level counterpart to
     * getScore(), used by SegmentMemberResolver to resolve a "score_at_least" condition.
     *
     * @return int[]
     */
    public function getCustomerIdsWithScoreAtLeast(int $threshold): array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('ordo_customer_score');

        $ids = $connection->fetchCol(
            $connection->select()
                ->from($table, 'customer_id')
                ->where('score >= ?', $threshold)
        );

        return array_map('intval', $ids);
    }

    /**
     * Current sum of matching ordo_score_rule points for a customer — kept in a separate
     * table (ordo_customer_demographic_score) from the running score total, so
     * EvaluateCustomerScoreRules can compute a delta between the old and new sum instead of
     * having to reverse out and reapply every rule's points on each customer save.
     */
    public function getDemographicScore(int $customerId): int
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('ordo_customer_demographic_score');

        $score = $connection->fetchOne(
            $connection->select()
                ->from($table, 'score')
                ->where('customer_id = ?', $customerId)
        );

        return $score !== false ? (int) $score : 0;
    }

    public function setDemographicScore(int $customerId, int $score): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('ordo_customer_demographic_score');

        // Same upsert shape as addPoints(), but this one replaces the value outright (it's a
        // recomputed sum, not a delta), so VALUES(score) becomes the new score rather than an
        // increment.
        $connection->query(
            // phpcs:ignore Magento2.SQL.RawQuery.FoundRawSql
            'INSERT INTO ' . $connection->quoteIdentifier($table) . ' (customer_id, score) VALUES (?, ?) '
            . 'ON DUPLICATE KEY UPDATE score = VALUES(score)',
            [$customerId, $score]
        );
    }
}
