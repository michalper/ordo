<?php
declare(strict_types=1);

namespace Ordo\Automation\Cron;

use Magento\Customer\Api\Data\CustomerInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\ConsentManager;
use Ordo\Automation\Model\ConsentTopic;
use Ordo\Automation\Model\Cron\CronRunLogger;
use Ordo\Automation\Model\Cron\ReminderEmailSender;
use Ordo\Automation\Model\Cron\ReminderLogStore;
use Ordo\Automation\Model\CustomerMapBuilder;
use Ordo\Automation\Model\CustomerStoreResolver;
use Ordo\Automation\Model\Offer;
use Ordo\Automation\Model\ResourceModel\Offer\CollectionFactory as OfferCollectionFactory;
use Ordo\Automation\Model\SalesRepEmailContext;
use Ordo\Automation\Model\TriggerOutcomeLogger;

/**
 * Every established B2B platform we could check (Adobe Commerce B2B, OroCommerce) only notifies about a quote
 * *after* something changes — nobody proactively warns the buyer before it expires. This cron closes that gap:
 * find offers expiring in N days and remind the customer, with a self-service "extend" option, before their
 * sales rep has to notice manually.
 */
class SendOfferExpiryReminders extends AbstractBatchClaimedReminderCron
{
    private const string XML_PATH_EMAIL_TEMPLATE = 'ordo_offer_expiring_soon';
    private const string REMINDER_TYPE_EXPIRING_SOON = 'expiring_soon';
    private const string REMINDER_LOG_TABLE = 'ordo_offer_reminder_log';

    public function __construct(
        Config $config,
        private readonly OfferCollectionFactory $offerCollectionFactory,
        CustomerMapBuilder $customerMapBuilder,
        ReminderEmailSender $emailSender,
        ReminderLogStore $reminderLogStore,
        SalesRepEmailContext $salesRepEmailContext,
        ConsentManager $consentManager,
        TriggerOutcomeLogger $triggerOutcomeLogger,
        CronRunLogger $cronRunLogger,
        CustomerStoreResolver $customerStoreResolver
    ) {
        parent::__construct(
            $config,
            $customerMapBuilder,
            $emailSender,
            $reminderLogStore,
            $salesRepEmailContext,
            $consentManager,
            $triggerOutcomeLogger,
            $cronRunLogger,
            $customerStoreResolver
        );
    }

    protected function isEnabled(): bool
    {
        return $this->config->isOfferReminderEnabled();
    }

    protected function reminderLogTable(): string
    {
        return self::REMINDER_LOG_TABLE;
    }

    protected function emailTemplatePath(): string
    {
        return self::XML_PATH_EMAIL_TEMPLATE;
    }

    protected function consentTopic(): ConsentTopic
    {
        // Promotional - an expiring-offer nudge is fundamentally about driving a purchase, same
        // bucket as win-back/abandoned-cart, see ConsentTopic::Promotional's own docblock.
        return ConsentTopic::Promotional;
    }

    protected function triggerOutcomeCode(): string
    {
        return TriggerOutcomeLogger::TRIGGER_OFFER_EXPIRY;
    }

    protected function reminderLabel(): string
    {
        return 'offer expiry reminder';
    }

    protected function rowNoun(): string
    {
        return 'offer';
    }

    protected function logIdColumn(): string
    {
        return 'offer_id';
    }

    protected function logStaticConditions(): array
    {
        return ['reminder_type = ?' => self::REMINDER_TYPE_EXPIRING_SOON];
    }

    protected function fetchDueRows(): array
    {
        $leadDays = $this->config->getOfferLeadDays();
        $targetDate = date('Y-m-d', (int) strtotime("+{$leadDays} days"));

        $collection = $this->offerCollectionFactory->create();
        $collection->addExpiringOnFilter($targetDate);

        $rows = [];
        foreach ($collection as $offer) {
            /** @var Offer $offer */
            $rows[] = [
                'id' => (int) $offer->getEntityId(),
                'customerId' => (int) $offer->getCustomerId(),
                'row' => $offer,
            ];
        }

        return $rows;
    }

    protected function reminderLogMatchConditions(int $rowId): array
    {
        return [
            'offer_id = ?' => $rowId,
            'reminder_type = ?' => self::REMINDER_TYPE_EXPIRING_SOON,
        ];
    }

    protected function buildReminderLogRow(int $rowId): array
    {
        return [
            'offer_id' => $rowId,
            'reminder_type' => self::REMINDER_TYPE_EXPIRING_SOON,
            'sent_at' => date('Y-m-d H:i:s'),
        ];
    }

    protected function buildTemplateVars(object $row, CustomerInterface $customer): array
    {
        /** @var Offer $row */
        return array_merge([
            'customer_name' => $customer->getFirstname(),
            'offer_reference' => $row->getReference(),
            'offer_total' => $row->getTotal(),
            'offer_currency' => $row->getCurrencyCode(),
            'offer_expires_at' => $row->getExpiresAt(),
            'can_self_extend' => $row->canSelfExtend($this->config->getOfferMaxSelfExtensions()),
        ], $this->salesRepEmailContext->getForLoadedCustomer($customer));
    }
}
