<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Approval;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Ordo\Automation\Model\Approval\ApprovalRateLimiter;
use PHPUnit\Framework\TestCase;

class ApprovalRateLimiterTest extends TestCase
{
    public function testIsAllowedWhenNothingCachedYet(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn(false);
        $serializer = $this->createStub(SerializerInterface::class);
        $serializer->method('serialize')->willReturnCallback('serialize');

        $cache->expects(self::once())->method('save')->with(
            self::anything(),
            self::callback('is_string'),
            ['ORDO_APPROVAL_RATE_LIMIT'],
            900
        );

        $limiter = new ApprovalRateLimiter($cache, $serializer);

        self::assertTrue($limiter->isAllowed('tok', '1.2.3.4'));
    }

    public function testIsAllowedUnderTheCap(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn(serialize(5));
        $serializer = $this->createStub(SerializerInterface::class);
        $serializer->method('unserialize')->willReturnCallback('unserialize');
        $serializer->method('serialize')->willReturnCallback('serialize');

        $cache->expects(self::once())->method('save');

        $limiter = new ApprovalRateLimiter($cache, $serializer);

        self::assertTrue($limiter->isAllowed('tok', '1.2.3.4'));
    }

    public function testNotAllowedAtTheCap(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn(serialize(10));
        $serializer = $this->createStub(SerializerInterface::class);
        $serializer->method('unserialize')->willReturnCallback('unserialize');

        $cache->expects(self::never())->method('save');

        $limiter = new ApprovalRateLimiter($cache, $serializer);

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
        $serializer = $this->createStub(SerializerInterface::class);
        $serializer->method('serialize')->willReturnCallback('serialize');

        $limiter = new ApprovalRateLimiter($cache, $serializer);
        $limiter->isAllowed('tok-a', '1.2.3.4');
        $limiter->isAllowed('tok-b', '1.2.3.4');
        $limiter->isAllowed('tok-a', '5.6.7.8');

        self::assertCount(3, array_unique($keysSeen));
    }
}
