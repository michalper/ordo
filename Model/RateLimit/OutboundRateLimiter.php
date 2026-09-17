<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\RateLimit;

/**
 * Client-side pace-setter for outbound provider calls (Twilio SMS, Meta Graph API for WhatsApp,
 * Web Push, outbound webhooks) - previously every send was one synchronous HTTP call with zero
 * regard for the provider's own documented rate limit, so a campaign/cron matching thousands of
 * customers in one tick (Cron\SendWinBackEmails and friends already loop over a whole audience
 * serially) could hammer the provider well past what it allows and start eating 429s with no
 * backoff for it (SendRetrier retries the individual failed call, but never slows the *pace* of
 * the calls before one actually fails).
 *
 * Spaces consecutive calls for the same $channel at least 1/$maxPerSecond seconds apart by
 * polling Model\RateLimit\ProviderRateLimitStore::claim() until this process wins the next slot -
 * a genuinely atomic, cross-process claim (a single INSERT..ON DUPLICATE KEY UPDATE), not the
 * CacheInterface-backed read-then-write this replaced. That older version admitted its own race:
 * a handful of truly concurrent callers (multiple queue consumer instances, overlapping cron
 * runs) could each read the same pre-update last-call time and under-throttle briefly. Since
 * every process now contends for the same DB row's claim, the configured interval is enforced
 * across every process hitting a given provider, not just within one.
 *
 * $maxPerSecond <= 0 means "not configured" - a no-op, so this stays fully opt-in per channel
 * (see Helper\Config's own getter for each channel's default).
 */
class OutboundRateLimiter
{
    private const int POLL_INTERVAL_MICROSECONDS = 10_000;

    private readonly \Closure $sleep;

    /**
     * @param (callable(int): void)|null $sleep Overridable purely so unit tests don't have to
     *   sleep through a real poll loop (real DI usage takes the default, usleep()).
     */
    public function __construct(
        private readonly ProviderRateLimitStore $providerRateLimitStore,
        ?callable $sleep = null
    ) {
        $this->sleep = $sleep !== null ? \Closure::fromCallable($sleep) : usleep(...);
    }

    /**
     * Blocks until this call has claimed a slot at least 1/$maxPerSecond seconds after whichever
     * call (in this process or any other) last claimed one on the same $channel.
     */
    public function throttle(string $channel, float $maxPerSecond): void
    {
        if ($maxPerSecond <= 0) {
            return;
        }

        $minIntervalMicroseconds = (int) round(1_000_000 / $maxPerSecond);

        while (!$this->providerRateLimitStore->claim($channel, $minIntervalMicroseconds)) {
            ($this->sleep)(self::POLL_INTERVAL_MICROSECONDS);
        }
    }
}
