<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ReorderCycle;

use Magento\Customer\Api\Data\CustomerInterface;
use Ordo\Automation\Model\ConsentChannel;
use Ordo\Automation\Model\ConsentManager;
use Ordo\Automation\Model\Cron\ReminderEmailSender;
use Ordo\Automation\Model\Cron\ReminderLogStore;
use Ordo\Automation\Model\ReorderCycle;
use Ordo\Automation\Model\SalesRepEmailContext;
use Ordo\Automation\Model\TriggerOutcomeLogger;

/**
 * Sends one reorder reminder for one cycle right now - the single-row counterpart to
 * Cron\SendReorderReminders's own batch loop, reusing the exact same collaborators (same email
 * template, same ordo_reorder_reminder_log claim-before-send bookkeeping, same consent gate, same
 * TriggerOutcomeLogger entry) so a manually-triggered send is indistinguishable from a cron-sent
 * one in every log/report that reads those tables afterward.
 *
 * Backs Controller\Adminhtml\ReorderCycle\SendReminder - closes the ROADMAP.md "no manual
 * per-customer reminder trigger" gap: previously the only way to nudge a customer whose reminder
 * hadn't fired yet (still short of the lead-days window, or already sent-and-suppressed today)
 * was waiting for the next cron tick.
 *
 * Deliberately does NOT check "already sent today" the way the cron does - an admin clicking
 * this button has explicitly decided to send again regardless of what already went out
 * automatically; it still WRITES that log row afterward, so the cron's own guard correctly skips
 * this cycle for the rest of today instead of also sending a second reminder.
 */
class ReorderReminderSender
{
    private const string XML_PATH_EMAIL_TEMPLATE = 'ordo_reorder_reminder';
    private const string REMINDER_LOG_TABLE = 'ordo_reorder_reminder_log';

    public function __construct(
        private readonly ReminderEmailSender $emailSender,
        private readonly ReminderLogStore $reminderLogStore,
        private readonly SalesRepEmailContext $salesRepEmailContext,
        private readonly ConsentManager $consentManager,
        private readonly TriggerOutcomeLogger $triggerOutcomeLogger
    ) {
    }

    /**
     * @throws OptedOutException the customer has opted out of email - never silently skipped,
     *  the caller (an interactive admin action) needs to surface this instead of it looking like
     *  a successful send.
     */
    public function sendNow(ReorderCycle $cycle, CustomerInterface $customer): void
    {
        $customerId = (int) $customer->getId();

        if (!$this->consentManager->hasConsent($customerId, ConsentChannel::Email)) {
            throw new OptedOutException(sprintf(
                'Customer #%d has opted out of email; cannot send a reorder reminder.',
                $customerId
            ));
        }

        // Claim (log) BEFORE sending - see ReminderLogStore::deleteMatching()'s own docblock for
        // why: a crash between a successful send and the log write must never cause the cron to
        // resend later today, and a genuine send failure rolls the claim back so this cycle can
        // be retried (by the cron, or by clicking this same button again).
        $reminderLogRow = [
            'reorder_cycle_id' => (int) $cycle->getEntityId(),
            'sent_at' => date('Y-m-d H:i:s'),
        ];
        $this->reminderLogStore->insert(self::REMINDER_LOG_TABLE, $reminderLogRow);

        try {
            $this->emailSender->send(
                self::XML_PATH_EMAIL_TEMPLATE,
                array_merge([
                    'customer_name' => $customer->getFirstname(),
                    'sku' => $cycle->getSku(),
                    'avg_interval_days' => $cycle->getAvgIntervalDays(),
                ], $this->salesRepEmailContext->getForLoadedCustomer($customer)),
                (string) $customer->getEmail(),
                (string) $customer->getFirstname()
            );
            $this->triggerOutcomeLogger->logSent(TriggerOutcomeLogger::TRIGGER_REORDER_REMINDER, $customerId);
        } catch (\Throwable $e) {
            $this->reminderLogStore->deleteMatching(self::REMINDER_LOG_TABLE, $reminderLogRow);
            throw $e;
        }
    }
}
