<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Sms;

use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Magento\Framework\Registry;
use Ordo\Automation\Model\MessageLog;
use Ordo\Automation\Model\MessageLogFactory;
use Ordo\Automation\Model\ResourceModel\MessageLog as MessageLogResource;
use Ordo\Automation\Model\Sms\MessageLogWriter;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class MessageLogWriterTest extends TestCase
{
    private MessageLogFactory $messageLogFactory;
    private MessageLogResource&\PHPUnit\Framework\MockObject\MockObject $messageLogResource;
    private LoggerInterface&\PHPUnit\Framework\MockObject\MockObject $logger;
    private MessageLogWriter $writer;

    protected function setUp(): void
    {
        $this->messageLogFactory = $this->createMock(MessageLogFactory::class);
        $this->messageLogResource = $this->createMock(MessageLogResource::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->writer = new MessageLogWriter($this->messageLogFactory, $this->messageLogResource, $this->logger);
    }

    private function makeLog(): MessageLog
    {
        $resource = $this->createStub(AbstractDb::class);
        $resource->method('getIdFieldName')->willReturn('entity_id');

        return new MessageLog($this->createStub(Context::class), $this->createStub(Registry::class), $resource);
    }

    public function testRecordSentSavesARowWithStatusSent(): void
    {
        $log = $this->makeLog();
        $this->messageLogFactory->expects(self::once())->method('create')->willReturn($log);
        $this->messageLogResource->expects(self::once())->method('save')->with($log);
        $this->logger->expects(self::never())->method('error');

        $this->writer->recordSent('sms', 42, '+15551234567', 'SM123');

        self::assertSame('sms', $log->getChannel());
        self::assertSame(42, $log->getCustomerId());
        self::assertSame('+15551234567', $log->getToAddress());
        self::assertSame('SM123', $log->getProviderMessageId());
        self::assertSame(MessageLog::STATUS_SENT, $log->getStatus());
    }

    public function testRecordOptedOutSavesARowWithStatusOptedOut(): void
    {
        $log = $this->makeLog();
        $this->messageLogFactory->expects(self::once())->method('create')->willReturn($log);
        $this->messageLogResource->expects(self::once())->method('save')->with($log);
        $this->logger->expects(self::never())->method('error');

        $this->writer->recordOptedOut('sms', 42, '+15551234567');

        self::assertSame(MessageLog::STATUS_OPTED_OUT, $log->getStatus());
        self::assertNull($log->getProviderMessageId());
    }

    public function testRecordFailedSavesARowWithStatusFailed(): void
    {
        $log = $this->makeLog();
        $this->messageLogFactory->expects(self::once())->method('create')->willReturn($log);
        $this->messageLogResource->expects(self::once())->method('save')->with($log);
        $this->logger->expects(self::never())->method('error');

        $this->writer->recordFailed('sms', 42, '+15551234567');

        self::assertSame(MessageLog::STATUS_FAILED, $log->getStatus());
    }

    public function testSaveFailureIsLoggedAndSwallowed(): void
    {
        $log = $this->makeLog();
        $this->messageLogFactory->expects(self::once())->method('create')->willReturn($log);
        $this->messageLogResource->expects(self::once())->method('save')->willThrowException(new \RuntimeException('DB is down'));
        $this->logger->expects(self::once())->method('error')->with(self::stringContains('DB is down'));

        $this->writer->recordSent('sms', 42, '+15551234567', 'SM123');
    }
}
