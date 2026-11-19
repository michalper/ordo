<?php

declare(strict_types=1);

namespace Ordo\Automation\Test\Mftf\Helper;

use Magento\FunctionalTestingFramework\Helper\Helper;

/**
 * Inserts a throwaway, obviously-out-of-range row into ordo_customer_clv_score for a REAL
 * customer (unlike RfmTestHelper's nonexistent-id poison row - ClvCalculator::
 * readStoredClvScores() joins to customer_entity, see its own docblock, so a row for a customer
 * that doesn't exist would just be filtered out and prove nothing). This is what makes an MFTF
 * assertion meaningful proof that Cron\RecomputeClvScores actually replaces stale data: the
 * poisoned customer has no real order history, so a genuine recompute (computeClvForAllCustomers()
 * only includes customers with at least one non-canceled order) drops their row entirely instead
 * of merely updating it - the Dashboard's "Average projected CLV" stat must measurably fall once
 * this obviously-wrong outlier is gone.
 */
class ClvTestHelper extends Helper
{
    use DbConnectionTrait;

    private const float POISON_CLV_SCORE = 99999999.0;

    public function insertPoisonClvScoreRow(
        int $customerId
    ): void {
        $pdo = $this->resolveDbConnection();

        $statement = $pdo->prepare(
            'REPLACE INTO ordo_customer_clv_score (customer_id, clv_score) VALUES (:customer_id, :clv_score)'
        );
        $statement->execute(['customer_id' => $customerId, 'clv_score' => self::POISON_CLV_SCORE]);
    }
}
