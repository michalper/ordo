<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Queue;

use Magento\Framework\Serialize\SerializerInterface;
use Ordo\Automation\Model\CampaignDispatcher;
use Psr\Log\LoggerInterface;

/**
 * Consumer side of CampaignDispatchPublisher — decodes the message and runs the same
 * CampaignDispatcher::dispatch() the old synchronous observers called directly, just off the
 * request thread. A malformed message is logged and dropped, not requeued: there's no sender
 * left to retry against, only a queue worker.
 */
class CampaignDispatchConsumer
{
    public function __construct(
        private readonly CampaignDispatcher $campaignDispatcher,
        private readonly SerializerInterface $serializer,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(string $message): void
    {
        $decoded = $this->serializer->unserialize($message);
        $triggerEvent = (string) ($decoded['trigger_event'] ?? '');

        if ($triggerEvent === '') {
            $this->logger->error('Ordo_Automation: dropped a campaign dispatch message with no trigger_event.');
            return;
        }

        // See CampaignDispatchPublisher's class doc: a campaign action running inside this
        // dispatch() call (e.g. add_tag) can itself trigger a new publish() back onto this same
        // topic/queue — flagging that window is what makes CampaignDispatchPublisher defer such
        // a publish instead of self-deadlocking on this message's own still-open queue lock.
        CampaignDispatchPublisher::setConsuming(true);
        try {
            $this->campaignDispatcher->dispatch($triggerEvent, (array) ($decoded['context'] ?? []));
        } finally {
            CampaignDispatchPublisher::setConsuming(false);
        }
    }
}
