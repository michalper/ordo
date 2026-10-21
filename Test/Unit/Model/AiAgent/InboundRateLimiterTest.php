<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\AiAgent;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Magento\Framework\Serialize\SerializerInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\RateLimit\CacheWindowCounter;
use Ordo\Automation\Model\AiAgent\InboundRateLimiter;
use PHPUnit\Framework\TestCase;

class InboundRateLimiterTest extends TestCase
{
    private function makeLimiter(CacheInterface $cache, SerializerInterface $serializer, int $maxPerMinute): InboundRateLimiter
    {
        $config = $this->createStub(Config::class);
        $config->method('getAiAgentRateLimitPerMinute')->willReturn($maxPerMinute);

        return new InboundRateLimiter(new CacheWindowCounter($cache, $serializer), $config);
    }

    public function testIsAllowedWhenNothingCachedYet(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn(false);

        $cache->expects(self::once())->method('save')->with(
            self::anything(),
            self::callback('is_string'),
            ['ORDO_AI_AGENT_RATE_LIMIT'],
            60
        );

        self::assertTrue($this->makeLimiter($cache, new JsonSerializer(), 60)->isAllowed('keyhash'));
    }

    public function testIsAllowedUnderTheCap(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn(json_encode(5));

        $cache->expects(self::once())->method('save');

        self::assertTrue($this->makeLimiter($cache, new JsonSerializer(), 60)->isAllowed('keyhash'));
    }

    public function testNotAllowedAtTheCap(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn(json_encode(60));

        $cache->expects(self::never())->method('save');

        self::assertFalse($this->makeLimiter($cache, new JsonSerializer(), 60)->isAllowed('keyhash'));
    }

    public function testDifferentIdentifiersHaveIndependentBudgets(): void
    {
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('load')->willReturnMap([
            ['ordo_ai_agent_rl_' . hash('sha256', 'key-a'), json_encode(60)],
            ['ordo_ai_agent_rl_' . hash('sha256', 'key-b'), false],
        ]);

        $limiter = $this->makeLimiter($cache, new JsonSerializer(), 60);

        self::assertFalse($limiter->isAllowed('key-a'));
        self::assertTrue($limiter->isAllowed('key-b'));
    }

    /**
     * A zero (or negative) per-minute budget is the module's "not configured" convention
     * (Config::getAiAgentRateLimitPerMinute()'s own doc) - throttling is opt-in, so this is a
     * no-op that never even touches the cache.
     */
    public function testIsAllowedAlwaysTrueWhenRateLimitIsZero(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects(self::never())->method('load');

        self::assertTrue($this->makeLimiter($cache, new JsonSerializer(), 0)->isAllowed('keyhash'));
    }
}
