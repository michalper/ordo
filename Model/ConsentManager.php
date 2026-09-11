<?php
declare(strict_types=1);

namespace Ordo\Automation\Model;

use Ordo\Automation\Model\ResourceModel\CustomerConsent as CustomerConsentResource;
use Ordo\Automation\Model\ResourceModel\CustomerConsent\CollectionFactory as CustomerConsentCollectionFactory;
use Ordo\Automation\Model\ResourceModel\CustomerConsentLog as CustomerConsentLogResource;

/**
 * Single source of truth for per-customer, per-channel marketing consent — Model\Campaign\
 * Action\SendEmail and SendSms both check hasConsent() before sending anything.
 *
 * Deliberately an OPT-OUT register, not opt-in: a customer with no ordo_customer_consent row at
 * all for a channel is treated as consented. Retrofitting every existing customer (and every
 * existing MFTF/unit test) to an explicit prior opt-in would be a breaking behavior change no
 * one asked for; what GDPR actually requires here is that an explicit opt-out is always honored,
 * which this does unconditionally the moment such a row exists, and that a data subject can see/
 * export/erase their own record (Controller\Adminhtml\Gdpr\*).
 *
 * setConsent() also appends a row to ordo_customer_consent_log (Model\CustomerConsentLog) on
 * every call - unlike ordo_customer_consent itself (upserted in place, current state only), that
 * table never updates a previous row, so "was this customer opted in for SMS on date X" stays
 * answerable after the state has since changed again.
 */
class ConsentManager
{
    public function __construct(
        private readonly CustomerConsentCollectionFactory $customerConsentCollectionFactory,
        private readonly CustomerConsentFactory $customerConsentFactory,
        private readonly CustomerConsentResource $customerConsentResource,
        private readonly CustomerConsentLogFactory $customerConsentLogFactory,
        private readonly CustomerConsentLogResource $customerConsentLogResource
    ) {
    }

    public function hasConsent(int $customerId, ConsentChannel $channel): bool
    {
        $consent = $this->findConsent($customerId, $channel);

        // No explicit row at all = consented by default (see class doc). A row exists only once
        // someone has actually recorded a preference either way.
        return !$consent instanceof CustomerConsent || $consent->isConsented();
    }

    public function setConsent(int $customerId, ConsentChannel $channel, bool $consented, ?string $source = null): void
    {
        $consent = $this->findConsent($customerId, $channel) ?? $this->customerConsentFactory->create();
        $consent->setCustomerId($customerId);
        $consent->setChannel($channel->value);
        $consent->setConsented($consented);
        $consent->setSource($source);

        $this->customerConsentResource->save($consent);

        // Append-only - never updates a previous log row, unlike ordo_customer_consent's own
        // upsert above. See CustomerConsentLog's own class doc for why this exists.
        $logEntry = $this->customerConsentLogFactory->create();
        $logEntry->setCustomerId($customerId);
        $logEntry->setChannel($channel->value);
        $logEntry->setConsented($consented);
        $logEntry->setSource($source);
        $this->customerConsentLogResource->save($logEntry);
    }

    /**
     * @return array<string, bool> channel value => consented, for every channel this customer
     *     has an explicit row for — channels with no row are simply absent (default-consented,
     *     see class doc), not listed as true. Stays string-keyed rather than ConsentChannel-keyed
     *     since this is also the shape Controller\Adminhtml\Gdpr\Export serializes verbatim into
     *     a data-subject's JSON export.
     */
    public function getConsentStates(int $customerId): array
    {
        $collection = $this->customerConsentCollectionFactory->create();
        $collection->addCustomerFilter($customerId);

        $states = [];
        foreach ($collection as $consent) {
            /** @var CustomerConsent $consent */
            $states[$consent->getChannel()] = $consent->isConsented();
        }

        return $states;
    }

    /**
     * Batch variant of hasConsent() - one query for the whole set instead of one per customer,
     * for the reminder crons (SendAbandonedCartReminders, SendCreditLimitAlerts,
     * SendOfferExpiryReminders, SendReorderReminders, SendWinBackEmails) that otherwise call
     * hasConsent() once per row inside a loop that can run to thousands of candidates - the same
     * batching CreditLimitCalculator::getUsedCreditForCustomers() already does for used-credit.
     *
     * @param int[] $customerIds
     * @return array<int, bool> customerId => consented, for every id in $customerIds (defaults to
     *     true - consented - for any id with no explicit opt-out row, same as hasConsent()).
     */
    public function hasConsentForCustomers(array $customerIds, ConsentChannel $channel): array
    {
        $states = array_fill_keys($customerIds, true);
        if ($customerIds === []) {
            return $states;
        }

        $collection = $this->customerConsentCollectionFactory->create();
        $collection->addCustomerIdsAndChannelFilter($customerIds, $channel->value);

        foreach ($collection as $consent) {
            /** @var CustomerConsent $consent */
            $states[$consent->getCustomerId()] = $consent->isConsented();
        }

        return $states;
    }

    private function findConsent(int $customerId, ConsentChannel $channel): ?CustomerConsent
    {
        $collection = $this->customerConsentCollectionFactory->create();
        $collection->addCustomerAndChannelFilter($customerId, $channel->value);

        /** @var CustomerConsent $consent getFirstItem() always returns a model instance - a
         *  fresh, id-less one when nothing matches, never false/null. */
        $consent = $collection->getFirstItem();
        return $consent->getId() ? $consent : null;
    }
}
