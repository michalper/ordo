<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Ordo\Automation\Model\MessageLog;

class MessageLogTest extends AbstractModelTestCase
{
    private function makeModel(): MessageLog
    {
        return new MessageLog($this->makeModelContext(), $this->makeRegistry(), $this->makeModelResource());
    }

    public function testGettersAndSettersRoundTrip(): void
    {
        $log = $this->makeModel();
        $log->setChannel('sms')
            ->setCustomerId(42)
            ->setToAddress('+15551234567')
            ->setProviderMessageId('SM123')
            ->setStatus(MessageLog::STATUS_DELIVERED)
            ->setErrorCode('30003');

        self::assertSame('sms', $log->getChannel());
        self::assertSame(42, $log->getCustomerId());
        self::assertSame('+15551234567', $log->getToAddress());
        self::assertSame('SM123', $log->getProviderMessageId());
        self::assertSame(MessageLog::STATUS_DELIVERED, $log->getStatus());
        self::assertSame('30003', $log->getErrorCode());
    }

    public function testCustomerIdAndProviderMessageIdAndErrorCodeAreNullableByDefault(): void
    {
        $log = $this->makeModel();

        self::assertNull($log->getCustomerId());
        self::assertNull($log->getProviderMessageId());
        self::assertNull($log->getErrorCode());
    }

    public function testSetCustomerIdAndErrorCodeAcceptExplicitNullToClear(): void
    {
        $log = $this->makeModel();
        $log->setCustomerId(42)->setErrorCode('30003');

        $log->setCustomerId(null)->setErrorCode(null);

        self::assertNull($log->getCustomerId());
        self::assertNull($log->getErrorCode());
    }
}
