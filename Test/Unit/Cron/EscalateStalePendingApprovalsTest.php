<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Cron;

use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Mail\TransportInterface;
use Magento\Framework\Translate\Inline\StateInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\Collection as OrderCollection;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Store\Model\Store;
use Ordo\Automation\Cron\EscalateStalePendingApprovals;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Cron\CronRunLogger;
use Ordo\Automation\Model\OrderApproval;
use Ordo\Automation\Model\ResourceModel\OrderApproval as OrderApprovalResource;
use Ordo\Automation\Model\ResourceModel\OrderApproval\Collection as ApprovalCollection;
use Ordo\Automation\Model\ResourceModel\OrderApproval\CollectionFactory as ApprovalCollectionFactory;
use Ordo\Automation\Model\TriggerOutcomeLogger;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class EscalateStalePendingApprovalsTest extends TestCase
{
    private Config $config;
    private ApprovalCollectionFactory $approvalCollectionFactory;
    private OrderApprovalResource $orderApprovalResource;
    private OrderCollectionFactory $orderCollectionFactory;
    private TransportBuilder $transportBuilder;
    private StateInterface $inlineTranslation;
    private TriggerOutcomeLogger $triggerOutcomeLogger;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->config = $this->createStub(Config::class);
        $this->config->method('isOrderApprovalEnabled')->willReturn(true);
        $this->config->method('getOrderApprovalEscalationDays')->willReturn(2);
        $this->approvalCollectionFactory = $this->createMock(ApprovalCollectionFactory::class);
        $this->orderApprovalResource = $this->createMock(OrderApprovalResource::class);
        $this->orderCollectionFactory = $this->createMock(OrderCollectionFactory::class);
        $this->transportBuilder = $this->createStub(TransportBuilder::class);
        $this->inlineTranslation = $this->createStub(StateInterface::class);
        $this->triggerOutcomeLogger = $this->createStub(TriggerOutcomeLogger::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function makeCron(): EscalateStalePendingApprovals
    {
        return new EscalateStalePendingApprovals(
            $this->config,
            $this->approvalCollectionFactory,
            $this->orderApprovalResource,
            $this->orderCollectionFactory,
            $this->transportBuilder,
            $this->inlineTranslation,
            $this->triggerOutcomeLogger,
            new CronRunLogger($this->logger)
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSkipsWhenApprovalDisabled(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isOrderApprovalEnabled')->willReturn(false);
        $this->config = $config;

        $this->approvalCollectionFactory->expects(self::never())->method('create');

        $this->makeCron()->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSkipsApprovalAtMaxEscalations(): void
    {
        $approval = $this->createMock(OrderApproval::class);
        $approval->method('getRemindersSent')->willReturn(3);

        $collection = $this->createStub(ApprovalCollection::class);
        $collection->method('addStalePendingFilter');
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$approval]));
        $this->approvalCollectionFactory->method('create')->willReturn($collection);

        $this->orderCollectionFactory->expects(self::never())->method('create');
        $this->logger->expects(self::once())->method('info')->with(self::stringContains('0 order approval escalations'));

        $this->makeCron()->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSendsEscalationAndIncrementsCounter(): void
    {
        $approval = $this->createMock(OrderApproval::class);
        $approval->method('getRemindersSent')->willReturn(0);
        $approval->method('getOrderId')->willReturn(7);
        $approval->method('getToken')->willReturn('tok');
        $approval->method('getAdminEmail')->willReturn('admin@example.com');
        $approval->expects(self::once())->method('setData')->with('reminders_sent', 1);

        $collection = $this->createStub(ApprovalCollection::class);
        $collection->method('addStalePendingFilter');
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$approval]));
        $this->approvalCollectionFactory->method('create')->willReturn($collection);

        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn(1);
        $store->method('getBaseUrl')->willReturn('https://example.com/');

        $order = $this->createStub(Order::class);
        $order->method('getId')->willReturn(7);
        $order->method('getEntityId')->willReturn(7);
        $order->method('getIncrementId')->willReturn('000000007');
        $order->method('getGrandTotal')->willReturn(150.0);
        $order->method('getCustomerId')->willReturn(42);
        $order->method('getStore')->willReturn($store);

        $orderCollection = $this->createStub(OrderCollection::class);
        $orderCollection->method('addFieldToFilter')->willReturnSelf();
        $orderCollection->method('getIterator')->willReturn(new \ArrayIterator([$order]));
        $this->orderCollectionFactory->method('create')->willReturn($orderCollection);

        $this->transportBuilder->method('setTemplateIdentifier')->willReturnSelf();
        $this->transportBuilder->method('setTemplateOptions')->willReturnSelf();
        $this->transportBuilder->method('setTemplateVars')->willReturnSelf();
        $this->transportBuilder->method('setFromByScope')->willReturnSelf();
        $this->transportBuilder->method('addTo')->willReturnSelf();

        $transport = $this->createMock(TransportInterface::class);
        $transport->expects(self::once())->method('sendMessage');
        $this->transportBuilder->method('getTransport')->willReturn($transport);

        $this->orderApprovalResource->expects(self::once())->method('save')->with($approval);
        $this->logger->expects(self::once())->method('info')->with(self::stringContains('1 order approval escalations'));

        $triggerOutcomeLogger = $this->createMock(TriggerOutcomeLogger::class);
        $triggerOutcomeLogger->expects(self::once())->method('logSent')
            ->with(TriggerOutcomeLogger::TRIGGER_ORDER_APPROVAL, 42);
        $this->triggerOutcomeLogger = $triggerOutcomeLogger;

        $this->makeCron()->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSkipsWhenOrderNotFound(): void
    {
        $approval = $this->createMock(OrderApproval::class);
        $approval->method('getRemindersSent')->willReturn(0);
        $approval->method('getOrderId')->willReturn(999);

        $collection = $this->createStub(ApprovalCollection::class);
        $collection->method('addStalePendingFilter');
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$approval]));
        $this->approvalCollectionFactory->method('create')->willReturn($collection);

        $orderCollection = $this->createStub(OrderCollection::class);
        $orderCollection->method('addFieldToFilter')->willReturnSelf();
        $orderCollection->method('getIterator')->willReturn(new \ArrayIterator([]));
        $this->orderCollectionFactory->method('create')->willReturn($orderCollection);

        $this->orderApprovalResource->expects(self::never())->method('save');

        $this->makeCron()->execute();
    }

    /**
     * The counter is claimed (incremented + saved) BEFORE the send attempt, not after - a crash
     * between a successful send and this save must never cause a duplicate escalation next tick.
     * When the send then genuinely fails, the counter must be rolled back to its pre-claim value
     * and saved again, so this approval is retried next run instead of quietly losing an
     * escalation attempt it never actually sent.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLogsErrorWhenEmailSendingThrows(): void
    {
        $approval = $this->createMock(OrderApproval::class);
        $approval->method('getRemindersSent')->willReturn(0);
        $approval->method('getOrderId')->willReturn(7);
        $setDataCalls = [];
        $approval->method('setData')->willReturnCallback(function ($key, $value) use (&$setDataCalls) {
            $setDataCalls[] = [$key, $value];
        });

        $collection = $this->createStub(ApprovalCollection::class);
        $collection->method('addStalePendingFilter');
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$approval]));
        $this->approvalCollectionFactory->method('create')->willReturn($collection);

        $order = $this->createStub(Order::class);
        $order->method('getId')->willReturn(7);
        $order->method('getEntityId')->willReturn(7);
        $order->method('getStore')->willThrowException(new \RuntimeException('no store'));

        $orderCollection = $this->createStub(OrderCollection::class);
        $orderCollection->method('addFieldToFilter')->willReturnSelf();
        $orderCollection->method('getIterator')->willReturn(new \ArrayIterator([$order]));
        $this->orderCollectionFactory->method('create')->willReturn($orderCollection);

        $this->orderApprovalResource->expects(self::exactly(2))->method('save')->with($approval);
        $this->logger->expects(self::once())->method('error');

        $this->makeCron()->execute();

        self::assertSame([['reminders_sent', 1], ['reminders_sent', 0]], $setDataCalls);
    }
}
