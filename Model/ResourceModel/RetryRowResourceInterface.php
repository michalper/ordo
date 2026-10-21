<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel;

use Magento\Framework\Model\AbstractModel;
use Ordo\Automation\Model\RetryRecordInterface;

/**
 * A resource model that can atomically claim a due retry row (CampaignActionRetry,
 * MessageSendRetry, PushSendRetry - each fulfills this via ClaimsRetryRowTrait). Exists so
 * Cron\AbstractRetryFailedCron can hold "the retry table's resource" without caring which of the
 * three it is.
 */
interface RetryRowResourceInterface
{
    public function claim(
        AbstractModel&RetryRecordInterface $model,
        string $now,
        string $tentativeNextRetryAt
    ): bool;
}
