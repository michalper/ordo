<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\AiAgent;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Ordo\Automation\Helper\Config;

/**
 * Fixed-window request counter for inbound AI-agent commerce endpoints, keyed by the
 * authenticated API key's hash (Model\AiAgent\ApiKeyAuthenticator) - one caller hammering its own
 * key never affects another key's budget. Deliberately the same cache-backed, non-atomic
 * "acceptable trade-off, not worth a DB table" shape as Model\Approval\ApprovalRateLimiter, not
 * Model\RateLimit\ProviderRateLimitStore's DB-claim primitive - that one paces this module's OWN
 * outbound calls to a slow external rate, worth getting exactly right; this one is a coarse abuse
 * guard on inbound traffic this module doesn't control the shape of, where a request instead
 * either fits in the current window or gets a 429 - never blocked/delayed.
 */
class InboundRateLimiter
{
    private const int WINDOW_SECONDS = 60;

    private const string CACHE_TAG = 'ORDO_AI_AGENT_RATE_LIMIT';

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly SerializerInterface $serializer,
        private readonly Config $config
    ) {
    }

    /**
     * @return bool true if this request is allowed (and now counted) within the current
     *   one-minute window, false if $identifier has already hit its per-minute budget.
     */
    public function isAllowed(string $identifier): bool
    {
        $maxPerMinute = $this->config->getAiAgentRateLimitPerMinute();
        if ($maxPerMinute <= 0) {
            return true;
        }

        $key = $this->cacheKey($identifier);
        $cached = $this->cache->load($key);
        $count = $cached !== false ? (int) $this->serializer->unserialize($cached) : 0;

        if ($count >= $maxPerMinute) {
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

    private function cacheKey(string $identifier): string
    {
        return 'ordo_ai_agent_rl_' . hash('sha256', $identifier);
    }
}
