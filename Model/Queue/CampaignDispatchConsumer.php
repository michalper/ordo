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

        $this->campaignDispatcher->dispatch($triggerEvent, (array) ($decoded['context'] ?? []));
    }
}
