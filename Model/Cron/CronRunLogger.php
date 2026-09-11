<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Cron;

use Ordo\Automation\Model\ResourceModel\Cron\CronRunLog as CronRunLogResource;
use Psr\Log\LoggerInterface;

/**
 * Wraps the "Ordo_Automation: failed to ...: %s" / "Ordo_Automation: ... ." log-line shape
 * duplicated across the reminder/alert crons (SendWinBackEmails, SendOfferExpiryReminders,
 * SendReorderReminders, SendCreditLimitAlerts, SendSalesRepDigest). Deliberately doesn't wrap the
 * try/catch itself: the per-item failure handling (skip vs. abort, what counts as "sent") is real
 * cron-specific logic, only the log-line formatting was boilerplate.
 *
 * Also persists the same formatted text into ordo_cron_run_log (Model\CronRunLog) on every call -
 * closes the "did today's escalation cron even run" ROADMAP.md gap, where this previously only
 * ever wrote to var/log. Deliberately no cron-name column: every existing call site already
 * passes a fully descriptive action/summary string (e.g. "sent 3 win-back emails"), so the
 * persisted message alone answers the same question the var/log line always did, with zero
 * changes needed to any of the ~20 crons that construct this class.
 */
class CronRunLogger
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly CronRunLogFactory $cronRunLogFactory,
        private readonly CronRunLogResource $cronRunLogResource
    ) {
    }

    /**
     * @param string $action Present-tense description of the failed action, e.g.
     *   "send win-back email to customer #5" — the "Ordo_Automation: failed to " prefix and the
     *   exception message are added here.
     */
    public function logFailure(string $action, \Throwable $e): void
    {
        $message = sprintf('Ordo_Automation: failed to %s: %s', $action, $e->getMessage());
        $this->logger->error($message);
        $this->persist(CronRunLog::LEVEL_FAILURE, $message);
    }

    /**
     * @param string $summary Past-tense summary of the run, e.g. "sent 3 win-back emails" — the
     *   "Ordo_Automation: " prefix and trailing period are added here.
     */
    public function logSummary(string $summary): void
    {
        $message = sprintf('Ordo_Automation: %s.', $summary);
        $this->logger->info($message);
        $this->persist(CronRunLog::LEVEL_SUMMARY, $message);
    }

    /**
     * Swallows its own failure - a DB hiccup persisting this log line must never crash the
     * calling cron mid-run (the var/log line above already recorded the real event either way).
     */
    private function persist(string $level, string $message): void
    {
        try {
            $entry = $this->cronRunLogFactory->create();
            $entry->setLevel($level);
            $entry->setMessage($message);
            $this->cronRunLogResource->save($entry);
        } catch (\Throwable $e) {
            $this->logger->error(
                sprintf('Ordo_Automation: failed to persist cron run log entry: %s', $e->getMessage())
            );
        }
    }
}
