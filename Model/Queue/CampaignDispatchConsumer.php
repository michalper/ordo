<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Queue;

use Magento\Framework\Serialize\SerializerInterface;
use Ordo\Automation\Model\CampaignDispatchDeadLetterFactory;
use Ordo\Automation\Model\CampaignDispatcher;
use Ordo\Automation\Model\ResourceModel\CampaignDispatchDeadLetter as CampaignDispatchDeadLetterResource;
use Psr\Log\LoggerInterface;

/**
 * Consumer side of CampaignDispatchPublisher — decodes the message and runs the same
 * CampaignDispatcher::dispatch() the old synchronous observers called directly, just off the
 * request thread. A malformed message is logged and dropped, not requeued: there's no sender
 * left to retry against, only a queue worker.
 *
 * Anything this can't recover from (an undecodable message, or an exception dispatch() itself
 * didn't already swallow internally - see CampaignDispatcher's own per-campaign try/catch) is now
 * caught here and persisted to ordo_campaign_dispatch_dead_letter instead of propagating
 * uncaught. Previously such a message was simply lost with only a framework-level error log; this
 * doesn't retry it (there's no automatic re-processing of dead letters), it just makes the
 * failure visible instead of silent.
 */
class CampaignDispatchConsumer
{
    public function __construct(
        private readonly CampaignDispatcher $campaignDispatcher,
        private readonly SerializerInterface $serializer,
        private readonly LoggerInterface $logger,
        private readonly CampaignDispatchGuard $dispatchGuard,
        private readonly CampaignDispatchDeadLetterFactory $deadLetterFactory,
        private readonly CampaignDispatchDeadLetterResource $deadLetterResource
    ) {
    }

    public function execute(string $message): void
    {
        try {
            $decoded = $this->serializer->unserialize($message);
        } catch (\Throwable $e) {
            $this->deadLetter($message, null, $e);
            return;
        }

        $triggerEvent = (string) ($decoded['trigger_event'] ?? '');

        if ($triggerEvent === '') {
            $this->logger->error('Ordo_Automation: dropped a campaign dispatch message with no trigger_event.');
            return;
        }

        // See CampaignDispatchPublisher's class doc: a campaign action running inside this
        // dispatch() call (e.g. add_tag) can itself trigger a new publish() back onto this same
        // topic/queue — flagging that window is what makes CampaignDispatchPublisher defer such
        // a publish instead of re-entering it while this message is still being consumed.
        $this->dispatchGuard->setConsuming(true);
        try {
            $this->campaignDispatcher->dispatch($triggerEvent, (array) ($decoded['context'] ?? []));
        } catch (\Throwable $e) {
            $this->deadLetter($message, $triggerEvent, $e);
        } finally {
            $this->dispatchGuard->setConsuming(false);
        }
    }

    private function deadLetter(string $message, ?string $triggerEvent, \Throwable $e): void
    {
        $this->logger->error(sprintf(
            'Ordo_Automation: campaign dispatch failed, dead-lettering: %s',
            $e->getMessage()
        ));

        try {
            $deadLetter = $this->deadLetterFactory->create();
            $deadLetter->setTriggerEvent($triggerEvent);
            $deadLetter->setMessage($message);
            $deadLetter->setError($e->getMessage());
            $this->deadLetterResource->save($deadLetter);
        } catch (\Throwable $persistError) {
            // A DB hiccup persisting the dead letter must not crash the consumer or resurface the
            // original exception - it's already logged above.
            $this->logger->error(sprintf(
                'Ordo_Automation: failed to persist campaign dispatch dead letter: %s',
                $persistError->getMessage()
            ));
        }
    }
}
