<?php
declare(strict_types=1);

namespace Ordo\Automation\Cron;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Cron\CronRunLogger;
use Ordo\Automation\Model\Cron\ReminderEmailSender;
use Ordo\Automation\Model\OrderApproval;
use Ordo\Automation\Model\OrderApprovalManagement;
use Ordo\Automation\Model\ResourceModel\OrderApproval as OrderApprovalResource;
use Ordo\Automation\Model\ResourceModel\OrderApproval\CollectionFactory as OrderApprovalCollectionFactory;
use Ordo\Automation\Model\TriggerOutcomeLogger;

/**
 * If nobody has approved or rejected a held order within the configured window, this reminds
 * whoever is currently responsible once more - capped per tier at
 * Config::getOrderApprovalEscalationMaxRemindersPerTier() reminders, after which responsibility
 * escalates to the next email in Config::getOrderApprovalEscalationChainEmails() (tier 0 is
 * always the original customer-assigned admin_email). An empty (unconfigured) chain means every
 * approval stays at tier 0 forever, re-reminding the same recipient - the original, single-level
 * behavior this class had before multi-level escalation existed. No auto-decision is made either
 * way at any tier - a human still has to act.
 */
class EscalateStalePendingApprovals
{
    private const string XML_PATH_EMAIL_TEMPLATE = 'ordo_order_approval_escalation';

    public function __construct(
        private readonly Config $config,
        private readonly OrderApprovalCollectionFactory $orderApprovalCollectionFactory,
        private readonly OrderApprovalResource $orderApprovalResource,
        private readonly OrderCollectionFactory $orderCollectionFactory,
        private readonly ReminderEmailSender $reminderEmailSender,
        private readonly OrderApprovalManagement $orderApprovalManagement,
        private readonly TriggerOutcomeLogger $triggerOutcomeLogger,
        private readonly CronRunLogger $cronRunLogger
    ) {
    }

    public function execute(): void
    {
        if (!$this->config->isOrderApprovalEnabled()) {
            return;
        }

        $escalationDays = $this->config->getOrderApprovalEscalationDays();
        $cutoff = date('Y-m-d H:i:s', (int) strtotime("-{$escalationDays} days"));
        $maxRemindersPerTier = $this->config->getOrderApprovalEscalationMaxRemindersPerTier();
        $chainEmails = $this->config->getOrderApprovalEscalationChainEmails();

        $collection = $this->orderApprovalCollectionFactory->create();
        $collection->addStalePendingFilter($cutoff);

        // Unlike the single-level version, an approval already at its reminder cap is NOT
        // skipped outright anymore - it's still a candidate, just for a tier-advance instead of
        // another same-tier reminder (see peekRecipientEmail()). Only a chain that's fully
        // exhausted (no further email configured past the current tier) drops it for good, same
        // end state as the original "sits pending forever" behavior at the deepest tier - and,
        // just like before, those are filtered out here so their orders are never even loaded.
        $approvals = [];
        foreach ($collection as $approval) {
            /** @var OrderApproval $approval */
            if ($this->peekRecipientEmail($approval, $maxRemindersPerTier, $chainEmails) !== null) {
                $approvals[] = $approval;
            }
        }

        // One batched IN(...) load for every stale approval's order instead of one query per
        // approval inside the loop below - found via a performance audit, same shape
        // CustomerMapBuilder::build() already uses for its own batch customer load.
        $orderIds = array_map(static fn ($approval): int => (int) $approval->getOrderId(), $approvals);
        $orderMap = [];
        if ($orderIds !== []) {
            $orderCollection = $this->orderCollectionFactory->create();
            $orderCollection->addFieldToFilter('entity_id', ['in' => $orderIds]);
            foreach ($orderCollection as $order) {
                /** @var Order $order */
                $orderMap[(int) $order->getEntityId()] = $order;
            }
        }

        $sent = 0;
        foreach ($approvals as $approval) {
            /** @var OrderApproval $approval */
            $order = $orderMap[(int) $approval->getOrderId()] ?? null;
            if ($order === null) {
                continue;
            }

            $remindersSentBeforeClaim = $approval->getRemindersSent();
            $escalationTierBeforeClaim = $approval->getEscalationTier();

            // rollbackEscalation() below always reverts both fields to their pre-claim values
            // regardless of which tier a failed claim advanced to, so the second element
            // (tierAdvanced) claimRecipientEmail() returns is no longer needed here.
            [$recipient] = $this->claimRecipientEmail($approval, $maxRemindersPerTier, $chainEmails);
            if ($recipient === null) {
                // Already claimed by peekRecipientEmail() at the pre-filter stage above, so this
                // shouldn't normally happen - defensive only.
                continue;
            }

            try {
                $this->sendEscalationEmail($approval, $order, $recipient);
                if ($order->getCustomerId()) {
                    $this->triggerOutcomeLogger->logSent(
                        TriggerOutcomeLogger::TRIGGER_ORDER_APPROVAL,
                        (int) $order->getCustomerId()
                    );
                }
                $sent++;
            } catch (\Throwable $e) {
                // Roll the claim back to its pre-claim values - a genuinely failed send must be
                // retried next run, not silently counted as having happened. A plain
                // setData()+save() can't do this: claimEscalation() wrote the new values via a
                // raw UPDATE that never touched origData, so AbstractDb::save()'s diff-against-
                // origData would see these reset values as already matching origData (both still
                // the pre-claim ones) and write nothing at all - found during a full-repo
                // /code-review. rollbackEscalation() reverts via its own raw UPDATE instead, so
                // it writes regardless of what origData thinks changed.
                $this->orderApprovalResource->rollbackEscalation(
                    $approval,
                    $remindersSentBeforeClaim,
                    $escalationTierBeforeClaim
                );
                $this->cronRunLogger->logFailure(
                    sprintf('send approval escalation for order #%d', (int) $order->getEntityId()),
                    $e
                );
            }
        }

        $this->cronRunLogger->logSummary(sprintf('sent %d order approval escalations', $sent));
    }

