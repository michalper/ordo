<?php
declare(strict_types=1);

namespace Ordo\Automation\Observer;

use Magento\Framework\Event\Observer as EventObserver;
use Magento\Framework\Event\ObserverInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Cron\ReminderEmailSender;
use Ordo\Automation\Model\CustomerTagManager;
use Psr\Log\LoggerInterface;

/**
 * The classic first step of any lifecycle program: tag the new customer and send a welcome
 * email. Runs on the native customer_register_success event — no polling, no cron delay.
 */
class SendWelcomeEmail implements ObserverInterface
{
    private const string XML_PATH_EMAIL_TEMPLATE = 'ordo_welcome_email';
    public const TAG_NEW_CUSTOMER = 'new_customer';

    public function __construct(
        private readonly Config $config,
        private readonly CustomerTagManager $customerTagManager,
        private readonly ReminderEmailSender $reminderEmailSender,
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
            $this->reminderEmailSender->send(
                self::XML_PATH_EMAIL_TEMPLATE,
                ['customer_name' => $customer->getFirstname()],
                $customer->getEmail(),
                $customer->getFirstname()
            );
        } catch (\Throwable $e) {
            $this->logger->error(sprintf(
                'Ordo_Automation: failed to send welcome email to customer #%d: %s',
                $customer->getId(),
                $e->getMessage()
            ));
        }
    }
}
