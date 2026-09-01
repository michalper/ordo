<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Campaign\Action;

use Magento\Framework\Stdlib\DateTime\DateTime;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Campaign\Action\ShowPopup;
use Ordo\Automation\Model\PendingPopup;
use Ordo\Automation\Model\PendingPopupFactory;
use Ordo\Automation\Model\ResourceModel\PendingPopup as PendingPopupResource;
use Ordo\Automation\Model\ResourceModel\PendingPopup\Collection as PendingPopupCollection;
use Ordo\Automation\Model\ResourceModel\PendingPopup\CollectionFactory as PendingPopupCollectionFactory;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class ShowPopupTest extends TestCase
{
    private PendingPopupFactory&\PHPUnit\Framework\MockObject\MockObject $pendingPopupFactory;
    private PendingPopupResource&\PHPUnit\Framework\MockObject\MockObject $pendingPopupResource;
    private PendingPopupCollectionFactory&\PHPUnit\Framework\MockObject\MockObject $pendingPopupCollectionFactory;
    private PendingPopupCollection&\PHPUnit\Framework\MockObject\MockObject $pendingPopupCollection;
    private Config&\PHPUnit\Framework\MockObject\MockObject $config;
    private DateTime&\PHPUnit\Framework\MockObject\MockObject $dateTime;
    private LoggerInterface&\PHPUnit\Framework\MockObject\MockObject $logger;
    private ShowPopup $action;

    protected function setUp(): void
    {
        $this->pendingPopupFactory = $this->createMock(PendingPopupFactory::class);
        $this->pendingPopupResource = $this->createMock(PendingPopupResource::class);
        $this->pendingPopupCollectionFactory = $this->createMock(PendingPopupCollectionFactory::class);
        $this->pendingPopupCollection = $this->createMock(PendingPopupCollection::class);
        $this->pendingPopupCollectionFactory->method('create')->willReturn($this->pendingPopupCollection);
        $this->config = $this->createMock(Config::class);
        $this->config->method('getPopupFrequencyCapHours')->willReturn(24);
        $this->pendingPopupCollection->method('targetHasRecentlyReceivedPopup')->willReturn(false);
        $this->dateTime = $this->createMock(DateTime::class);
        $this->dateTime->method('gmtTimestamp')->willReturn(1700000000);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->action = new ShowPopup(
            $this->pendingPopupFactory,
            $this->pendingPopupResource,
            $this->pendingPopupCollectionFactory,
            $this->config,
            $this->dateTime,
            $this->logger
        );
    }

    #[AllowMockObjectsWithoutExpectations]
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

    #[AllowMockObjectsWithoutExpectations]
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

    #[AllowMockObjectsWithoutExpectations]
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

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLogsAndSkipsWhenNoIdentifierInContext(): void
    {
        $this->pendingPopupFactory->expects(self::never())->method('create');
        $this->logger->expects(self::once())->method('error');

        $context = [];
        $this->action->execute($context, ['headline' => 'Hello!']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLogsAndSkipsWhenHeadlineMissing(): void
    {
        $this->pendingPopupFactory->expects(self::never())->method('create');
        $this->logger->expects(self::once())->method('error');

        $context = ['customer_id' => 42];
        $this->action->execute($context, []);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSkipsSilentlyWhenTargetRecentlyReceivedAPopup(): void
    {
        $collection = $this->createStub(PendingPopupCollection::class);
        $collection->method('targetHasRecentlyReceivedPopup')->willReturn(true);
        $collectionFactory = $this->createStub(PendingPopupCollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        $action = new ShowPopup(
            $this->pendingPopupFactory,
            $this->pendingPopupResource,
            $collectionFactory,
            $this->config,
            $this->dateTime,
            $this->logger
        );

        $this->pendingPopupFactory->expects(self::never())->method('create');
        $this->pendingPopupResource->expects(self::never())->method('save');
        $this->logger->expects(self::never())->method('error');

        $context = ['customer_id' => 42];
        $action->execute($context, ['headline' => 'Hello!']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteIgnoresFrequencyCapWhenDisabled(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->config->method('getPopupFrequencyCapHours')->willReturn(0);
        $this->action = new ShowPopup(
            $this->pendingPopupFactory,
            $this->pendingPopupResource,
            $this->pendingPopupCollectionFactory,
            $this->config,
            $this->dateTime,
            $this->logger
        );

        $popup = $this->createStub(PendingPopup::class);
        $this->pendingPopupFactory->method('create')->willReturn($popup);
        $this->pendingPopupCollectionFactory->expects(self::never())->method('create');
        $this->pendingPopupResource->expects(self::once())->method('save')->with($popup);

        $context = ['customer_id' => 42];
        $this->action->execute($context, ['headline' => 'Hello!']);
    }
}
