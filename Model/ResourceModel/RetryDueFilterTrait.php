<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel;

/**
 * Shared addDueFilter() for the three retry-row collections (CampaignActionRetry,
 * MessageSendRetry, PushSendRetry) - the selection half of the claim contract
 * ClaimsRetryRowTrait implements, so the two can't drift on what "due" means.
 */
trait RetryDueFilterTrait
{
    /**
     * Every row due to be retried right now and not yet exhausted - the Cron\RetryFailed* crons'
     * own selection criteria. A row whose attempts has already reached the configured max is
     * excluded here rather than deleted, so it stays visible as a dead letter without ever being
     * picked up again.
     */
    public function addDueFilter(string $now, int $maxAttempts): self
    {
        $this->addFieldToFilter('next_retry_at', ['lteq' => $now]);
        $this->addFieldToFilter('attempts', ['lt' => $maxAttempts]);
        $this->setOrder('next_retry_at', self::SORT_ORDER_ASC);
        return $this;
    }
}
