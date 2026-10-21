<?php
declare(strict_types=1);

namespace Ordo\Automation\Cron;

use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Ordo\Automation\Model\Cron\CronRunLogger;
use Ordo\Automation\Model\ResourceModel\RetryRowResourceInterface;
use Ordo\Automation\Model\RetryRecordInterface;

/**
 * Shared claim/attempt/reschedule skeleton for the three persisted-retry crons
 * (RetryFailedCampaignActions, RetryFailedMessageSends, RetryFailedPushSends). ADRs 0003/0004
 * keep their tables and retry units separate, but the loop around them is deliberately identical
 * (ADR 0010): claim each due row atomically (the claim bumps next_retry_at forward, see
 * ResourceModel\ClaimsRetryRowTrait), re-attempt it, delete it on success, and on another failure
 * record attempts/last_error, correct next_retry_at to the real backoff delay and count rows
 * whose retry budget is now exhausted. Once attempts reaches the cron's max the row is left in
 * place - Collection::addDueFilter() excludes it from future selection, so it becomes a permanent
 * dead letter for manual investigation rather than being retried forever. Only what a single
 * attempt means - and the labels/limits around it - differs per cron, so that's all a subclass
 * supplies.
 *
 * @template TRetry of AbstractModel&RetryRecordInterface
 */
abstract class AbstractRetryFailedCron
{
    public function __construct(
        private readonly AbstractDb&RetryRowResourceInterface $retryResource,
        private readonly CronRunLogger $cronRunLogger
    ) {
    }

    /**
     * @return iterable<TRetry> every row due for another try right now - this cron's own
     *   collection with addDueFilter() applied against its own max attempts.
     */
    abstract protected function dueRetries(string $now): iterable;

    /**
     * @return string when the attempt after this one would run - this cron's own queue's backoff.
     */
    abstract protected function nextRetryAt(int $attemptNumber): string;

    /**
     * Re-attempts one claimed row. Throw to hit the reschedule/backoff path; return an outcome
     * for everything that must not count as a retryable failure (see RetryAttemptOutcome).
     *
     * @param TRetry $retry
     */
    abstract protected function attempt(AbstractModel&RetryRecordInterface $retry): RetryAttemptOutcome;

    /**
     * @param TRetry $retry
     * @return string what failed, for CronRunLogger::logFailure().
     */
    abstract protected function failureLabel(AbstractModel&RetryRecordInterface $retry, int $attemptNumber): string;

    /**
     * @return int the retry budget (this cron's own MAX_ATTEMPTS - kept a per-class public
     *   constant since tests and operators reference each cron's own value).
     */
    abstract protected function maxAttempts(): int;

    /**
     * @return string the run summary's noun phrase, e.g. 'campaign action(s)'.
     */
    abstract protected function subjectLabel(): string;

    public function execute(): void
    {
        $now = date('Y-m-d H:i:s');

        $succeeded = 0;
        $exhausted = 0;
        foreach ($this->dueRetries($now) as $retry) {
            $this->retryOne($retry, $now, $succeeded, $exhausted);
        }

        $this->cronRunLogger->logSummary(sprintf(
            'retried %d %s, %d exhausted their retry budget',
            $succeeded,
            $this->subjectLabel(),
            $exhausted
        ));
    }

    /**
     * @param TRetry $retry
     */
    private function retryOne(
        AbstractModel&RetryRecordInterface $retry,
        string $now,
        int &$succeeded,
        int &$exhausted
    ): void {
        $attemptNumber = $retry->getAttempts() + 1;
        $tentativeNextRetryAt = $this->nextRetryAt($attemptNumber);

        if (!$this->retryResource->claim($retry, $now, $tentativeNextRetryAt)) {
            // Lost the race to another overlapping cron run - it already claimed this row.
            return;
        }

        try {
            switch ($this->attempt($retry)) {
                case RetryAttemptOutcome::Sent:
                    $this->retryResource->delete($retry);
                    $succeeded++;
                    break;
                case RetryAttemptOutcome::Dropped:
                    $this->retryResource->delete($retry);
                    break;
                case RetryAttemptOutcome::DeadLettered:
                    $exhausted++;
                    break;
            }
        } catch (\Throwable $e) {
            $retry->setAttempts($attemptNumber);
            $retry->setLastError($e->getMessage());
            $retry->setNextRetryAt($tentativeNextRetryAt);
            $this->retryResource->save($retry);

            $this->cronRunLogger->logFailure($this->failureLabel($retry, $attemptNumber), $e);

            if ($attemptNumber >= $this->maxAttempts()) {
                $exhausted++;
            }
        }
    }
}
