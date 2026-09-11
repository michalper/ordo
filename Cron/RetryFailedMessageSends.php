<?php
declare(strict_types=1);

namespace Ordo\Automation\Cron;

use Ordo\Automation\Model\Campaign\ActionPool;
use Ordo\Automation\Model\Campaign\MessageSendRetryQueue;
use Ordo\Automation\Model\Cron\CronRunLogger;
use Ordo\Automation\Model\MessageSendRetry;
use Ordo\Automation\Model\ResourceModel\MessageSendRetry as MessageSendRetryResource;
use Ordo\Automation\Model\ResourceModel\MessageSendRetry\CollectionFactory as MessageSendRetryCollectionFactory;

/**
 * Re-attempts every ordo_message_send_retry row due for another try (see
 * etc/db_schema.xml's own comment and Model\Campaign\MessageSendRetryQueue, which creates these
 * rows in the first place from Send{Email,Sms,WhatsApp}'s own exhausted-SendRetrier failures).
 *
 * A due row is claimed the same way ordo_campaign_action_retry rows are — a single atomic
 * conditional UPDATE (ResourceModel\MessageSendRetry::claim()) that bumps next_retry_at forward
 * as the claim itself. On success the row is deleted outright; on another failure, attempts is
 * incremented and next_retry_at corrected to the real backoff delay; once attempts reaches
 * MAX_ATTEMPTS the row is left in place — Collection::addDueFilter() excludes it from future
 * selection, so it becomes a permanent dead letter for manual investigation rather than being
 * retried forever.
 *
 * Retrying means simply re-running the whole action (ActionPool::get($actionType)->execute())
 * against the same stored context+params — the same "just resume it" idea
 * Cron\RetryFailedCampaignActions already uses for scheduled-action resumes. That re-runs the
 * action's own consent/quiet-hours/frequency-cap checks too, which is correct: a customer who
 * opted out between the original attempt and this retry must not get sent to anyway.
 */
class RetryFailedMessageSends
{
    public const int MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly MessageSendRetryCollectionFactory $messageSendRetryCollectionFactory,
        private readonly MessageSendRetryResource $messageSendRetryResource,
        private readonly ActionPool $actionPool,
        private readonly MessageSendRetryQueue $messageSendRetryQueue,
        private readonly CronRunLogger $cronRunLogger
    ) {
    }

    public function execute(): void
    {
        $now = date('Y-m-d H:i:s');

        $due = $this->messageSendRetryCollectionFactory->create();
        $due->addDueFilter($now, self::MAX_ATTEMPTS);

        $succeeded = 0;
        $exhausted = 0;
        foreach ($due as $retry) {
            /** @var MessageSendRetry $retry */
            $this->retryOne($retry, $now, $succeeded, $exhausted);
        }

        $this->cronRunLogger->logSummary(sprintf(
            'retried %d message send(s), %d exhausted their retry budget',
            $succeeded,
            $exhausted
        ));
    }

    private function retryOne(MessageSendRetry $retry, string $now, int &$succeeded, int &$exhausted): void
    {
        $attemptNumber = $retry->getAttempts() + 1;
        $tentativeNextRetryAt = $this->messageSendRetryQueue->nextRetryAt($attemptNumber);

        if (!$this->messageSendRetryResource->claim($retry, $now, $tentativeNextRetryAt)) {
            // Lost the race to another overlapping cron run - it already claimed this row.
            return;
        }

        $action = $this->actionPool->get($retry->getActionType());
        if ($action === null) {
            // Action type no longer registered (module config changed since this row was
            // enqueued) - nothing sane to retry, leave it as a dead letter immediately.
            $exhausted++;
            return;
        }

        try {
            $context = $retry->getContext();
            $context[MessageSendRetryQueue::RETRY_CONTEXT_FLAG] = true;
            $action->execute($context, $retry->getParams());
            $this->messageSendRetryResource->delete($retry);
            $succeeded++;
        } catch (\Throwable $e) {
            $retry->setAttempts($attemptNumber);
            $retry->setLastError($e->getMessage());
            $retry->setNextRetryAt($tentativeNextRetryAt);
            $this->messageSendRetryResource->save($retry);

            $this->cronRunLogger->logFailure(
                sprintf('retry message send "%s" (attempt %d)', $retry->getActionType(), $attemptNumber),
                $e
            );

            if ($attemptNumber >= self::MAX_ATTEMPTS) {
                $exhausted++;
            }
        }
    }
}
