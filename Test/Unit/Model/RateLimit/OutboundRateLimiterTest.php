<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\RateLimit;

use Magento\Framework\App\CacheInterface;
use Ordo\Automation\Model\RateLimit\OutboundRateLimiter;
use PHPUnit\Framework\TestCase;

class OutboundRateLimiterTest extends TestCase
{
    public function testThrottleIsANoopWhenMaxPerSecondIsZeroOrLess(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects(self::never())->method('load');
        $cache->expects(self::never())->method('save');

        $sleep = function (int $microseconds): void {
            self::fail('sleep should never be called when unconfigured');
        };

        $limiter = new OutboundRateLimiter($cache, $sleep);

        $limiter->throttle('twilio', 0);
        $limiter->throttle('twilio', -5);
    }

    public function testThrottleDoesNotSleepWhenEnoughTimeAlreadyElapsedSinceTheLastCall(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        // Last call was a full second ago (in microseconds) - well past the 1/1 = 1s interval.
        $cache->method('load')->willReturn((string) (1_000_000.0));
        $cache->expects(self::once())->method('save');

        $slept = false;
        $sleep = function () use (&$slept): void {
            $slept = true;
        };
        $now = fn (): float => 2.0; // 2_000_000 microseconds - 1s after the cached last call.

        $limiter = new OutboundRateLimiter($cache, $sleep, $now);
        $limiter->throttle('twilio', 1.0);

        self::assertFalse($slept);
    }

    public function testThrottleSleepsForTheRemainingIntervalWhenCalledTooSoon(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn((string) (1_000_000.0));
        $cache->expects(self::once())->method('save');

        $sleptMicroseconds = null;
        $sleep = function (int $microseconds) use (&$sleptMicroseconds): void {
            $sleptMicroseconds = $microseconds;
        };
        // Only 0.2s after the last call, but the configured rate needs a full 1s gap (1/sec).
        $now = fn (): float => 1.2;

        $limiter = new OutboundRateLimiter($cache, $sleep, $now);
        $limiter->throttle('twilio', 1.0);

        // 1s interval - 0.2s elapsed = 0.8s = 800_000 microseconds still owed.
        self::assertSame(800_000, $sleptMicroseconds);
    }

    public function testThrottleKeysTheCacheEntryPerChannel(): void
    {
        $savedKeys = [];
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('load')->willReturn(false);
        $cache->method('save')->willReturnCallback(
            function (string $data, string $key) use (&$savedKeys): bool {
                $savedKeys[] = $key;
                return true;
            }
        );

        $limiter = new OutboundRateLimiter($cache, fn () => null, fn (): float => 1.0);
        $limiter->throttle('twilio', 1.0);
        $limiter->throttle('whatsapp', 1.0);

        self::assertNotSame($savedKeys[0], $savedKeys[1]);
    }
}
