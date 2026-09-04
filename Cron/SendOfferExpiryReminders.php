<?php
declare(strict_types=1);

namespace Ordo\Automation\Cron;

use Magento\Customer\Api\Data\CustomerInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Cron\ReminderEmailSender;
use Ordo\Automation\Model\Cron\ReminderLogStore;
use Ordo\Automation\Model\CustomerMapBuilder;
use Ordo\Automation\Model\Offer;
use Ordo\Automation\Model\ResourceModel\Offer\CollectionFactory as OfferCollectionFactory;
use Ordo\Automation\Model\SalesRepEmailContext;
use Ordo\Automation\Model\TriggerOutcomeLogger;
use Psr\Log\LoggerInterface;

/**
 * Every established B2B platform we could check (Adobe Commerce B2B, OroCommerce) only notifies about a quote
 * *after* something changes — nobody proactively warns the buyer before it expires. This cron closes that gap:
 * find offers expiring in N days and remind the customer, with a self-service "extend" option, before their
 * sales rep has to notice manually.
 */
class SendOfferExpiryReminders
{
    private const string XML_PATH_EMAIL_TEMPLATE = 'ordo_offer_expiring_soon';
    private const string REMINDER_TYPE_EXPIRING_SOON = 'expiring_soon';
    private const string REMINDER_LOG_TABLE = 'ordo_offer_reminder_log';

    public function __construct(
        private readonly Config $config,
        private readonly OfferCollectionFactory $offerCollectionFactory,
        private readonly CustomerMapBuilder $customerMapBuilder,
        private readonly ReminderEmailSender $emailSender,
        private readonly ReminderLogStore $reminderLogStore,
        private readonly SalesRepEmailContext $salesRepEmailContext,
        private readonly TriggerOutcomeLogger $triggerOutcomeLogger,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        if (!$this->config->isOfferReminderEnabled()) {
            return;
        }

        $leadDays = $this->config->getOfferLeadDays();
        $targetDate = date('Y-m-d', (int) strtotime("+{$leadDays} days"));

        $collection = $this->offerCollectionFactory->create();
        $collection->addExpiringOnFilter($targetDate);

        $offers = [];
        $customerIds = [];
        foreach ($collection as $offer) {
            /** @var Offer $offer */
            $offers[] = $offer;
            $customerIds[] = (int) $offer->getCustomerId();
        }

        $customerMap = $this->customerMapBuilder->build($customerIds);

        $sent = 0;
        foreach ($offers as $offer) {
            /** @var Offer $offer */
            if ($this->reminderAlreadySent((int) $offer->getEntityId(), self::REMINDER_TYPE_EXPIRING_SOON)) {
                continue;
            }

            $customerId = (int) $offer->getCustomerId();
            if (!isset($customerMap[$customerId])) {
                continue;
            }

            try {
                $customer = $customerMap[$customerId];
                $this->emailSender->send(
                    self::XML_PATH_EMAIL_TEMPLATE,
                    $this->buildTemplateVars($offer, $customer),
                    $customer->getEmail(),
                    $customer->getFirstname()
                );
                $this->logReminder((int) $offer->getEntityId(), self::REMINDER_TYPE_EXPIRING_SOON);
                $this->triggerOutcomeLogger->logSent(TriggerOutcomeLogger::TRIGGER_OFFER_EXPIRY, $customerId);
                $sent++;
            } catch (\Throwable $e) {
                $this->logger->error(sprintf(
                    'Ordo_Automation: failed to send offer expiry reminder for offer #%d: %s',
                    $offer->getEntityId(),
                    $e->getMessage()
                ));
            }
        }

        $this->logger->info(sprintf('Ordo_Automation: sent %d offer expiry reminders.', $sent));
    }

    /**
     * @return array<string, mixed>
     */
    private function buildTemplateVars(Offer $offer, CustomerInterface $customer): array
    {
        return array_merge([
            'customer_name' => $customer->getFirstname(),
            'offer_reference' => $offer->getReference(),
            'offer_total' => $offer->getTotal(),
            'offer_currency' => $offer->getCurrencyCode(),
            'offer_expires_at' => $offer->getExpiresAt(),
            'can_self_extend' => $offer->canSelfExtend($this->config->getOfferMaxSelfExtensions()),
        ], $this->salesRepEmailContext->getForCustomer($offer->getCustomerId()));
    }

    private function reminderAlreadySent(int $offerId, string $type): bool
    {
        return $this->reminderLogStore->countMatching(self::REMINDER_LOG_TABLE, [
            'offer_id = ?' => $offerId,
            'reminder_type = ?' => $type,
        ]) > 0;
    }

    private function logReminder(int $offerId, string $type): void
    {
        $this->reminderLogStore->insert(self::REMINDER_LOG_TABLE, [
            'offer_id' => $offerId,
            'reminder_type' => $type,
            'sent_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
