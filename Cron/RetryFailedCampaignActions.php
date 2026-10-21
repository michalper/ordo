<?php
declare(strict_types=1);

namespace Ordo\Automation\Cron;

use Magento\Framework\Model\AbstractModel;
use Ordo\Automation\Model\Campaign\ActionRetryQueue;
use Ordo\Automation\Model\CampaignActionRetry;
use Ordo\Automation\Model\CampaignDispatcher;
use Ordo\Automation\Model\Cron\CronRunLogger;
use Ordo\Automation\Model\ResourceModel\CampaignActionRetry as CampaignActionRetryResource;
use Ordo\Automation\Model\ResourceModel\CampaignActionRetry\CollectionFactory as CampaignActionRetryCollectionFactory;
use Ordo\Automation\Model\RetryRecordInterface;

/**
 * Re-attempts every ordo_campaign_action_retry row due for another try (see
 * etc/db_schema.xml's own comment and Model\Campaign\ActionRetryQueue, which creates these rows
 * in the first place from Cron\RunScheduledCampaignActions' own failures). The claim/attempt/
 * reschedule/dead-letter loop itself lives in AbstractRetryFailedCron; an attempt here means
 * resuming the scheduled action through CampaignDispatcher.
 *
 * @extends AbstractRetryFailedCron<CampaignActionRetry>
 */
class RetryFailedCampaignActions extends AbstractRetryFailedCron
{
    public const int MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly CampaignActionRetryCollectionFactory $campaignActionRetryCollectionFactory,
        CampaignActionRetryResource $campaignActionRetryResource,
        private readonly CampaignDispatcher $campaignDispatcher,
        private readonly ActionRetryQueue $actionRetryQueue,
        CronRunLogger $cronRunLogger
    ) {
        parent::__construct($campaignActionRetryResource, $cronRunLogger);
    }

    protected function dueRetries(string $now): iterable
    {
        $due = $this->campaignActionRetryCollectionFactory->create();
        $due->addDueFilter($now, self::MAX_ATTEMPTS);

        /** @var iterable<CampaignActionRetry> $due */
        return $due;
    }

    protected function nextRetryAt(int $attemptNumber): string
    {
        return $this->actionRetryQueue->nextRetryAt($attemptNumber);
    }

    /**
     * @param CampaignActionRetry $retry
     */
    protected function attempt(AbstractModel&RetryRecordInterface $retry): RetryAttemptOutcome
    {
        $this->campaignDispatcher->resumeScheduledAction(
            $retry->getCampaignId(),
            $retry->getResumeActionId(),
            $retry->getContext()
        );

        return RetryAttemptOutcome::Sent;
    }

    /**
     * @param CampaignActionRetry $retry
     */
    protected function failureLabel(AbstractModel&RetryRecordInterface $retry, int $attemptNumber): string
    {
        return sprintf(
            'retry campaign action resume for campaign #%d (attempt %d)',
            $retry->getCampaignId(),
            $attemptNumber
        );
    }

    protected function maxAttempts(): int
    {
        return self::MAX_ATTEMPTS;
    }

    protected function subjectLabel(): string
    {
        return 'campaign action(s)';
    }
}
