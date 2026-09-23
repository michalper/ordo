<?php
declare(strict_types=1);

namespace Ordo\Automation\Cron;

use Ordo\Automation\Model\Campaign\SplitWinnerCalculator;
use Ordo\Automation\Model\Cron\CronRunLogger;

/**
 * Runs Model\Campaign\SplitWinnerCalculator::decideWinners() on a schedule - see that class's
 * own docblock for the actual decision logic (ROADMAP.md's "auto-pick a winner for A/B-split
 * campaign variants"). Off by default (Helper\Config::isAbTestAutoWinnerEnabled()), same reasoning
 * as frequency_cap/quiet_hours: this changes live campaign behavior (which variant future
 * customers land in), so a merchant opts in rather than it happening silently.
 */
class AutoPickCampaignSplitWinner
{
    public function __construct(
        private readonly SplitWinnerCalculator $splitWinnerCalculator,
        private readonly CronRunLogger $cronRunLogger
    ) {
    }

    public function execute(): void
    {
        $decided = $this->splitWinnerCalculator->decideWinners();

        $this->cronRunLogger->logSummary(
            sprintf('auto-picked a winner for %d split action(s)', $decided)
        );
    }
}
