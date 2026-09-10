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
 * the admin once more, capped at a configurable maximum, so a stale approval doesn't sit
 * forgotten for weeks. No auto-decision is made either way — a human still has to act.
 */
class EscalateStalePendingApprovals
{
    private const string XML_PATH_EMAIL_TEMPLATE = 'ordo_order_approval_escalation';
    private const string XML_PATH_EMAIL_SENDER = 'general';
    private const int MAX_ESCALATIONS = 3;

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

        $collection = $this->orderApprovalCollectionFactory->create();
        $collection->addStalePendingFilter($cutoff);

        $approvals = [];
        foreach ($collection as $approval) {
            /** @var OrderApproval $approval */
            if ($approval->getRemindersSent() < self::MAX_ESCALATIONS) {
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

            // Claim (increment reminders_sent) BEFORE sending, not after - a crash between a
            // successful send and this save must never cause a duplicate escalation on the next
            // tick. If the send itself then fails, the increment is rolled back so this approval
            // is retried next run.
            $remindersSentBeforeClaim = $approval->getRemindersSent();
            $approval->setData('reminders_sent', $remindersSentBeforeClaim + 1);
            $this->orderApprovalResource->save($approval);

            try {
                $this->sendEscalationEmail($approval, $order);
                if ($order->getCustomerId()) {
                    $this->triggerOutcomeLogger->logSent(
                        TriggerOutcomeLogger::TRIGGER_ORDER_APPROVAL,
                        (int) $order->getCustomerId()
                    );
                }
                $sent++;
            } catch (\Throwable $e) {
                $approval->setData('reminders_sent', $remindersSentBeforeClaim);
                $this->orderApprovalResource->save($approval);
                $this->cronRunLogger->logFailure(
                    sprintf('send approval escalation for order #%d', (int) $order->getEntityId()),
                    $e
                );
            }
        }

        $this->cronRunLogger->logSummary(sprintf('sent %d order approval escalations', $sent));
    }

    private function sendEscalationEmail(OrderApproval $approval, Order $order): void
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
            ->addTo($approval->getAdminEmail())
            ->getTransport();

        $transport->sendMessage();

        $this->inlineTranslation->resume();
    }
}
