<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\RateLimit;

use Ordo\Automation\Model\RateLimit\OutboundRateLimiter;
use Ordo\Automation\Model\RateLimit\ProviderRateLimitStore;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class OutboundRateLimiterTest extends TestCase
{
    #[AllowMockObjectsWithoutExpectations]
    public function testThrottleIsANoopWhenMaxPerSecondIsZeroOrLess(): void
    {
        $store = $this->createMock(ProviderRateLimitStore::class);
        $store->expects(self::never())->method('claim');

        $sleep = function (int $microseconds): void {
            self::fail('sleep should never be called when unconfigured');
        };

        $limiter = new OutboundRateLimiter($store, $sleep);

        $limiter->throttle('twilio', 0);
        $limiter->throttle('twilio', -5);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testThrottleDoesNotSleepWhenTheClaimSucceedsImmediately(): void
    {
        $store = $this->createMock(ProviderRateLimitStore::class);
        $store->expects(self::once())->method('claim')->with('twilio', 1_000_000)->willReturn(true);

        $slept = false;
        $sleep = function () use (&$slept): void {
            $slept = true;
        };

        $limiter = new OutboundRateLimiter($store, $sleep);
        $limiter->throttle('twilio', 1.0);

        self::assertFalse($slept);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testThrottlePollsUntilTheClaimSucceeds(): void
    {
        $store = $this->createMock(ProviderRateLimitStore::class);
        $store->expects(self::exactly(3))->method('claim')->with('twilio', 1_000_000)
            ->willReturnOnConsecutiveCalls(false, false, true);

        $sleepCalls = 0;
        $sleep = function (int $microseconds) use (&$sleepCalls): void {
            $sleepCalls++;
            self::assertSame(10_000, $microseconds);
        };

        $limiter = new OutboundRateLimiter($store, $sleep);
        $limiter->throttle('twilio', 1.0);

        self::assertSame(2, $sleepCalls);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testThrottleComputesTheIntervalFromMaxPerSecond(): void
    {
        $store = $this->createMock(ProviderRateLimitStore::class);
        // 5/sec -> 200_000 microseconds apart.
        $store->expects(self::once())->method('claim')->with('push', 200_000)->willReturn(true);

        $limiter = new OutboundRateLimiter($store, fn () => null);
        $limiter->throttle('push', 5.0);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testThrottleClaimsPerChannelIndependently(): void
    {
        $claimedChannels = [];
        $store = $this->createStub(ProviderRateLimitStore::class);
        $store->method('claim')->willReturnCallback(
            function (string $channel) use (&$claimedChannels): bool {
                $claimedChannels[] = $channel;
                return true;
            }
        );

        $limiter = new OutboundRateLimiter($store, fn () => null);
        $limiter->throttle('twilio', 1.0);
        $limiter->throttle('whatsapp', 1.0);

        self::assertSame(['twilio', 'whatsapp'], $claimedChannels);
    }
}
