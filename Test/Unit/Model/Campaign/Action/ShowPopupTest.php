<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Campaign\Action;

use Ordo\Automation\Model\Campaign\Action\ShowPopup;
use Ordo\Automation\Model\PendingPopup;
use Ordo\Automation\Model\PendingPopupFactory;
use Ordo\Automation\Model\ResourceModel\PendingPopup as PendingPopupResource;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

class ShowPopupTest extends TestCase
{
    private PendingPopupFactory&\PHPUnit\Framework\MockObject\MockObject $pendingPopupFactory;
    private PendingPopupResource&\PHPUnit\Framework\MockObject\MockObject $pendingPopupResource;
    private LoggerInterface&\PHPUnit\Framework\MockObject\MockObject $logger;
    private ShowPopup $action;

    protected function setUp(): void
    {
        $this->pendingPopupFactory = $this->createMock(PendingPopupFactory::class);
        $this->pendingPopupResource = $this->createMock(PendingPopupResource::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->action = new ShowPopup($this->pendingPopupFactory, $this->pendingPopupResource, $this->logger);
    }

    public function testExecuteSavesPopupForCustomer(): void
    {
        $popup = $this->createMock(PendingPopup::class);
        $popup->expects(self::once())->method('setCustomerId')->with(42);
        $popup->expects(self::once())->method('setVisitorId')->with(null);
        $popup->expects(self::once())->method('setHeadline')->with('Hello!');
        $popup->expects(self::once())->method('setBody')->with('Come back soon');
        $popup->expects(self::once())->method('setCtaLabel')->with('Shop now');
        $popup->expects(self::once())->method('setCtaUrl')->with('https://example.test/sale');
        $this->pendingPopupFactory->method('create')->willReturn($popup);
        $this->pendingPopupResource->expects(self::once())->method('save')->with($popup);

        $context = ['customer_id' => 42];
        $this->action->execute($context, [
            'headline' => 'Hello!',
            'body' => 'Come back soon',
            'cta_label' => 'Shop now',
            'cta_url' => 'https://example.test/sale',
        ]);
    }

    public function testExecuteSavesPopupForAnonymousVisitor(): void
    {
        $popup = $this->createMock(PendingPopup::class);
        $popup->expects(self::once())->method('setCustomerId')->with(null);
        $popup->expects(self::once())->method('setVisitorId')->with('v1');
        $this->pendingPopupFactory->method('create')->willReturn($popup);
        $this->pendingPopupResource->expects(self::once())->method('save')->with($popup);

        $context = ['visitor_id' => 'v1'];
        $this->action->execute($context, ['headline' => 'Hello!']);
    }

    public function testExecuteLeavesOptionalParamsNullWhenBlank(): void
    {
        $popup = $this->createMock(PendingPopup::class);
        $popup->expects(self::once())->method('setBody')->with(null);
        $popup->expects(self::once())->method('setCtaLabel')->with(null);
        $popup->expects(self::once())->method('setCtaUrl')->with(null);
        $this->pendingPopupFactory->method('create')->willReturn($popup);

        $context = ['customer_id' => 42];
        $this->action->execute($context, ['headline' => 'Hello!']);
    }

    public function testExecuteLogsAndSkipsWhenNoIdentifierInContext(): void
    {
        $this->pendingPopupFactory->expects(self::never())->method('create');
        $this->logger->expects(self::once())->method('error');

        $context = [];
        $this->action->execute($context, ['headline' => 'Hello!']);
    }

    public function testExecuteLogsAndSkipsWhenHeadlineMissing(): void
    {
        $this->pendingPopupFactory->expects(self::never())->method('create');
        $this->logger->expects(self::once())->method('error');

        $context = ['customer_id' => 42];
        $this->action->execute($context, []);
    }
}
