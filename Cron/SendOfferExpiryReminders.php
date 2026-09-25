<?php
declare(strict_types=1);

namespace Ordo\Automation\Cron;

use Magento\Customer\Api\Data\CustomerInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\ConsentChannel;
use Ordo\Automation\Model\ConsentManager;
use Ordo\Automation\Model\Cron\CronRunLogger;
use Ordo\Automation\Model\Cron\ReminderEmailSender;
use Ordo\Automation\Model\Cron\ReminderLogStore;
use Ordo\Automation\Model\CustomerMapBuilder;
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
        private readonly ConsentManager $consentManager,
        private readonly TriggerOutcomeLogger $triggerOutcomeLogger,
        private readonly CronRunLogger $cronRunLogger
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
        // One query for the whole batch instead of one hasConsent() call per offer inside the
        // loop below - found via a performance audit, same reasoning as
        // CreditLimitCalculator::getUsedCreditForCustomers().
        $consentByCustomer = $this->consentManager->hasConsentForCustomers($customerIds, ConsentChannel::Email);

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

            // A customer who opted out of email must never receive this reminder, same consent
            // gate every other channel's send action applies before sending anything.
            if (!($consentByCustomer[$customerId] ?? true)) {
                continue;
            }

            // Claim (log) BEFORE sending, not after - a crash between a successful send and the
            // log write must never cause a resend on the next tick. If the send itself then
            // fails, the claim is rolled back so this offer is retried next run. claim() (not a
            // plain insert()) re-checks "already sent" atomically - the cheap reminderAlreadySent()
            // check above is only a fast pre-filter, not the actual guard against a double-send.
            $reminderLogRow = $this->buildReminderLogRow(
                (int) $offer->getEntityId(),
                self::REMINDER_TYPE_EXPIRING_SOON
            );
            if (!$this->reminderLogStore->claim(
                self::REMINDER_LOG_TABLE,
                $this->reminderLogMatchConditions((int) $offer->getEntityId(), self::REMINDER_TYPE_EXPIRING_SOON),
                $reminderLogRow
            )) {
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
                $this->triggerOutcomeLogger->logSent(TriggerOutcomeLogger::TRIGGER_OFFER_EXPIRY, $customerId);
                $sent++;
            } catch (\Throwable $e) {
                $this->reminderLogStore->deleteMatching(self::REMINDER_LOG_TABLE, $reminderLogRow);
                $this->cronRunLogger->logFailure(
                    sprintf('send offer expiry reminder for offer #%d', $offer->getEntityId()),
                    $e
                );
            }
        }

        $this->cronRunLogger->logSummary(sprintf('sent %d offer expiry reminders', $sent));
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
        ], $this->salesRepEmailContext->getForLoadedCustomer($customer));
    }

    private function reminderAlreadySent(int $offerId, string $type): bool
    {
        return $this->reminderLogStore->countMatching(
            self::REMINDER_LOG_TABLE,
            $this->reminderLogMatchConditions($offerId, $type)
        ) > 0;
    }

    /**
     * @return array<string, int|string>
     */
    private function reminderLogMatchConditions(int $offerId, string $type): array
    {
        return [
            'offer_id = ?' => $offerId,
            'reminder_type = ?' => $type,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildReminderLogRow(int $offerId, string $type): array
    {
        return [
            'offer_id' => $offerId,
            'reminder_type' => $type,
            'sent_at' => date('Y-m-d H:i:s'),
        ];
    }
}
