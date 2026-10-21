<?php
declare(strict_types=1);

namespace Ordo\Automation\Cron;

/**
 * What AbstractRetryFailedCron::attempt() resolved a claimed retry row into, beyond the
 * throw-for-backoff path: a Sent row is deleted and counted as a success; a DeadLettered row is
 * left in place and counted as exhausted, because no further attempt can ever work (e.g. the
 * action type is no longer registered); a Dropped row is deleted without counting anything (e.g.
 * the push subscription is gone - nothing left to retry, and not a failure either).
 */
enum RetryAttemptOutcome
{
    case Sent;
    case DeadLettered;
    case Dropped;
}
