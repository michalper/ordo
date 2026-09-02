<?php
declare(strict_types=1);

namespace Ordo\Automation\Cron;

use Ordo\Automation\Model\Rfm\RfmCalculator;
use Psr\Log\LoggerInterface;

/**
 * Refreshes ordo_customer_rfm_score — the precomputed percentile-rank/quintile snapshot
 * RfmCalculator::getPercentileRanks() reads instead of scanning and sorting the whole customer
 * base on every campaign dispatch or segment resolve. No enable flag: unlike email-sending crons,
 * this one is pure data maintenance with no customer-visible side effect, so there's nothing an
 * admin would want to opt out of independently — the percentile-based conditions/report simply
 * fall back to computing live (RfmCalculator::getPercentileRanks()'s own fallback) until this has
 * run at least once.
 */
class RecomputeRfmScores
{
    public function __construct(
        private readonly RfmCalculator $rfmCalculator,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        $this->rfmCalculator->recomputeAndStoreScores();

        $this->logger->info('Ordo_Automation: recomputed RFM percentile ranks and quintiles.');
    }
}
