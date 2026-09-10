<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Magento\Framework\Registry;
use Ordo\Automation\Model\MessageLogEvent;
use Ordo\Automation\Model\MessageLogEventFactory;
use Ordo\Automation\Model\MessageLogEventWriter;
use Ordo\Automation\Model\ResourceModel\MessageLogEvent as MessageLogEventResource;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class MessageLogEventWriterTest extends TestCase
{
    private MessageLogEventFactory $messageLogEventFactory;
    private MessageLogEventResource&\PHPUnit\Framework\MockObject\MockObject $messageLogEventResource;
    private LoggerInterface&\PHPUnit\Framework\MockObject\MockObject $logger;
    private MessageLogEventWriter $writer;

    protected function setUp(): void
    {
        $this->messageLogEventFactory = $this->createMock(MessageLogEventFactory::class);
        $this->messageLogEventResource = $this->createMock(MessageLogEventResource::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->writer = new MessageLogEventWriter(
            $this->messageLogEventFactory,
            $this->messageLogEventResource,
            $this->logger
        );
    }

    private function makeEvent(): MessageLogEvent
    {
        $resource = $this->createStub(AbstractDb::class);
        $resource->method('getIdFieldName')->willReturn('entity_id');

        return new MessageLogEvent(
            $this->createStub(Context::class),
            $this->createStub(Registry::class),
            $resource
        );
    }

    public function testRecordOpenedSavesAnOpenedRowWithNoUrl(): void
    {
        $event = $this->makeEvent();
        $this->messageLogEventFactory->expects(self::once())->method('create')->willReturn($event);
        $this->messageLogEventResource->expects(self::once())->method('save')->with($event);
        $this->logger->expects(self::never())->method('error');

        $this->writer->recordOpened(7);

        self::assertSame(7, $event->getMessageLogId());
        self::assertSame(MessageLogEvent::TYPE_OPENED, $event->getEventType());
        self::assertNull($event->getUrl());
    }

    public function testRecordClickedSavesAClickedRowWithUrl(): void
    {
        $event = $this->makeEvent();
        $this->messageLogEventFactory->expects(self::once())->method('create')->willReturn($event);
        $this->messageLogEventResource->expects(self::once())->method('save')->with($event);
        $this->logger->expects(self::never())->method('error');

        $this->writer->recordClicked(7, 'https://example.com/product');

        self::assertSame(MessageLogEvent::TYPE_CLICKED, $event->getEventType());
        self::assertSame('https://example.com/product', $event->getUrl());
    }

    public function testSaveFailureIsLoggedAndSwallowed(): void
    {
        $event = $this->makeEvent();
        $this->messageLogEventFactory->expects(self::once())->method('create')->willReturn($event);
        $this->messageLogEventResource->expects(self::once())->method('save')
            ->willThrowException(new \RuntimeException('DB is down'));
        $this->logger->expects(self::once())->method('error')->with(self::stringContains('DB is down'));

        $this->writer->recordOpened(7);
    }
}
