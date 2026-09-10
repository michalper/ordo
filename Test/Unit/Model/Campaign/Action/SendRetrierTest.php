<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Campaign\Action;

use Ordo\Automation\Model\Campaign\Action\SendRetrier;
use PHPUnit\Framework\TestCase;

class SendRetrierTest extends TestCase
{
    public function testAttemptReturnsTheSendResultWhenTheFirstCallSucceeds(): void
    {
        $retrier = new SendRetrier(1);
        $calls = 0;

        $result = $retrier->attempt(function () use (&$calls) {
            $calls++;
            return 'ok';
        });

        self::assertSame('ok', $result);
        self::assertSame(1, $calls);
    }

    public function testAttemptRetriesATransientFailureAndSucceedsOnASubsequentAttempt(): void
    {
        $retrier = new SendRetrier(1);
        $calls = 0;

        $result = $retrier->attempt(function () use (&$calls) {
            $calls++;
            if ($calls < 3) {
                throw new \RuntimeException('transient');
            }
            return 'ok';
        });

        self::assertSame('ok', $result);
        self::assertSame(3, $calls);
    }

    public function testAttemptThrowsTheLastExceptionOnceMaxAttemptsAreExhausted(): void
    {
        $retrier = new SendRetrier(1);
        $calls = 0;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('attempt 3');

        try {
            $retrier->attempt(function () use (&$calls) {
                $calls++;
                throw new \RuntimeException('attempt ' . $calls);
            });
        } finally {
            self::assertSame(3, $calls);
        }
    }

    /**
     * Regression test for exactly the reasoning in SendRetrier's own docblock: an exception that
     * means "this destination is permanently invalid" (opt-out, dead subscription, malformed
     * number) must fail fast on the first attempt, not burn through every retry on an outcome
     * that can never change.
     */
    public function testAttemptFailsFastWhenShouldRetryReturnsFalse(): void
    {
        $retrier = new SendRetrier(1);
        $calls = 0;

        $this->expectException(\DomainException::class);

        try {
            $retrier->attempt(
                function () use (&$calls) {
                    $calls++;
                    throw new \DomainException('permanently invalid');
                },
                fn (\Throwable $e): bool => !$e instanceof \DomainException
            );
        } finally {
            self::assertSame(1, $calls);
        }
    }

    public function testAttemptStillRetriesAnExceptionShouldRetryAllows(): void
    {
        $retrier = new SendRetrier(1);
        $calls = 0;

        $result = $retrier->attempt(
            function () use (&$calls) {
                $calls++;
                if ($calls < 2) {
                    throw new \RuntimeException('transient');
                }
                return 'ok';
            },
            fn (\Throwable $e): bool => !$e instanceof \DomainException
        );

        self::assertSame('ok', $result);
        self::assertSame(2, $calls);
    }
}
