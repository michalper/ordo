<?php
declare(strict_types=1);

namespace Ordo\Automation\Cron;

use Ordo\Automation\Model\Campaign\ScheduledTriggerScanner;
use Ordo\Automation\Model\Cron\CronRunLogger;

/**
 * Finds every scheduled_at/recurring_schedule campaign trigger that is due and fires it -
 * Model\Campaign\ScheduledTriggerScanner carries the actual due-ness/firing logic, this class is
 * just the cron entry point (every 5 minutes, see etc/crontab.xml - the same cadence
 * Cron\RunScheduledCampaignActions already uses for its own due-row scan).
 */
class DispatchScheduledCampaignTriggers
{
    public function __construct(
        private readonly ScheduledTriggerScanner $scheduledTriggerScanner,
        private readonly CronRunLogger $cronRunLogger
    ) {
    }

    public function execute(): void
    {
        $fired = $this->scheduledTriggerScanner->scan();

        if ($fired > 0) {
            $this->cronRunLogger->logSummary(sprintf('fired %d scheduled campaign trigger(s)', $fired));
        }
    }
}
