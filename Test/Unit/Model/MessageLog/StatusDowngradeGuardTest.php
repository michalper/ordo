<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\MessageLog;

use Ordo\Automation\Model\MessageLog;
use Ordo\Automation\Model\MessageLog\StatusDowngradeGuard;
use PHPUnit\Framework\TestCase;

class StatusDowngradeGuardTest extends TestCase
{
    private StatusDowngradeGuard $guard;

    protected function setUp(): void
    {
        $this->guard = new StatusDowngradeGuard();
    }

    public function testMoreFinalIncomingStatusIsNotADowngrade(): void
    {
        self::assertFalse($this->guard->isDowngrade(MessageLog::STATUS_SENT, MessageLog::STATUS_DELIVERED));
        self::assertFalse($this->guard->isDowngrade(MessageLog::STATUS_SENT, MessageLog::STATUS_FAILED));
    }

    public function testLessFinalIncomingStatusIsADowngrade(): void
    {
        self::assertTrue($this->guard->isDowngrade(MessageLog::STATUS_FAILED, MessageLog::STATUS_DELIVERED));
        self::assertTrue($this->guard->isDowngrade(MessageLog::STATUS_DELIVERED, MessageLog::STATUS_SENT));
    }

    public function testEqualRankIsNotADowngrade(): void
    {
        self::assertFalse($this->guard->isDowngrade(MessageLog::STATUS_FAILED, MessageLog::STATUS_UNDELIVERED));
    }

    public function testUnrecognizedCurrentStatusIsAlwaysOverwritable(): void
    {
        // A brand-new row starts with the empty string, which isn't in the rank table - it must
        // always be overwritable by any real, known status.
        self::assertFalse($this->guard->isDowngrade('', MessageLog::STATUS_SENT));
    }

    public function testUnrecognizedIncomingStatusNeverOverwritesAKnownOne(): void
    {
        self::assertTrue($this->guard->isDowngrade(MessageLog::STATUS_SENT, 'totally_unknown_status'));
    }
}
