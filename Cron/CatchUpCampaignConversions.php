<?php
declare(strict_types=1);

namespace Ordo\Automation\Cron;

use Ordo\Automation\Model\CampaignOutcomeLogger;
use Ordo\Automation\Model\Cron\CronRunLogger;

/**
 * Runs Model\CampaignOutcomeLogger::catchUpMissedConversions() — see that method's own docblock
 * for the race it closes (campaign dispatch is async, so a customer's own next real order can
 * save before the campaign's own 'sent' row even exists for Observer\RecordCampaignOutcome to
 * mark at order-save time). Frequent (every 10 minutes, same cadence as the message/action retry
 * crons in etc/crontab.xml) because the scan itself is cheap — only rows still un-acted within the
 * lookback window, typically a handful at most — and because every tick this stays missed is a
 * conversion the admin-facing Funnel widget keeps under-reporting.
 */
class CatchUpCampaignConversions
{
    public function __construct(
        private readonly CampaignOutcomeLogger $campaignOutcomeLogger,
        private readonly CronRunLogger $cronRunLogger
    ) {
    }

    public function execute(): void
    {
        $caughtUp = $this->campaignOutcomeLogger->catchUpMissedConversions();

        if ($caughtUp > 0) {
            $this->cronRunLogger->logSummary(sprintf(
                'retroactively attributed %d campaign conversion(s) that the real-time order-save '
                . 'check missed (campaign send was still in flight on the async dispatch queue)',
                $caughtUp
            ));
        }
    }
}
