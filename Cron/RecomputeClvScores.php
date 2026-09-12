<?php
declare(strict_types=1);

namespace Ordo\Automation\Cron;

use Ordo\Automation\Model\Clv\ClvCalculator;
use Ordo\Automation\Model\Cron\CronRunLogger;

/**
 * Refreshes ordo_customer_clv_score — the precomputed CLV projection snapshot
 * ClvCalculator::getClvScores() reads instead of scanning the whole customer base on every
 * campaign dispatch or segment resolve. No enable flag, same rationale as
 * Cron\RecomputeRfmScores: pure data maintenance with no customer-visible side effect, so
 * there's nothing an admin would want to opt out of independently.
 */
class RecomputeClvScores
{
    public function __construct(
        private readonly ClvCalculator $clvCalculator,
        private readonly CronRunLogger $cronRunLogger
    ) {
    }

    public function execute(): void
    {
        $this->clvCalculator->recomputeAndStoreScores();

        $this->cronRunLogger->logSummary('recomputed CLV projections');
    }
}
