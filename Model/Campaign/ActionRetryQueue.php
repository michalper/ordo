<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign;

use Ordo\Automation\Model\CampaignActionRetryFactory;
use Ordo\Automation\Model\ResourceModel\CampaignActionRetry as CampaignActionRetryResource;
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
        private readonly LoggerInterface $logger
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
     * Exponential backoff, same shape as SendRetrier's in-process retries but in minutes instead
     * of microseconds (a persisted retry is inherently coarser-grained - this cron only runs
     * periodically, not inline in a request), capped at MAX_DELAY_MINUTES so a row that's failed
     * many times still gets retried at a bounded cadence rather than drifting further and
     * further apart.
     */
    public function nextRetryAt(int $attempts): string
    {
        $delayMinutes = min(self::BASE_DELAY_MINUTES * (2 ** max(0, $attempts - 1)), self::MAX_DELAY_MINUTES);
        return date('Y-m-d H:i:s', strtotime("+{$delayMinutes} minutes"));
    }
}
