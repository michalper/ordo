<?php
declare(strict_types=1);

namespace Ordo\Automation\Cron;

use Magento\Framework\App\Area;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Translate\Inline\StateInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Cron\CronRunLogger;
use Ordo\Automation\Model\OrderApproval;
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
    private const string XML_PATH_EMAIL_SENDER = 'general';

    public function __construct(
        private readonly Config $config,
        private readonly OrderApprovalCollectionFactory $orderApprovalCollectionFactory,
        private readonly OrderApprovalResource $orderApprovalResource,
        private readonly OrderCollectionFactory $orderCollectionFactory,
        private readonly TransportBuilder $transportBuilder,
        private readonly StateInterface $inlineTranslation,
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

            [$recipient, $tierAdvanced] = $this->claimRecipientEmail($approval, $maxRemindersPerTier, $chainEmails);
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
                // retried next run, not silently counted as having happened. Only touch
                // escalation_tier when this claim actually advanced it, so a same-tier reminder's
                // rollback writes reminders_sent alone, exactly like the original single-level
                // version did.
                $approval->setData('reminders_sent', $remindersSentBeforeClaim);
                if ($tierAdvanced) {
                    $approval->setData('escalation_tier', $escalationTierBeforeClaim);
                }
                $this->orderApprovalResource->save($approval);
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
     * @param string[] $chainEmails
     * @return array{0: string|null, 1: bool} [recipient (null if nothing further to do - reminder
     *   cap reached at the deepest available tier), whether this claim advanced escalation_tier].
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

            $approval->setData('reminders_sent', $remindersSent + 1);
            $this->orderApprovalResource->save($approval);
            return [$recipient, false];
        }

        $nextTier = $tier + 1;
        $nextRecipient = $chainEmails[$nextTier - 1] ?? null;
        if ($nextRecipient === null) {
            return [null, false];
        }

        $approval->setData('escalation_tier', $nextTier);
        $approval->setData('reminders_sent', 1);
        $this->orderApprovalResource->save($approval);
        return [$nextRecipient, true];
    }

    private function sendEscalationEmail(OrderApproval $approval, Order $order, string $recipient): void
    {
        // This order's own store, not StoreManagerInterface::getStore()'s "current" store - see
        // Observer\HoldOrderForApproval::sendApprovalRequestEmail()'s own comment for the same
        // multi-store base-URL fix and why it matters here identically.
        $store = $order->getStore();
        $baseUrl = rtrim((string) $store->getBaseUrl(), '/');
        $token = $approval->getToken();

        $this->inlineTranslation->suspend();

        $transport = $this->transportBuilder
            ->setTemplateIdentifier(self::XML_PATH_EMAIL_TEMPLATE)
            ->setTemplateOptions(['area' => Area::AREA_FRONTEND, 'store' => $store->getId()])
            ->setTemplateVars([
                'order_increment_id' => $order->getIncrementId(),
                'order_total' => $order->getGrandTotal(),
                'approve_url' => $baseUrl . '/ordo/approval/approve/token/' . $token,
                'reject_url' => $baseUrl . '/ordo/approval/reject/token/' . $token,
                'store' => $store,
            ])
            ->setFromByScope(self::XML_PATH_EMAIL_SENDER, $store->getId())
            ->addTo($recipient)
            ->getTransport();

        $transport->sendMessage();

        $this->inlineTranslation->resume();
    }
}
