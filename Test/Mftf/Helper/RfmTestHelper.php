<?php

declare(strict_types=1);

namespace Ordo\Automation\Test\Mftf\Helper;

use Magento\FunctionalTestingFramework\Helper\Helper;

/**
 * Inserts a throwaway row into ordo_customer_rfm_score for a customer_id that will never
 * actually exist, so the table is non-empty (RfmCalculator::readStoredPercentileRanks()
 * returns real, if incomplete, data instead of null) before Cron\RecomputeRfmScores has ever
 * run for the customer this test actually cares about. This is what makes an MFTF assertion
 * meaningful proof that a percentile condition reads the *precomputed* table rather than
 * falling back to a live scan: if it read live, the condition would already be satisfied right
 * after the customer's first real order, with no cron involved at all — placing that same
 * order while the table already has *other* data (but not this customer's) is what actually
 * exercises the "not found in the stored ranks, condition fails closed" path first.
 */
class RfmTestHelper extends Helper
{
    use DbConnectionTrait;

    private const int POISON_CUSTOMER_ID = 999999999;

    public function insertPoisonRfmScoreRow(): void
    {
        $pdo = $this->resolveDbConnection();

        $statement = $pdo->prepare(
            'REPLACE INTO ordo_customer_rfm_score '
            . '(customer_id, recency_percentile, frequency_percentile, monetary_percentile, '
            . 'recency_quintile, frequency_quintile, monetary_quintile) '
            . 'VALUES (:customer_id, 50, 50, 50, 3, 3, 3)'
        );
        $statement->execute(['customer_id' => self::POISON_CUSTOMER_ID]);
    }

    /**
     * Confirms Controller\Adminhtml\Rfm\MassDelete's "Reset Cached Score" mass action actually
     * deleted a real customer's ordo_customer_rfm_score row (RfmCalculator::
     * resetScoresForCustomers()) - the real, database-observable proof this mass action does
     * something at all, since the grid itself has no stored entity of its own to re-check
     * against (see MassDelete's own docblock).
     *
     * @throws \RuntimeException if a matching row still exists
     */
    public function assertNoRfmScoreForCustomer(
        int $customerId
    ): void {
        $pdo = $this->resolveDbConnection();

        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM ordo_customer_rfm_score WHERE customer_id = :customer_id'
        );
        $statement->execute(['customer_id' => $customerId]);
        $count = (int) $statement->fetchColumn();

        if ($count > 0) {
            throw new \RuntimeException(sprintf(
                'Unexpected ordo_customer_rfm_score row still found for customer_id=%d.',
                $customerId
            ));
        }
    }
}
