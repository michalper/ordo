<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\RateLimit;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\SerializerInterface;

/**
 * The cache-backed fixed-window counter behind both inbound abuse guards
 * (Model\AiAgent\InboundRateLimiter, Model\Approval\ApprovalRateLimiter) — each keeps its own
 * key derivation, budget and window, and shares only this load-compare-count core.
 *
 * Deliberately non-atomic (a plain load()-then-save(), not a DB-backed conditional UPDATE like
 * ProviderRateLimitStore): a handful of truly simultaneous requests could each read the same
 * pre-increment count and all be let through. Acceptable for a coarse inbound deterrent —
 * never for pacing this module's own outbound calls, which is ProviderRateLimitStore's job.
 */
class CacheWindowCounter
{
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly SerializerInterface $serializer
    ) {
    }

    /**
     * @return bool true if this hit fits the window's budget (and is now counted), false once
     *   $maxPerWindow has been reached.
     */
    public function hit(string $cacheKey, int $maxPerWindow, int $windowSeconds, string $cacheTag): bool
    {
        $cached = $this->cache->load($cacheKey);
        $count = $cached !== false ? (int) $this->serializer->unserialize($cached) : 0;

        if ($count >= $maxPerWindow) {
            return false;
        }

        $this->cache->save(
            (string) $this->serializer->serialize($count + 1),
            $cacheKey,
            [$cacheTag],
            $windowSeconds
        );

        return true;
    }
}
