<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Push;

use Ordo\Automation\Model\PushSendRetryFactory;
use Ordo\Automation\Model\ResourceModel\PushSendRetry as PushSendRetryResource;
use Psr\Log\LoggerInterface;

/**
 * Persisted retry queue for a single send_push subscription send whose SendRetrier in-process
 * retries were all exhausted - see etc/db_schema.xml's ordo_push_send_retry comment.
 * Model\Push\PushSubscriptionSender calls enqueue() the moment its own retries are exhausted for
 * one subscription (previously that just wrote an ordo_message_log STATUS_FAILED row and moved
 * on); Cron\RetryFailedPushSends then owns re-attempting and rescheduling from there.
 *
 * Same backoff shape as Model\Campaign\MessageSendRetryQueue - a separate class rather than a
 * shared one for the same reason that class gives for not sharing with ActionRetryQueue: these
 * retry structurally different things (a single subscription send vs. a whole campaign action),
 * even though the math is identical today.
 */
class PushSendRetryQueue
{
    public const int BASE_DELAY_MINUTES = 5;

    public const int MAX_DELAY_MINUTES = 120;

    public function __construct(
        private readonly PushSendRetryFactory $pushSendRetryFactory,
        private readonly PushSendRetryResource $pushSendRetryResource,
        private readonly LoggerInterface $logger
    ) {
    }

    public function enqueue(
        int $subscriptionId,
        int $customerId,
        ?int $campaignId,
        ?string $variant,
        string $payload,
        \Throwable $error
    ): void {
        try {
            $retry = $this->pushSendRetryFactory->create();
            $retry->setSubscriptionId($subscriptionId);
            $retry->setCustomerId($customerId);
            $retry->setCampaignId($campaignId);
            $retry->setVariant($variant);
            $retry->setPayload($payload);
            $retry->setAttempts(1);
            $retry->setLastError($error->getMessage());
            $retry->setNextRetryAt($this->nextRetryAt(1));
            $this->pushSendRetryResource->save($retry);
        } catch (\Throwable $e) {
            // A DB hiccup enqueuing the retry must not compound the original failure - the
            // original error is already logged by the caller; this one just means the send won't
            // get a second chance, which is no worse than the old drop-on-the-floor behavior this
            // replaces.
            $this->logger->error(sprintf(
                'Ordo_Automation: failed to enqueue push send retry for subscription #%d: %s',
                $subscriptionId,
                $e->getMessage()
            ));
        }
    }

    /**
     * Exponential backoff, same shape/constants as MessageSendRetryQueue::nextRetryAt().
     */
    public function nextRetryAt(int $attempts): string
    {
        $delayMinutes = min(self::BASE_DELAY_MINUTES * (2 ** max(0, $attempts - 1)), self::MAX_DELAY_MINUTES);
        return date('Y-m-d H:i:s', strtotime("+{$delayMinutes} minutes"));
    }
}