    /**
     * Read-only version of {@see claimRecipientEmail()} - who WOULD this approval's next
     * reminder go to, without claiming (incrementing/saving) anything. Used to pre-filter the
     * stale-approval list before the batched order load, so an approval whose chain is already
     * exhausted never causes its order to be loaded at all - same as the original single-level
     * version's upfront `reminders_sent < MAX_ESCALATIONS` filter.
     *
     * @param string[] $chainEmails
     */
    private function peekRecipientEmail(OrderApproval $approval, int $maxRemindersPerTier, array $chainEmails): ?string
    {
        $tier = $approval->getEscalationTier();
        $remindersSent = $approval->getRemindersSent();

        if ($remindersSent < $maxRemindersPerTier) {
            return $tier === 0 ? $approval->getAdminEmail() : ($chainEmails[$tier - 1] ?? null);
        }

        return $chainEmails[$tier] ?? null;
    }

    /**
     * Decides who this approval's next reminder (if any) goes to, and immediately claims that
     * attempt (BEFORE sending, not after - a crash between a successful send and this save must
     * never cause a duplicate escalation on the next tick, same reasoning the single-level
     * version already had for reminders_sent alone). Either increments reminders_sent at the
     * current tier, or - once that tier's cap is reached - advances escalation_tier by one and
     * resets reminders_sent to 0 for the new tier's first reminder.
     *
     * The claim itself is a conditional UPDATE re-checking the exact reminders_sent/
     * escalation_tier this call just read (OrderApproval::claimEscalation()) - two genuinely
     * overlapping cron runs (a manual "Run Now" racing the scheduled instance) racing on the same
     * approval must not both send; the loser's claim affects 0 rows and this returns a null
     * recipient, same as a chain that's run out of tiers.
     *
     * @param string[] $chainEmails
     * @return array{0: string|null, 1: bool} [recipient (null if nothing further to do - reminder
     *   cap reached at the deepest available tier, or this call lost a claim race), whether this
     *   claim advanced escalation_tier].
     */
    private function claimRecipientEmail(OrderApproval $approval, int $maxRemindersPerTier, array $chainEmails): array
    {
        $tier = $approval->getEscalationTier();
        $remindersSent = $approval->getRemindersSent();

        if ($remindersSent < $maxRemindersPerTier) {
            $recipient = $tier === 0 ? $approval->getAdminEmail() : ($chainEmails[$tier - 1] ?? null);
            if ($recipient === null) {
                // Tier itself is already past the end of a chain that shrank since it was set -
                // nothing left to remind at this tier either.
                return [null, false];
            }

            if (!$this->orderApprovalResource->claimEscalation(
                $approval,
                $remindersSent,
                $tier,
                $remindersSent + 1,
                $tier,
                false
            )) {
                return [null, false];
            }
            return [$recipient, false];
        }

        $nextTier = $tier + 1;
        $nextRecipient = $chainEmails[$nextTier - 1] ?? null;
        if ($nextRecipient === null) {
            return [null, false];
        }

        if (!$this->orderApprovalResource->claimEscalation($approval, $remindersSent, $tier, 1, $nextTier, true)) {
            return [null, false];
        }
        return [$nextRecipient, true];
    }

    private function sendEscalationEmail(OrderApproval $approval, Order $order, string $recipient): void
    {
        // This order's own store, not StoreManagerInterface::getStore()'s "current" store - see
        // Observer\HoldOrderForApproval::sendApprovalRequestEmail()'s own comment for the same
        // multi-store base-URL fix and why it matters here identically.
        $store = $order->getStore();
        $links = $this->orderApprovalManagement->buildDecisionLinks($store, $approval->getToken());

        $this->reminderEmailSender->send(
            self::XML_PATH_EMAIL_TEMPLATE,
            [
                'order_increment_id' => $order->getIncrementId(),
                'order_total' => $order->getGrandTotal(),
                'approve_url' => $links->getApproveUrl(),
                'reject_url' => $links->getRejectUrl(),
            ],
            $recipient,
            store: $store
        );
    }
}
