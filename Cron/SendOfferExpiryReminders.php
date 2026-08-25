<?php
declare(strict_types=1);

namespace Ordo\Automation\Cron;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\App\Area;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Translate\Inline\StateInterface;
use Magento\Store\Model\StoreManagerInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Offer;
use Ordo\Automation\Model\ResourceModel\Offer\CollectionFactory as OfferCollectionFactory;
use Psr\Log\LoggerInterface;

/**
 * Every established B2B platform we could check (Adobe Commerce B2B, OroCommerce) only notifies about a quote
 * *after* something changes — nobody proactively warns the buyer before it expires. This cron closes that gap:
 * find offers expiring in N days and remind the customer, with a self-service "extend" option, before their
 * sales rep has to notice manually.
 */
class SendOfferExpiryReminders
{
    private const XML_PATH_EMAIL_TEMPLATE = 'ordo_offer_expiring_soon';
    private const XML_PATH_EMAIL_SENDER = 'general';
    private const REMINDER_TYPE_EXPIRING_SOON = 'expiring_soon';

    public function __construct(
        private readonly Config $config,
        private readonly OfferCollectionFactory $offerCollectionFactory,
        private readonly ResourceConnection $resourceConnection,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly TransportBuilder $transportBuilder,
        private readonly StoreManagerInterface $storeManager,
        private readonly StateInterface $inlineTranslation,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        if (!$this->config->isOfferReminderEnabled()) {
            return;
        }

        $leadDays = $this->config->getOfferLeadDays();
        $targetDate = date('Y-m-d', strtotime("+{$leadDays} days"));

        $collection = $this->offerCollectionFactory->create();
        $collection->addExpiringOnFilter($targetDate);

        $sent = 0;
        foreach ($collection as $offer) {
            if ($this->reminderAlreadySent((int) $offer->getEntityId(), self::REMINDER_TYPE_EXPIRING_SOON)) {
                continue;
            }

            try {
                $this->sendReminder($offer);
                $this->logReminder((int) $offer->getEntityId(), self::REMINDER_TYPE_EXPIRING_SOON);
                $sent++;
            } catch (\Throwable $e) {
                $this->logger->error(
                    sprintf('Ordo_Automation: failed to send offer expiry reminder for offer #%d: %s', $offer->getEntityId(), $e->getMessage())
                );
            }
        }

        $this->logger->info(sprintf('Ordo_Automation: sent %d offer expiry reminders.', $sent));
    }

    private function sendReminder(Offer $offer): void
    {
        $customer = $this->customerRepository->getById($offer->getCustomerId());
        $store = $this->storeManager->getStore();

        $this->inlineTranslation->suspend();

        $transport = $this->transportBuilder
            ->setTemplateIdentifier(self::XML_PATH_EMAIL_TEMPLATE)
            ->setTemplateOptions(['area' => Area::AREA_FRONTEND, 'store' => $store->getId()])
            ->setTemplateVars([
                'customer_name' => $customer->getFirstname(),
                'offer_reference' => $offer->getReference(),
                'offer_total' => $offer->getTotal(),
                'offer_currency' => $offer->getCurrencyCode(),
                'offer_expires_at' => $offer->getExpiresAt(),
                'can_self_extend' => $offer->canSelfExtend($this->config->getOfferMaxSelfExtensions()),
                'store' => $store,
            ])
            ->setFromByScope(self::XML_PATH_EMAIL_SENDER, $store->getId())
            ->addTo($customer->getEmail(), $customer->getFirstname())
            ->getTransport();

        $transport->sendMessage();

        $this->inlineTranslation->resume();
    }

    private function reminderAlreadySent(int $offerId, string $type): bool
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('ordo_offer_reminder_log');

        $count = $connection->fetchOne(
            $connection->select()
                ->from($table, 'COUNT(*)')
                ->where('offer_id = ?', $offerId)
                ->where('reminder_type = ?', $type)
        );

        return (int) $count > 0;
    }

    private function logReminder(int $offerId, string $type): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('ordo_offer_reminder_log');

        $connection->insert($table, [
            'offer_id' => $offerId,
            'reminder_type' => $type,
            'sent_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
