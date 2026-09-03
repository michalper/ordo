<?php
declare(strict_types=1);

namespace Ordo\Automation\Observer;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\App\Area;
use Magento\Framework\Event\Observer as EventObserver;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Math\Random;
use Magento\Framework\Translate\Inline\StateInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order as OrderResource;
use Magento\Store\Model\StoreManagerInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\OrderApproval;
use Ordo\Automation\Model\OrderApprovalFactory;
use Ordo\Automation\Model\ResourceModel\OrderApproval as OrderApprovalResource;
use Ordo\Automation\Setup\Patch\Data\AddCustomerSpendLimitAttributes;
use Ordo\Automation\Setup\Patch\Data\AddPendingApprovalOrderStatus;
use Psr\Log\LoggerInterface;

/**
 * Fires right after an order is placed. If the ordering customer has both a spend limit and
 * an approval-admin email configured, and this order is over the limit, the order is held
 * (status -> pending approval, same "new" state so inventory reservation is untouched) and an
 * approve/reject email is sent to the admin with a token-based link — no login required, so a
 * busy admin can act from their phone without hunting for credentials.
 */
class HoldOrderForApproval implements ObserverInterface
{
    private const XML_PATH_EMAIL_TEMPLATE = 'ordo_order_approval_request';
    private const XML_PATH_EMAIL_SENDER = 'general';

    public function __construct(
        private readonly Config $config,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly OrderResource $orderResource,
        private readonly OrderApprovalFactory $orderApprovalFactory,
        private readonly OrderApprovalResource $orderApprovalResource,
        private readonly Random $random,
        private readonly TransportBuilder $transportBuilder,
        private readonly StoreManagerInterface $storeManager,
        private readonly StateInterface $inlineTranslation,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(EventObserver $observer): void
    {
        if (!$this->config->isOrderApprovalEnabled()) {
            return;
        }

        /** @var Order $order */
        $order = $observer->getEvent()->getOrder();
        if (!$order || !$order->getCustomerId()) {
            return;
        }

        try {
            $customer = $this->customerRepository->getById((int) $order->getCustomerId());
        } catch (\Throwable $e) {
            return;
        }

        $spendLimitAttribute = $customer->getCustomAttribute(AddCustomerSpendLimitAttributes::ATTRIBUTE_SPEND_LIMIT);
        $adminEmailAttribute = $customer->getCustomAttribute(
            AddCustomerSpendLimitAttributes::ATTRIBUTE_APPROVAL_ADMIN_EMAIL
        );

        $spendLimit = $spendLimitAttribute ? (float) $spendLimitAttribute->getValue() : 0.0;
        $adminEmail = $adminEmailAttribute ? (string) $adminEmailAttribute->getValue() : '';

        if ($spendLimit <= 0.0 || $adminEmail === '' || (float) $order->getGrandTotal() <= $spendLimit) {
            return;
        }

        $token = $this->random->getUniqueHash();

        // Order the two saves this way deliberately: at this point in the order-placement
        // flow (sales_order_place_after, dispatched from within Order's own save process),
        // $order->getEntityId() is reliably still null — this save() call is what actually
        // performs the insert and assigns it. Building the approval row before this point
        // would silently record order_id as 0 (found running this against a real checkout;
        // see VERIFICATION.md). Reading getEntityId() only after this save is what makes it
        // safe.
        $order->setStatus(AddPendingApprovalOrderStatus::STATUS_PENDING_APPROVAL);
        $this->orderResource->save($order);

        /** @var OrderApproval $approval */
        $approval = $this->orderApprovalFactory->create();
        $approval->setData([
            'order_id' => $order->getEntityId(),
            'admin_email' => $adminEmail,
            'token' => $token,
            'status' => OrderApproval::STATUS_PENDING,
        ]);
        $this->orderApprovalResource->save($approval);

        try {
            $this->sendApprovalRequestEmail($order, $adminEmail, $token);
        } catch (\Throwable $e) {
            $this->logger->error(sprintf(
                'Ordo_Automation: failed to send approval request email for order #%d: %s',
                $order->getEntityId(),
                $e->getMessage()
            ));
        }
    }

    private function sendApprovalRequestEmail(Order $order, string $adminEmail, string $token): void
    {
        $store = $this->storeManager->getStore();
        $baseUrl = rtrim((string) $store->getBaseUrl(), '/');

        $this->inlineTranslation->suspend();

        $transport = $this->transportBuilder
            ->setTemplateIdentifier(self::XML_PATH_EMAIL_TEMPLATE)
            ->setTemplateOptions(['area' => Area::AREA_FRONTEND, 'store' => $store->getId()])
            ->setTemplateVars([
                'order_increment_id' => $order->getIncrementId(),
                'order_total' => $order->getGrandTotal(),
                'customer_name' => trim(
                    $order->getCustomerFirstname() . ' ' . $order->getCustomerLastname()
                ),
                'approve_url' => $baseUrl . '/ordo/approval/approve/token/' . $token,
                'reject_url' => $baseUrl . '/ordo/approval/reject/token/' . $token,
                'store' => $store,
            ])
            ->setFromByScope(self::XML_PATH_EMAIL_SENDER, $store->getId())
            ->addTo($adminEmail)
            ->getTransport();

        $transport->sendMessage();

        $this->inlineTranslation->resume();
    }
}
