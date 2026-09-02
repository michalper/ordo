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
 *
 * A campaign action can itself cause a new dispatch — e.g. add_tag fires
 * "ordo_customer_tag_added", which DispatchTagAddedCampaigns turns right back into a
 * publish('tag_added', ...) call on this exact same topic/queue, from inside
 * CampaignDispatchConsumer::execute() while it's still consuming the message that triggered it.
 * Confirmed via a real CI run (queue_message_status): a message whose action chain re-entered
 * publish() this way consistently ended at status 4 ("retry required"), never 3 ("complete"),
 * with no matching exception anywhere — consistent with the DB-queue driver self-deadlocking on
 * its own `queue`/`queue_message` locks when a second message is inserted onto the same queue a
 * still-open consumer transaction already holds a lock on. CampaignDispatchConsumer flags
 * $isConsuming around its dispatch() call; a publish() that happens while that flag is set
 * defers the actual insert to register_shutdown_function, i.e. after the consumer's own
 * transaction has already committed and released its lock — a normal (non-reentrant) publish
 * from an HTTP request observer is completely unaffected and stays immediate.
 */
class CampaignDispatchPublisher
{
    public const TOPIC = 'ordo.automation.campaign.dispatch';

    private static bool $isConsuming = false;

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
        $payload = $this->serializer->serialize([
            'trigger_event' => $triggerEvent,
            'context' => $context,
        ]);

        if (self::$isConsuming) {
            register_shutdown_function(function () use ($payload): void {
                $this->publisher->publish(self::TOPIC, $payload);
            });
            return;
        }

        $this->publisher->publish(self::TOPIC, $payload);
    }

    /**
     * CampaignDispatchConsumer wraps its dispatch() call with this — see the class docblock.
     */
    public static function setConsuming(bool $isConsuming): void
    {
        self::$isConsuming = $isConsuming;
    }
}
