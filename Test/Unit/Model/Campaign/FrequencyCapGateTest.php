<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Campaign;

use Ordo\Automation\Model\Campaign\FrequencyCapGate;
use Ordo\Automation\Model\Campaign\FrequencyCapManager;
use Ordo\Automation\Model\Sms\MessageLogWriter;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class FrequencyCapGateTest extends TestCase
{
    private FrequencyCapManager&\PHPUnit\Framework\MockObject\MockObject $frequencyCapManager;
    private MessageLogWriter&\PHPUnit\Framework\MockObject\MockObject $messageLogWriter;
    private LoggerInterface&\PHPUnit\Framework\MockObject\MockObject $logger;
    private FrequencyCapGate $gate;

    protected function setUp(): void
    {
        $this->frequencyCapManager = $this->createMock(FrequencyCapManager::class);
        $this->messageLogWriter = $this->createMock(MessageLogWriter::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->gate = new FrequencyCapGate($this->frequencyCapManager, $this->messageLogWriter, $this->logger);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testAllowsReturnsTrueAndTouchesNothingElseWhenUnderTheCap(): void
    {
        $this->frequencyCapManager->method('hasCapacity')->with(42)->willReturn(true);

        $this->logger->expects(self::never())->method('info');
        $this->messageLogWriter->expects(self::never())->method('recordSuppressed');

        self::assertTrue($this->gate->allows(42, 'sms', '+15551234567', 'send_sms'));
    }

    public function testAllowsReturnsFalseLogsAndRecordsSuppressedWhenOverTheCap(): void
    {
        $this->frequencyCapManager->method('hasCapacity')->with(42)->willReturn(false);

        $this->logger->expects(self::once())->method('info')->with(self::stringContains('send_sms'));
        $this->messageLogWriter->expects(self::once())->method('recordSuppressed')
            ->with('sms', 42, '+15551234567', null, null);

        self::assertFalse($this->gate->allows(42, 'sms', '+15551234567', 'send_sms'));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testAllowsPassesCampaignIdAndVariantThroughToRecordSuppressed(): void
    {
        $this->frequencyCapManager->method('hasCapacity')->with(42)->willReturn(false);

        $this->messageLogWriter->expects(self::once())->method('recordSuppressed')
            ->with('sms', 42, '+15551234567', 5, 'b');

        self::assertFalse($this->gate->allows(42, 'sms', '+15551234567', 'send_sms', 5, 'b'));
    }
}
