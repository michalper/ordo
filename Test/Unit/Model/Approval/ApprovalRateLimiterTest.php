<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Approval;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Ordo\Automation\Model\Approval\ApprovalRateLimiter;
use Ordo\Automation\Model\RateLimit\CacheWindowCounter;
use PHPUnit\Framework\TestCase;

class ApprovalRateLimiterTest extends TestCase
{
    public function testIsAllowedWhenNothingCachedYet(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn(false);

        $cache->expects(self::once())->method('save')->with(
            self::anything(),
            self::callback('is_string'),
            ['ORDO_APPROVAL_RATE_LIMIT'],
            900
        );

        $limiter = new ApprovalRateLimiter(new CacheWindowCounter($cache, new JsonSerializer()));

        self::assertTrue($limiter->isAllowed('tok', '1.2.3.4'));
    }

    public function testIsAllowedUnderTheCap(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn(json_encode(5));

        $cache->expects(self::once())->method('save');

        $limiter = new ApprovalRateLimiter(new CacheWindowCounter($cache, new JsonSerializer()));

        self::assertTrue($limiter->isAllowed('tok', '1.2.3.4'));
    }

    public function testNotAllowedAtTheCap(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn(json_encode(10));

        $cache->expects(self::never())->method('save');

        $limiter = new ApprovalRateLimiter(new CacheWindowCounter($cache, new JsonSerializer()));

        self::assertFalse($limiter->isAllowed('tok', '1.2.3.4'));
    }

    public function testDifferentTokensOrIpsUseDifferentKeys(): void
    {
        $cache = $this->createStub(CacheInterface::class);
        $keysSeen = [];
        $cache->method('load')->willReturnCallback(function ($key) use (&$keysSeen) {
            $keysSeen[] = $key;
            return false;
        });
        $limiter = new ApprovalRateLimiter(new CacheWindowCounter($cache, new JsonSerializer()));
        $limiter->isAllowed('tok-a', '1.2.3.4');
        $limiter->isAllowed('tok-b', '1.2.3.4');
        $limiter->isAllowed('tok-a', '5.6.7.8');

        self::assertCount(3, array_unique($keysSeen));
    }
}
