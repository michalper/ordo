<?php
declare(strict_types=1);

namespace Ordo\Automation\Cron;

use Magento\Framework\Model\AbstractModel;
use Ordo\Automation\Model\Cron\CronRunLogger;
use Ordo\Automation\Model\Push\PushSendRetryQueue;
use Ordo\Automation\Model\Push\PushSubscriptionSender;
use Ordo\Automation\Model\PushSendRetry;
use Ordo\Automation\Model\PushSubscriptionFactory;
use Ordo\Automation\Model\ResourceModel\PushSendRetry as PushSendRetryResource;
use Ordo\Automation\Model\ResourceModel\PushSendRetry\CollectionFactory as PushSendRetryCollectionFactory;
use Ordo\Automation\Model\ResourceModel\PushSubscription as PushSubscriptionResource;
use Ordo\Automation\Model\RetryRecordInterface;

/**
 * Re-attempts every ordo_push_send_retry row due for another try (see etc/db_schema.xml's own
 * comment and Model\Push\PushSendRetryQueue, which creates these rows in the first place from
 * Model\Push\PushSubscriptionSender's own exhausted-SendRetrier failures). The claim/attempt/
 * reschedule/dead-letter loop itself lives in AbstractRetryFailedCron.
 *
 * Unlike Cron\RetryFailedMessageSends, this never goes through Model\Campaign\ActionPool — a
 * push retry is a single subscription's send, not a whole campaign action (ADR 0004), so it calls
 * Model\Push\PushSubscriptionSender directly. If the subscription itself no longer exists (the
 * customer unsubscribed, or a prior SubscriptionGoneException already deleted it) the row is
 * simply dropped — nothing left to retry, and that's not a failure worth counting against the
 * retry budget.
 *
 * @extends AbstractRetryFailedCron<PushSendRetry>
 */
class RetryFailedPushSends extends AbstractRetryFailedCron
{
    public const int MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly PushSendRetryCollectionFactory $pushSendRetryCollectionFactory,
        PushSendRetryResource $pushSendRetryResource,
        private readonly PushSubscriptionFactory $pushSubscriptionFactory,
        private readonly PushSubscriptionResource $pushSubscriptionResource,
        private readonly PushSubscriptionSender $pushSubscriptionSender,
        private readonly PushSendRetryQueue $pushSendRetryQueue,
        CronRunLogger $cronRunLogger
    ) {
        parent::__construct($pushSendRetryResource, $cronRunLogger);
    }

    protected function dueRetries(string $now): iterable
    {
        $due = $this->pushSendRetryCollectionFactory->create();
        $due->addDueFilter($now, self::MAX_ATTEMPTS);

        /** @var iterable<PushSendRetry> $due */
        return $due;
    }

    protected function nextRetryAt(int $attemptNumber): string
    {
        return $this->pushSendRetryQueue->nextRetryAt($attemptNumber);
    }

    /**
     * @param PushSendRetry $retry
     */
    protected function attempt(AbstractModel&RetryRecordInterface $retry): RetryAttemptOutcome
    {
        $subscription = $this->pushSubscriptionFactory->create();
        $this->pushSubscriptionResource->load($subscription, $retry->getSubscriptionId());
        if (!$subscription->getId()) {
            // Unsubscribed (or already deleted by a SubscriptionGoneException) since this retry
            // was enqueued - nothing left to retry, and not a failure worth counting.
            return RetryAttemptOutcome::Dropped;
        }

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

        return RetryAttemptOutcome::Sent;
    }

    /**
     * @param PushSendRetry $retry
     */
    protected function failureLabel(AbstractModel&RetryRecordInterface $retry, int $attemptNumber): string
    {
        return sprintf(
            'retry push send to subscription #%d (attempt %d)',
            $retry->getSubscriptionId(),
            $attemptNumber
        );
    }

    protected function maxAttempts(): int
    {
        return self::MAX_ATTEMPTS;
    }

    protected function subjectLabel(): string
    {
        return 'push send(s)';
    }
}
