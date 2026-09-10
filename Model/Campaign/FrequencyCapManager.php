<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign;

use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\ResourceModel\MessageLog\CollectionFactory as MessageLogCollectionFactory;

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
 */
class FrequencyCapManager
{
    public function __construct(
        private readonly Config $config,
        private readonly MessageLogCollectionFactory $messageLogCollectionFactory
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

        $windowHours = $this->config->getFrequencyCapWindowHours($storeId);
        $sentSince = date('Y-m-d H:i:s', (int) strtotime("-{$windowHours} hours"));

        $collection = $this->messageLogCollectionFactory->create();
        $collection->addCustomerFilter($customerId);
        $collection->addSentSinceFilter($sentSince);
        $collection->addRealSendAttemptFilter();

        return $collection->getSize() < $maxMessages;
    }
}
