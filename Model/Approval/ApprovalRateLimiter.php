<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Approval;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\SerializerInterface;

/**
 * Bespoke cache-backed attempt counter for Controller\Approval\{Approve,Reject} - both are
 * public, unauthenticated, token-only endpoints with no throttling of their own today, and
 * Magento has no built-in webapi rate-limit mechanism to turn on instead (those endpoints aren't
 * webapi.xml service contracts). Keyed by token+IP so a single leaked/guessed token can't be
 * hammered, without penalizing every other legitimate token an attacker's IP happens to also
 * guess wrong on.
 *
 * Not perfectly atomic (a plain cache load()-then-save(), not a DB-backed conditional UPDATE like
 * this module's other claim-before-use patterns) - a handful of truly simultaneous requests could
 * each read the same pre-increment count and all be let through. That's an acceptable trade-off
 * for a brute-force deterrent (the token itself is still the real security boundary, a 32+ byte
 * random value - this only slows down blind guessing), not worth a DB table just for this.
 */
class ApprovalRateLimiter
{
    private const int MAX_ATTEMPTS = 10;

    private const int WINDOW_SECONDS = 900;

    private const string CACHE_TAG = 'ORDO_APPROVAL_RATE_LIMIT';

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly SerializerInterface $serializer
    ) {
    }

    /**
     * @return bool true if this attempt is allowed (and now counted), false if the caller has
     *   already hit MAX_ATTEMPTS within the current WINDOW_SECONDS window.
     */
    public function isAllowed(string $token, string $ip): bool
    {
        $key = $this->cacheKey($token, $ip);
        $cached = $this->cache->load($key);
        $count = $cached !== false ? (int) $this->serializer->unserialize($cached) : 0;

        if ($count >= self::MAX_ATTEMPTS) {
            return false;
        }

        $this->cache->save(
            (string) $this->serializer->serialize($count + 1),
            $key,
            [self::CACHE_TAG],
            self::WINDOW_SECONDS
        );

        return true;
    }

    private function cacheKey(string $token, string $ip): string
    {
        return 'ordo_approval_rl_' . hash('sha256', $token . '|' . $ip);
    }
}
