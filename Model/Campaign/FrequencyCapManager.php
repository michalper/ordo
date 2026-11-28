<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\ResourceModel\MessageLog\CollectionFactory as MessageLogCollectionFactory;
use Ordo\Automation\Setup\Patch\Data\AddCustomerMaxMessagesPerWindowAttribute;

/**
 * Unified, cross-channel frequency cap - closes the ROADMAP.md Tier 3 "unified suppression/
 * frequency capping across all channels" item. Every Send* campaign action
 * (SendEmail/SendSms/SendWhatsApp/SendPush) checks this before sending, so a customer matching
 * several campaigns in one dispatch tick (or the same campaign repeatedly) can't be emailed,
 * texted, WhatsApp'd, and push-notified back to back with no limit. Deliberately channel-agnostic
 * - counts `ordo_message_log` rows across every channel together, since the whole point is
 * capping total contact volume, not per-channel volume (a customer capped on email shouldn't be
 * able to receive the exact same volume again over SMS a moment later).
 *
 * Opt-in (disabled by default, see Config::isFrequencyCapEnabled()) - this changes existing send
 * behavior for every install upgrading into it, and an admin who's never had a volume complaint
 * shouldn't suddenly have sends silently suppressed the day this ships.
 *
 * Also takes a customer's own self-service preference (the `ordo_max_messages_per_window`
 * customer attribute, set via Api\ConsentPreferencesManagementInterface::
 * setMyMaxMessagesPerWindow() on the storefront "Communication Preferences" page) into account -
 * see docs/adr/0094. Always the TIGHTER of the admin cap and the customer's own value, never the
 * looser - a customer's own preference can only restrict contact, never expand beyond what the
 * admin already allows.
 */
class FrequencyCapManager
{
    public function __construct(
        private readonly Config $config,
        private readonly MessageLogCollectionFactory $messageLogCollectionFactory,
        private readonly CustomerRepositoryInterface $customerRepository
    ) {
    }

    public function hasCapacity(int $customerId, ?int $storeId = null): bool
    {
        if (!$this->config->isFrequencyCapEnabled($storeId)) {
            return true;
        }

        $maxMessages = $this->config->getFrequencyCapMaxMessages($storeId);
        if ($maxMessages <= 0) {
            // Misconfiguration (or a store that hasn't set this yet) shouldn't silently block
            // every send - a real cap is always a positive number, so treat <= 0 as "no cap"
            // rather than "cap at zero", the safer failure direction for a feature this reaching.
            return true;
        }

        $customerMax = $this->getCustomerOwnMaxMessages($customerId);
        if ($customerMax !== null && $customerMax < $maxMessages) {
            $maxMessages = $customerMax;
        }

        $windowHours = $this->config->getFrequencyCapWindowHours($storeId);
        $sentSince = date('Y-m-d H:i:s', (int) strtotime("-{$windowHours} hours"));

        $collection = $this->messageLogCollectionFactory->create();
        $collection->addCustomerFilter($customerId);
        $collection->addSentSinceFilter($sentSince);
        $collection->addRealSendAttemptFilter();

        return $collection->getSize() < $maxMessages;
    }

    /**
     * Null means no preference set (or the customer can't be loaded) - use the admin cap alone.
     * Same "<= 0 means no real cap" treatment the admin-configured value gets above, so a
     * customer who somehow ends up with a zero/negative value stored doesn't silently block
     * every send to themselves forever.
     */
    private function getCustomerOwnMaxMessages(int $customerId): ?int
    {
        try {
            $customer = $this->customerRepository->getById($customerId);
        } catch (\Throwable) {
            return null;
        }

        $attribute = $customer->getCustomAttribute(AddCustomerMaxMessagesPerWindowAttribute::ATTRIBUTE_CODE);
        if ($attribute === null) {
            return null;
        }

        $value = (int) $attribute->getValue();

        return $value > 0 ? $value : null;
    }
}
