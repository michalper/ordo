<?php
declare(strict_types=1);

namespace Ordo\Automation\Cron;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Area;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Translate\Inline\StateInterface;
use Magento\Store\Model\StoreManagerInterface;
use Ordo\Automation\Helper\Config;
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
    private const string XML_PATH_EMAIL_SENDER = 'general';
    private const string REMINDER_TYPE_EXPIRING_SOON = 'expiring_soon';

    public function __construct(
        private readonly Config $config,
        private readonly OfferCollectionFactory $offerCollectionFactory,
        private readonly ResourceConnection $resourceConnection,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly TransportBuilder $transportBuilder,
        private readonly StoreManagerInterface $storeManager,
        private readonly StateInterface $inlineTranslation,
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

        $customerMap = $this->buildCustomerMap($customerIds);

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
                $this->sendReminder($offer, $customerMap[$customerId]);
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
     * @param int[] $customerIds
     * @return array<int, CustomerInterface>
     */
    private function buildCustomerMap(array $customerIds): array
    {
        if ($customerIds === []) {
            return [];
        }

        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('entity_id', array_values(array_unique($customerIds)), 'in')
            ->create();

        $customerMap = [];
        foreach ($this->customerRepository->getList($searchCriteria)->getItems() as $customer) {
            $customerMap[(int) $customer->getId()] = $customer;
        }

        return $customerMap;
    }

    private function sendReminder(Offer $offer, CustomerInterface $customer): void
    {
        $store = $this->storeManager->getStore();

        $this->inlineTranslation->suspend();

        $transport = $this->transportBuilder
            ->setTemplateIdentifier(self::XML_PATH_EMAIL_TEMPLATE)
            ->setTemplateOptions(['area' => Area::AREA_FRONTEND, 'store' => $store->getId()])
            ->setTemplateVars(array_merge([
                'customer_name' => $customer->getFirstname(),
                'offer_reference' => $offer->getReference(),
                'offer_total' => $offer->getTotal(),
                'offer_currency' => $offer->getCurrencyCode(),
                'offer_expires_at' => $offer->getExpiresAt(),
                'can_self_extend' => $offer->canSelfExtend($this->config->getOfferMaxSelfExtensions()),
                'store' => $store,
            ], $this->salesRepEmailContext->getForCustomer($offer->getCustomerId())))
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
