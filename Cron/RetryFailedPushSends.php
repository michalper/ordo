<?php
declare(strict_types=1);

namespace Ordo\Automation\Cron;

use Ordo\Automation\Model\Cron\CronRunLogger;
use Ordo\Automation\Model\Push\PushSendRetryQueue;
use Ordo\Automation\Model\Push\PushSubscriptionSender;
use Ordo\Automation\Model\PushSendRetry;
use Ordo\Automation\Model\PushSubscriptionFactory;
use Ordo\Automation\Model\ResourceModel\PushSendRetry as PushSendRetryResource;
use Ordo\Automation\Model\ResourceModel\PushSendRetry\CollectionFactory as PushSendRetryCollectionFactory;
use Ordo\Automation\Model\ResourceModel\PushSubscription as PushSubscriptionResource;

/**
 * Re-attempts every ordo_push_send_retry row due for another try (see etc/db_schema.xml's own
 * comment and Model\Push\PushSendRetryQueue, which creates these rows in the first place from
 * Model\Push\PushSubscriptionSender's own exhausted-SendRetrier failures).
 *
 * A due row is claimed the same way ordo_message_send_retry rows are — a single atomic
 * conditional UPDATE (ResourceModel\PushSendRetry::claim()) that bumps next_retry_at forward as
 * the claim itself. On success the row is deleted outright; on another failure, attempts is
 * incremented and next_retry_at corrected to the real backoff delay; once attempts reaches
 * MAX_ATTEMPTS the row is left in place — Collection::addDueFilter() excludes it from future
 * selection, so it becomes a permanent dead letter for manual investigation rather than being
 * retried forever.
 *
 * Unlike Cron\RetryFailedMessageSends, this never goes through Model\Campaign\ActionPool — a
 * push retry is a single subscription's send, not a whole campaign action, so it calls
 * Model\Push\PushSubscriptionSender directly. If the subscription itself no longer exists (the
 * customer unsubscribed, or a prior SubscriptionGoneException already deleted it) the row is
 * simply dropped — nothing left to retry, and that's not a failure worth counting against the
 * retry budget.
 */
class RetryFailedPushSends
{
    public const int MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly PushSendRetryCollectionFactory $pushSendRetryCollectionFactory,
        private readonly PushSendRetryResource $pushSendRetryResource,
        private readonly PushSubscriptionFactory $pushSubscriptionFactory,
        private readonly PushSubscriptionResource $pushSubscriptionResource,
        private readonly PushSubscriptionSender $pushSubscriptionSender,
        private readonly PushSendRetryQueue $pushSendRetryQueue,
        private readonly CronRunLogger $cronRunLogger
    ) {
    }

    public function execute(): void
    {
        $now = date('Y-m-d H:i:s');

        $due = $this->pushSendRetryCollectionFactory->create();
        $due->addDueFilter($now, self::MAX_ATTEMPTS);

        $succeeded = 0;
        $exhausted = 0;
        foreach ($due as $retry) {
            /** @var PushSendRetry $retry */
            $this->retryOne($retry, $now, $succeeded, $exhausted);
        }

        $this->cronRunLogger->logSummary(sprintf(
            'retried %d push send(s), %d exhausted their retry budget',
            $succeeded,
            $exhausted
        ));
    }

    private function retryOne(PushSendRetry $retry, string $now, int &$succeeded, int &$exhausted): void
    {
        $attemptNumber = $retry->getAttempts() + 1;
        $tentativeNextRetryAt = $this->pushSendRetryQueue->nextRetryAt($attemptNumber);

        if (!$this->pushSendRetryResource->claim($retry, $now, $tentativeNextRetryAt)) {
            // Lost the race to another overlapping cron run - it already claimed this row.
            return;
        }

        $subscription = $this->pushSubscriptionFactory->create();
        $this->pushSubscriptionResource->load($subscription, $retry->getSubscriptionId());
        if (!$subscription->getId()) {
            // Unsubscribed (or already deleted by a SubscriptionGoneException) since this retry
            // was enqueued - nothing left to retry, and not a failure worth counting.
            $this->pushSendRetryResource->delete($retry);
            return;
        }

        try {
            // A SubscriptionGoneException never reaches here - PushSubscriptionSender swallows
            // it itself (deletes the subscription, records the failure) even on a retry attempt,
            // since that's a permanent stop, not something worth another try. From here, a call
            // that doesn't throw means either a real successful send or that permanent-stop case
            // - both mean this retry row is done.
            $this->pushSubscriptionSender->send(
                $subscription,
                $retry->getPayload(),
                $retry->getCustomerId(),
                $retry->getCampaignId(),
                $retry->getVariant(),
                isRetryAttempt: true
            );
            $this->pushSendRetryResource->delete($retry);
            $succeeded++;
        } catch (\Throwable $e) {
            $retry->setAttempts($attemptNumber);
            $retry->setLastError($e->getMessage());
            $retry->setNextRetryAt($tentativeNextRetryAt);
            $this->pushSendRetryResource->save($retry);

            $this->cronRunLogger->logFailure(
                sprintf(
                    'retry push send to subscription #%d (attempt %d)',
                    $retry->getSubscriptionId(),
                    $attemptNumber
                ),
                $e
            );

            if ($attemptNumber >= self::MAX_ATTEMPTS) {
                $exhausted++;
            }
        }
    }
}
