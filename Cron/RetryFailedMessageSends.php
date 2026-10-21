<?php
declare(strict_types=1);

namespace Ordo\Automation\Cron;

use Magento\Framework\Model\AbstractModel;
use Ordo\Automation\Api\Campaign\ActionInterface;
use Ordo\Automation\Model\Campaign\ActionPool;
use Ordo\Automation\Model\Campaign\MessageSendRetryQueue;
use Ordo\Automation\Model\Cron\CronRunLogger;
use Ordo\Automation\Model\MessageSendRetry;
use Ordo\Automation\Model\ResourceModel\MessageSendRetry as MessageSendRetryResource;
use Ordo\Automation\Model\ResourceModel\MessageSendRetry\CollectionFactory as MessageSendRetryCollectionFactory;
use Ordo\Automation\Model\RetryRecordInterface;

/**
 * Re-attempts every ordo_message_send_retry row due for another try (see
 * etc/db_schema.xml's own comment and Model\Campaign\MessageSendRetryQueue, which creates these
 * rows in the first place from Send{Email,Sms,WhatsApp}'s own exhausted-SendRetrier failures).
 * The claim/attempt/reschedule/dead-letter loop itself lives in AbstractRetryFailedCron.
 *
 * Retrying means simply re-running the whole action (ActionPool::get($actionType)->execute())
 * against the same stored context+params — the same "just resume it" idea
 * Cron\RetryFailedCampaignActions already uses for scheduled-action resumes. That re-runs the
 * action's own consent/quiet-hours/frequency-cap checks too, which is correct: a customer who
 * opted out between the original attempt and this retry must not get sent to anyway.
 *
 * @extends AbstractRetryFailedCron<MessageSendRetry>
 */
class RetryFailedMessageSends extends AbstractRetryFailedCron
{
    public const int MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly MessageSendRetryCollectionFactory $messageSendRetryCollectionFactory,
        MessageSendRetryResource $messageSendRetryResource,
        private readonly ActionPool $actionPool,
        private readonly MessageSendRetryQueue $messageSendRetryQueue,
        CronRunLogger $cronRunLogger
    ) {
        parent::__construct($messageSendRetryResource, $cronRunLogger);
    }

    protected function dueRetries(string $now): iterable
    {
        $due = $this->messageSendRetryCollectionFactory->create();
        $due->addDueFilter($now, self::MAX_ATTEMPTS);

        /** @var iterable<MessageSendRetry> $due */
        return $due;
    }

    protected function nextRetryAt(int $attemptNumber): string
    {
        return $this->messageSendRetryQueue->nextRetryAt($attemptNumber);
    }

    /**
     * @param MessageSendRetry $retry
     */
    protected function attempt(AbstractModel&RetryRecordInterface $retry): RetryAttemptOutcome
    {
        $action = $this->actionPool->get($retry->getActionType());
        if (!$action instanceof ActionInterface) {
            // Action type no longer registered (module config changed since this row was
            // enqueued) - nothing sane to retry, leave it as a dead letter immediately.
            return RetryAttemptOutcome::DeadLettered;
        }

        $context = $retry->getContext();
        $context[MessageSendRetryQueue::RETRY_CONTEXT_FLAG] = true;
        $action->execute($context, $retry->getParams());

        return RetryAttemptOutcome::Sent;
    }

    /**
     * @param MessageSendRetry $retry
     */
    protected function failureLabel(AbstractModel&RetryRecordInterface $retry, int $attemptNumber): string
    {
        return sprintf('retry message send "%s" (attempt %d)', $retry->getActionType(), $attemptNumber);
    }

    protected function maxAttempts(): int
    {
        return self::MAX_ATTEMPTS;
    }

    protected function subjectLabel(): string
    {
        return 'message send(s)';
    }
}
