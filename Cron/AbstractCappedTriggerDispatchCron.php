<?php
declare(strict_types=1);

namespace Ordo\Automation\Cron;

use Magento\Framework\App\ResourceConnection;
use Ordo\Automation\Model\CampaignDispatcher;
use Ordo\Automation\Model\Cron\CronRunLogger;
use Ordo\Automation\Model\Cron\ReminderLogStore;

/**
 * Shared scan/claim/dispatch skeleton for the (customer_id, event_key) funnel crons
 * (SendBrowseAbandonmentReminders, SendWishlistAbandonmentReminders) — both scan for a
 * customer/product pair that hasn't converted, capped at a configurable max dispatches per pair,
 * and dispatch a bare campaign trigger with no built-in action of its own. Only the SELECT (which
 * source table/join defines "due", and what the cap counts against) and the trigger code/label
 * differ between the two; the claim-before-dispatch crash safety, the concurrent-run freshness
 * guard, and the logging all live here once. See docs/adr/0065 for why
 * Cron\ScanReviewRequestDue, a structurally similar but not identical cron (dispatches exactly
 * once per order, not capped per customer/event_key, no freshness guard), was deliberately left
 * out of this abstraction rather than forced into it.
 */
abstract class AbstractCappedTriggerDispatchCron
{
    public function __construct(
        protected readonly ResourceConnection $resourceConnection,
        private readonly CampaignDispatcher $campaignDispatcher,
        private readonly CronRunLogger $cronRunLogger,
        private readonly ReminderLogStore $reminderLogStore
    ) {
    }

    abstract protected function isEnabled(): bool;

    /**
     * @return string the ordo_*_reminder_log table this cron claims dispatches against.
     */
    abstract protected function logTable(): string;

    /**
     * @return string the campaign trigger code to dispatch (CampaignTriggerInterface::TRIGGER_*).
     */
    abstract protected function triggerCode(): string;

    /**
     * @return string a short, human-readable noun phrase for log messages (e.g. "browse abandonment").
     */
    abstract protected function triggerLabel(): string;

    /**
     * @return array<int, array{customer_id: int|string, event_key: string, reminders_sent: int|string}>
     */
    abstract protected function fetchDueRows(): array;

    public function execute(): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $logTable = $this->logTable();

        // Captured before the rows are fetched: any log row for the same customer/event_key with
        // sent_at at/after this moment can only have been written by a concurrently overlapping
        // run (this run's own select already counted everything older into reminders_sent).
        $runStartedAt = date('Y-m-d H:i:s');

        $rows = $this->fetchDueRows();

        $dispatched = 0;
        foreach ($rows as $row) {
            $customerId = (int) $row['customer_id'];
            $eventKey = (string) $row['event_key'];

            try {
                // Claim (log) BEFORE dispatching, not after - a crash between a successful
                // dispatch and the log write must never cause a duplicate trigger on the next
                // tick. If the dispatch itself then fails, the claim is rolled back so this row
                // is retried next run. claimAndRun() (not a plain insert()) atomically re-checks
                // that no overlapping run already claimed this customer/event_key since this run
                // started - the select's own HAVING cap is only a pre-filter: two overlapping
                // runs can both count the same reminders_sent before either inserts, and both
                // would dispatch.
                $claimed = $this->reminderLogStore->claimAndRun(
                    $logTable,
                    [
                        'customer_id = ?' => $customerId,
                        'event_key = ?' => $eventKey,
                        'sent_at >= ?' => $runStartedAt,
                    ],
                    [
                        'customer_id' => $customerId,
                        'event_key' => $eventKey,
                        'sent_at' => date('Y-m-d H:i:s'),
                    ],
                    function () use ($customerId, $eventKey): void {
                        $this->campaignDispatcher->dispatch($this->triggerCode(), [
                            'customer_id' => $customerId,
                            'product_sku' => $eventKey,
                        ]);
                    }
                );
            } catch (\Throwable $e) {
                $this->cronRunLogger->logFailure(
                    sprintf(
                        'dispatch %s trigger for customer #%d / %s',
                        $this->triggerLabel(),
                        $customerId,
                        $eventKey
                    ),
                    $e
                );
                continue;
            }

            if ($claimed) {
                $dispatched++;
            }
        }

        $this->cronRunLogger->logSummary(sprintf('dispatched %d %s triggers', $dispatched, $this->triggerLabel()));
    }
}
