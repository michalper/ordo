<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign;

use Ordo\Automation\Model\CampaignActionRetryFactory;
use Ordo\Automation\Model\ResourceModel\CampaignActionRetry as CampaignActionRetryResource;
use Ordo\Automation\Model\Retry\RetryBackoff;
use Psr\Log\LoggerInterface;

/**
 * Persisted retry queue for a resumeScheduledAction() call that failed - see
 * etc/db_schema.xml's ordo_campaign_action_retry comment. Cron\RunScheduledCampaignActions calls
 * enqueue() the first time a given resume fails (its own try/catch previously just logged and
 * abandoned the row); Cron\RetryFailedCampaignActions then owns re-attempting and rescheduling
 * from there.
 */
class ActionRetryQueue
{
    public const int BASE_DELAY_MINUTES = 5;

    public const int MAX_DELAY_MINUTES = 120;

    public function __construct(
        private readonly CampaignActionRetryFactory $campaignActionRetryFactory,
        private readonly CampaignActionRetryResource $campaignActionRetryResource,
        private readonly LoggerInterface $logger,
        private readonly RetryBackoff $retryBackoff
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function enqueue(int $campaignId, int $resumeActionId, array $context, \Throwable $error): void
    {
        try {
            $retry = $this->campaignActionRetryFactory->create();
            $retry->setCampaignId($campaignId);
            $retry->setResumeActionId($resumeActionId);
            $retry->setContext($context);
            $retry->setAttempts(1);
            $retry->setLastError($error->getMessage());
            $retry->setNextRetryAt($this->nextRetryAt(1));
            $this->campaignActionRetryResource->save($retry);
        } catch (\Throwable $e) {
            // A DB hiccup enqueuing the retry must not compound the original failure - the
            // original error is already logged by the caller (CronRunLogger::logFailure()); this
            // one just means the row won't get a second chance, which is no worse than the old
            // single-attempt behavior this replaces.
            $this->logger->error(sprintf(
                'Ordo_Automation: failed to enqueue campaign action retry: %s',
                $e->getMessage()
            ));
        }
    }

    /**
     * Exponential backoff - the math lives in Model\Retry\RetryBackoff (shared with the other
     * retry queues), parametrized by this queue's own BASE/MAX constants.
     */
    public function nextRetryAt(int $attempts): string
    {
        return $this->retryBackoff->nextRetryAt($attempts, self::BASE_DELAY_MINUTES, self::MAX_DELAY_MINUTES);
    }
}
