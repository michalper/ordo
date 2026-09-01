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

        $connection->query(
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
}
