<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign\Action;

/**
 * Shared retry-with-backoff for the actual provider call inside each channel's send action
 * (SendEmail/SendSms/SendWhatsApp/SendPush) - previously a transient provider hiccup (a Twilio/
 * Graph API/SMTP timeout or 5xx) permanently dropped that message on the very first failure, with
 * no automatic recovery at all (`Cron\RunScheduledCampaignActions`'s own docblock even admits
 * this: "a row that failed stays failed; there's no retry queue for this yet"). Retries up to
 * MAX_ATTEMPTS times with a short exponential backoff via `usleep()` - a bounded, synchronous
 * delay (at most ~600ms total) is acceptable here since this runs inside a single queue
 * consumer's processing of one message, and is a much smaller change than standing up a separate
 * persistent retry queue/cron.
 *
 * Deliberately does NOT retry an exception that means "this destination is permanently invalid"
 * (an SMS opt-out, a dead push subscription, a malformed phone number) - retrying one of those
 * would just waste the provider call MAX_ATTEMPTS times for an outcome that can never change on
 * a second attempt. Callers pass an optional `$shouldRetry` predicate to say which exceptions are
 * actually worth retrying; the default retries everything.
 */
class SendRetrier
{
    private const int MAX_ATTEMPTS = 3;
    private const int DEFAULT_BASE_DELAY_MICROSECONDS = 200_000;

    /**
     * @param int $baseDelayMicroseconds Overridable purely so unit tests don't have to sleep
     *     through real backoff delays (real DI usage takes the default).
     */
    public function __construct(private readonly int $baseDelayMicroseconds = self::DEFAULT_BASE_DELAY_MICROSECONDS)
    {
    }

    /**
     * @template T
     * @param callable(): T $send
     * @param (callable(\Throwable): bool)|null $shouldRetry Return false to fail fast on a given
     *     exception instead of burning through the remaining attempts on it.
     * @return T
     * @throws \Throwable the last attempt's exception, once retries are exhausted or
     *     $shouldRetry says not to retry this one at all.
     */
    public function attempt(callable $send, ?callable $shouldRetry = null)
    {
        $attempt = 0;

        while (true) {
            $attempt++;
            try {
                return $send();
            } catch (\Throwable $e) {
                $retryable = $shouldRetry === null || $shouldRetry($e);
                if (!$retryable || $attempt >= self::MAX_ATTEMPTS) {
                    throw $e;
                }
                usleep($this->baseDelayMicroseconds * (2 ** ($attempt - 1)));
            }
        }
    }
}
