<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\MessageLog;

use Ordo\Automation\Model\MessageLog;

/**
 * Every delivery-status webhook (SendGrid, Twilio, Meta/WhatsApp) has the same at-least-once
 * redelivery semantics - the provider can and does resend an event that already landed, and
 * network/queueing hiccups can make a later, more-final event arrive before an earlier, less-final
 * one. Without a guard, that redelivery/reordering can regress an `ordo_message_log` row backward
 * (e.g. an already-`FAILED` message flipping back to `SENT` because a stale "sent" event arrived
 * after the "failed" one). Originally fixed only in Controller\Email\StatusCallback; extracted here
 * so Controller\Sms\StatusCallback and Controller\WhatsApp\Webhook share the exact same rule
 * instead of each needing its own copy (or silently missing it, as they did before this class
 * existed).
 */
class StatusDowngradeGuard
{
    /**
     * @var array<string, int>
     */
    private const array STATUS_RANK = [
        MessageLog::STATUS_SENT => 0,
        MessageLog::STATUS_DELIVERED => 1,
        MessageLog::STATUS_UNDELIVERED => 2,
        MessageLog::STATUS_FAILED => 2,
        MessageLog::STATUS_OPTED_OUT => 2,
        MessageLog::STATUS_SUPPRESSED => 2,
    ];

    /**
     * True when $incomingStatus is strictly less final than $currentStatus in the precedence
     * table above - i.e. applying it would move the message log backward.  An unrecognized status
     * on either side ranks below everything (-1), so an unknown incoming status is never treated
     * as a downgrade of a known one, and a known incoming status always outranks an unknown
     * current one - callers only ever pass values that are already one of the MessageLog::STATUS_*
     * constants, so this only matters for defensive robustness, not the expected path.
     */
    public function isDowngrade(string $currentStatus, string $incomingStatus): bool
    {
        $currentRank = self::STATUS_RANK[$currentStatus] ?? -1;
        $incomingRank = self::STATUS_RANK[$incomingStatus] ?? -1;

        return $incomingRank < $currentRank;
    }
}
