<?php
declare(strict_types=1);

namespace Ordo\Automation\Observer;

use Magento\Framework\App\Area;
use Magento\Framework\Event\Observer as EventObserver;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Translate\Inline\StateInterface;
use Magento\Store\Model\StoreManagerInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\CustomerTagManager;
use Psr\Log\LoggerInterface;

/**
 * The classic first step of any lifecycle program: tag the new customer and send a welcome
 * email. Runs on the native customer_register_success event — no polling, no cron delay.
 */
class SendWelcomeEmail implements ObserverInterface
{
    private const XML_PATH_EMAIL_TEMPLATE = 'ordo_welcome_email';
    private const XML_PATH_EMAIL_SENDER = 'general';
    public const TAG_NEW_CUSTOMER = 'new_customer';

    public function __construct(
        private readonly Config $config,
        private readonly CustomerTagManager $customerTagManager,
        private readonly TransportBuilder $transportBuilder,
        private readonly StoreManagerInterface $storeManager,
        private readonly StateInterface $inlineTranslation,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(EventObserver $observer): void
    {
        if (!$this->config->isLifecycleEmailsEnabled()) {
            return;
        }

        $customer = $observer->getEvent()->getCustomer();
        if (!$customer || !$customer->getId()) {
            return;
        }

        $this->customerTagManager->addTag((int) $customer->getId(), self::TAG_NEW_CUSTOMER);

        try {
            $store = $this->storeManager->getStore();
            $this->inlineTranslation->suspend();

            $transport = $this->transportBuilder
                ->setTemplateIdentifier(self::XML_PATH_EMAIL_TEMPLATE)
                ->setTemplateOptions(['area' => Area::AREA_FRONTEND, 'store' => $store->getId()])
                ->setTemplateVars([
                    'customer_name' => $customer->getFirstname(),
                    'store' => $store,
                ])
                ->setFromByScope(self::XML_PATH_EMAIL_SENDER, $store->getId())
                ->addTo($customer->getEmail(), $customer->getFirstname())
                ->getTransport();

            $transport->sendMessage();
            $this->inlineTranslation->resume();
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('Ordo_Automation: failed to send welcome email to customer #%d: %s', $customer->getId(), $e->getMessage()));
        }
    }
}
