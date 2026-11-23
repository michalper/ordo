<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Track;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Ordo\Automation\Model\RateLimit\CacheWindowCounter;
use Ordo\Automation\Model\Track\EmailCaptureRateLimiter;
use PHPUnit\Framework\TestCase;

class EmailCaptureRateLimiterTest extends TestCase
{
    public function testIsAllowedWhenNothingCachedYet(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn(false);

        $cache->expects(self::once())->method('save')->with(
            self::anything(),
            self::callback('is_string'),
            ['ORDO_EMAIL_CAPTURE_RATE_LIMIT'],
            900
        );

        $limiter = new EmailCaptureRateLimiter(new CacheWindowCounter($cache, new JsonSerializer()));

        self::assertTrue($limiter->isAllowed('1.2.3.4'));
    }

    public function testIsAllowedUnderTheCap(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn(json_encode(9));

        $cache->expects(self::once())->method('save');

        $limiter = new EmailCaptureRateLimiter(new CacheWindowCounter($cache, new JsonSerializer()));

        self::assertTrue($limiter->isAllowed('1.2.3.4'));
    }

    public function testNotAllowedAtTheCap(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn(json_encode(10));

        $cache->expects(self::never())->method('save');

        $limiter = new EmailCaptureRateLimiter(new CacheWindowCounter($cache, new JsonSerializer()));

        self::assertFalse($limiter->isAllowed('1.2.3.4'));
    }

    public function testDifferentIpsUseDifferentKeys(): void
    {
        $cache = $this->createStub(CacheInterface::class);
        $keysSeen = [];
        $cache->method('load')->willReturnCallback(function ($key) use (&$keysSeen) {
            $keysSeen[] = $key;
            return false;
        });
        $limiter = new EmailCaptureRateLimiter(new CacheWindowCounter($cache, new JsonSerializer()));
        $limiter->isAllowed('1.2.3.4');
        $limiter->isAllowed('5.6.7.8');

        self::assertCount(2, array_unique($keysSeen));
    }
}
