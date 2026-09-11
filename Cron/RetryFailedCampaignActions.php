<?php
declare(strict_types=1);

namespace Ordo\Automation\Cron;

use Ordo\Automation\Model\Campaign\ActionRetryQueue;
use Ordo\Automation\Model\CampaignActionRetry;
use Ordo\Automation\Model\CampaignDispatcher;
use Ordo\Automation\Model\Cron\CronRunLogger;
use Ordo\Automation\Model\ResourceModel\CampaignActionRetry as CampaignActionRetryResource;
use Ordo\Automation\Model\ResourceModel\CampaignActionRetry\CollectionFactory as CampaignActionRetryCollectionFactory;

/**
 * Re-attempts every ordo_campaign_action_retry row due for another try (see
 * etc/db_schema.xml's own comment and Model\Campaign\ActionRetryQueue, which creates these rows
 * in the first place from Cron\RunScheduledCampaignActions' own failures).
 *
 * A due row is claimed the same way ordo_campaign_scheduled_action rows are — a single atomic
 * conditional UPDATE (ResourceModel\CampaignActionRetry::claim()) — except here the claim itself
 * is "bump next_retry_at forward", not "set an executed_at that's never touched again", since a
 * row here is expected to be attempted more than once. On success the row is deleted outright
 * (there's nothing further to track); on another failure, attempts is incremented and
 * next_retry_at corrected to the real backoff delay; once attempts reaches MAX_ATTEMPTS the row
 * is left in place — Collection::addDueFilter() excludes it from future selection, so it becomes
 * a permanent dead letter for manual investigation rather than being retried forever.
 */
class RetryFailedCampaignActions
{
    public const int MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly CampaignActionRetryCollectionFactory $campaignActionRetryCollectionFactory,
        private readonly CampaignActionRetryResource $campaignActionRetryResource,
        private readonly CampaignDispatcher $campaignDispatcher,
        private readonly ActionRetryQueue $actionRetryQueue,
        private readonly CronRunLogger $cronRunLogger
    ) {
    }

    public function execute(): void
    {
        $now = date('Y-m-d H:i:s');

        $due = $this->campaignActionRetryCollectionFactory->create();
        $due->addDueFilter($now, self::MAX_ATTEMPTS);

        $succeeded = 0;
        $exhausted = 0;
        foreach ($due as $retry) {
            /** @var CampaignActionRetry $retry */
            $this->retryOne($retry, $now, $succeeded, $exhausted);
        }

        $this->cronRunLogger->logSummary(sprintf(
            'retried %d campaign action(s), %d exhausted their retry budget',
            $succeeded,
            $exhausted
        ));
    }

    private function retryOne(CampaignActionRetry $retry, string $now, int &$succeeded, int &$exhausted): void
    {
        $attemptNumber = $retry->getAttempts() + 1;
        $tentativeNextRetryAt = $this->actionRetryQueue->nextRetryAt($attemptNumber);

        if (!$this->campaignActionRetryResource->claim($retry, $now, $tentativeNextRetryAt)) {
            // Lost the race to another overlapping cron run - it already claimed this row.
            return;
        }

        try {
            $this->campaignDispatcher->resumeScheduledAction(
                $retry->getCampaignId(),
                $retry->getResumeActionId(),
                $retry->getContext()
            );
            $this->campaignActionRetryResource->delete($retry);
            $succeeded++;
        } catch (\Throwable $e) {
            $retry->setAttempts($attemptNumber);
            $retry->setLastError($e->getMessage());
            $retry->setNextRetryAt($tentativeNextRetryAt);
            $this->campaignActionRetryResource->save($retry);

            $this->cronRunLogger->logFailure(
                sprintf(
                    'retry campaign action resume for campaign #%d (attempt %d)',
                    $retry->getCampaignId(),
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
