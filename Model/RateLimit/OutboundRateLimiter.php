<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\RateLimit;

use Magento\Framework\App\CacheInterface;

/**
 * Client-side pace-setter for outbound provider calls (Twilio SMS, Meta Graph API for WhatsApp,
 * Web Push) - previously every send was one synchronous HTTP call with zero regard for the
 * provider's own documented rate limit, so a campaign/cron matching thousands of customers in
 * one tick (Cron\SendWinBackEmails and friends already loop over a whole audience serially) could
 * hammer the provider well past what it allows and start eating 429s with no backoff for it
 * (SendRetrier retries the individual failed call, but never slows the *pace* of the calls before
 * one actually fails).
 *
 * Spaces consecutive calls for the same $channel at least 1/$maxPerSecond seconds apart via a
 * short usleep() - not a hard reject like Model\Approval\ApprovalRateLimiter (that guards a
 * public, anonymous endpoint against abuse; this guards an outbound call this module itself
 * makes, so the right response to "too soon" is "wait a little", not "refuse"). Cache-backed, not
 * a DB-backed conditional UPDATE like this module's claim-before-use patterns - the same
 * "acceptable trade-off, not worth a table just for this" reasoning ApprovalRateLimiter's own
 * docblock gives: a handful of truly concurrent callers could each read the same pre-update
 * last-call time and under-throttle briefly, which only means occasionally sending a little
 * faster than configured, never slower or lost - the provider's own final say (a real 429, which
 * SendRetrier still handles) is the actual hard limit either way.
 *
 * $maxPerSecond <= 0 means "not configured" - a no-op, so this stays fully opt-in per channel
 * (see Helper\Config's own getter for each channel's default).
 */
class OutboundRateLimiter
{
    private const string CACHE_KEY_PREFIX = 'ordo_outbound_rl_';

    /**
     * Cache entries are keyed per-channel and only ever need to live long enough to bridge the
     * gap between two consecutive calls - a few seconds of staleness just means the very next
     * call after a long idle period isn't throttled at all, which is correct (nothing to space
     * out from).
     */
    private const int CACHE_LIFETIME_SECONDS = 60;

    private readonly \Closure $sleep;
    private readonly \Closure $now;

    /**
     * @param (callable(int): void)|null $sleep Overridable purely so unit tests don't have to
     *   sleep through a real throttle delay (real DI usage takes the default, usleep()).
     * @param (callable(bool): float)|null $now Overridable for the same reason - deterministic
     *   "elapsed time" in tests instead of real wall-clock microtime().
     */
    public function __construct(
        private readonly CacheInterface $cache,
        ?callable $sleep = null,
        ?callable $now = null
    ) {
        $this->sleep = $sleep !== null ? \Closure::fromCallable($sleep) : usleep(...);
        $this->now = $now !== null ? \Closure::fromCallable($now) : microtime(...);
    }

    /**
     * Blocks just long enough that this call is at least 1/$maxPerSecond seconds after the last
     * call this same $channel made, then records this call's own time for the next caller to
     * space out from in turn.
     */
    public function throttle(string $channel, float $maxPerSecond): void
    {
        if ($maxPerSecond <= 0) {
            return;
        }

        $minIntervalMicroseconds = (int) round(1_000_000 / $maxPerSecond);
        $key = self::CACHE_KEY_PREFIX . $channel;

        $lastCallMicrotime = (float) ($this->cache->load($key) ?: 0.0);
        $nowMicrotime = $this->nowMicrotime();

        $elapsedMicroseconds = $nowMicrotime - $lastCallMicrotime;
        if ($elapsedMicroseconds < $minIntervalMicroseconds) {
            ($this->sleep)((int) ($minIntervalMicroseconds - $elapsedMicroseconds));
        }

        $this->cache->save(
            (string) $this->nowMicrotime(),
            $key,
            [],
            self::CACHE_LIFETIME_SECONDS
        );
    }

    /**
     * Wraps the injected $now closure (whose call return type PHPStan can only see as mixed)
     * behind one declared-`float` boundary, rather than casting a mixed value at every call
     * site.
     */
    private function nowMicrotime(): float
    {
        return ((float) ($this->now)(true)) * 1_000_000;
    }
}
