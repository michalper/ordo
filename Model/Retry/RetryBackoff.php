<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Retry;

/**
 * Exponential backoff shared by the persisted retry queues (Campaign\ActionRetryQueue,
 * Campaign\MessageSendRetryQueue, Push\PushSendRetryQueue) - same shape as SendRetrier's
 * in-process retries but in minutes instead of microseconds (a persisted retry is inherently
 * coarser-grained - the retry crons only run periodically, not inline in a request), capped so a
 * row that's failed many times still gets retried at a bounded cadence rather than drifting
 * further and further apart. The base/cap stay constants on each queue (they parametrize this
 * call) because each queue owns its own cadence, even though the values are identical today.
 * Not `final` only because the Magento2 coding standard prohibits it (plugins/proxies).
 */
class RetryBackoff
{
    public function nextRetryAt(int $attempts, int $baseDelayMinutes, int $maxDelayMinutes): string
    {
        $delayMinutes = min($baseDelayMinutes * (2 ** max(0, $attempts - 1)), $maxDelayMinutes);
        return date('Y-m-d H:i:s', time() + $delayMinutes * 60);
    }
}
