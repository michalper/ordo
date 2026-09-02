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
 * That's a second INSERT into a queue a still-open consumer transaction already touches —
 * defensively deferred rather than risking lock contention with it, even though a real CI
 * investigation (queue_message_status ending at status 4 = COMPLETE, per
 * Magento\MysqlMq\Model\QueueManagement — not a deadlock) later showed the dispatch chain itself
 * completes fine either way. CampaignDispatchConsumer flags CampaignDispatchGuard around its
 * dispatch() call; a publish() that happens while that flag is set defers the actual insert to
 * register_shutdown_function, i.e. after the consumer's own transaction has already committed —
 * a normal (non-reentrant) publish from an HTTP request observer is completely unaffected and
 * stays immediate.
 */
class CampaignDispatchPublisher
{
    public const TOPIC = 'ordo.automation.campaign.dispatch';

    /**
     * @var callable(callable(): void): void
     */
    private $shutdownScheduler;

    /**
     * @param (callable(callable(): void): void)|null $shutdownScheduler Defaults to the real
     *  register_shutdown_function() — overridable so a test can substitute an immediate
     *  invocation and actually assert what the deferred publish does, instead of the deferred
     *  branch being unreachable from a unit test entirely.
     */
    public function __construct(
        private readonly PublisherInterface $publisher,
        private readonly SerializerInterface $serializer,
        private readonly CampaignDispatchGuard $dispatchGuard,
        ?callable $shutdownScheduler = null
    ) {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        $this->shutdownScheduler = $shutdownScheduler ?? register_shutdown_function(...);
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

        if ($this->dispatchGuard->isConsuming()) {
            ($this->shutdownScheduler)(function () use ($payload): void {
                $this->publisher->publish(self::TOPIC, $payload);
            });
            return;
        }

        $this->publisher->publish(self::TOPIC, $payload);
    }
}
