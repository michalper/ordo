<?php
declare(strict_types=1);

namespace Ordo\Automation\Cron;

use Magento\Framework\App\Area;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Translate\Inline\StateInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Store\Model\StoreManagerInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\OrderApproval;
use Ordo\Automation\Model\ResourceModel\OrderApproval as OrderApprovalResource;
use Ordo\Automation\Model\ResourceModel\OrderApproval\CollectionFactory as OrderApprovalCollectionFactory;
use Psr\Log\LoggerInterface;

/**
 * If nobody has approved or rejected a held order within the configured window, this reminds
 * the admin once more, capped at a configurable maximum, so a stale approval doesn't sit
 * forgotten for weeks. No auto-decision is made either way — a human still has to act.
 */
class EscalateStalePendingApprovals
{
    private const XML_PATH_EMAIL_TEMPLATE = 'ordo_order_approval_escalation';
    private const XML_PATH_EMAIL_SENDER = 'general';
    private const MAX_ESCALATIONS = 3;

    public function __construct(
        private readonly Config $config,
        private readonly OrderApprovalCollectionFactory $orderApprovalCollectionFactory,
        private readonly OrderApprovalResource $orderApprovalResource,
        private readonly OrderCollectionFactory $orderCollectionFactory,
        private readonly TransportBuilder $transportBuilder,
        private readonly StoreManagerInterface $storeManager,
        private readonly StateInterface $inlineTranslation,
        private readonly LoggerInterface $logger
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

        $sent = 0;
        foreach ($collection as $approval) {
            /** @var OrderApproval $approval */
            if ($approval->getRemindersSent() >= self::MAX_ESCALATIONS) {
                continue;
            }

            /** @var Order $order */
            $order = $this->orderCollectionFactory->create()
                ->addFieldToFilter('entity_id', $approval->getOrderId())
                ->getFirstItem();

            if (!$order->getId()) {
                continue;
            }

            try {
                $this->sendEscalationEmail($approval, $order);
                $approval->setData('reminders_sent', $approval->getRemindersSent() + 1);
                $this->orderApprovalResource->save($approval);
                $sent++;
            } catch (\Throwable $e) {
                $this->logger->error(
                    sprintf(
                        'Ordo_Automation: failed to send approval escalation for order #%d: %s',
                        (int) $order->getEntityId(),
                        $e->getMessage()
                    )
                );
            }
        }

        $this->logger->info(sprintf('Ordo_Automation: sent %d order approval escalations.', $sent));
    }

    private function sendEscalationEmail(OrderApproval $approval, Order $order): void
    {
        $store = $this->storeManager->getStore();
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
