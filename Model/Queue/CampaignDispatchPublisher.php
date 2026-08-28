<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Queue;

use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\Serialize\SerializerInterface;

/**
 * The only thing a trigger observer does now: hand off "here's what just happened" to the
 * queue and return immediately. CampaignDispatchConsumer picks the message up (via cron-run
 * consumers on this install's DB queue driver — see AGENTS.md) and does the actual
 * condition/action work, so a checkout or registration request never waits on campaign logic.
 */
class CampaignDispatchPublisher
{
    public const TOPIC = 'ordo.automation.campaign.dispatch';

    public function __construct(
        private readonly PublisherInterface $publisher,
        private readonly SerializerInterface $serializer
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function publish(string $triggerEvent, array $context): void
    {
        $this->publisher->publish(self::TOPIC, $this->serializer->serialize([
            'trigger_event' => $triggerEvent,
            'context' => $context,
        ]));
    }
}
