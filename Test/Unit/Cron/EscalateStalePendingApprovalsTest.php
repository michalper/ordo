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
use Magento\Store\Model\StoreManagerInterface;
use Ordo\Automation\Cron\EscalateStalePendingApprovals;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\OrderApproval;
use Ordo\Automation\Model\ResourceModel\OrderApproval as OrderApprovalResource;
use Ordo\Automation\Model\ResourceModel\OrderApproval\Collection as ApprovalCollection;
use Ordo\Automation\Model\ResourceModel\OrderApproval\CollectionFactory as ApprovalCollectionFactory;
use Ordo\Automation\Model\TriggerOutcomeLogger;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class EscalateStalePendingApprovalsTest extends TestCase
{
    private Config $config;
    private ApprovalCollectionFactory $approvalCollectionFactory;
    private OrderApprovalResource $orderApprovalResource;
    private OrderCollectionFactory $orderCollectionFactory;
    private TransportBuilder $transportBuilder;
    private StoreManagerInterface $storeManager;
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
        $this->storeManager = $this->createStub(StoreManagerInterface::class);
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
            $this->storeManager,
            $this->inlineTranslation,
            $this->triggerOutcomeLogger,
            $this->logger
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

        $order = $this->createStub(Order::class);
        $order->method('getId')->willReturn(7);
        $order->method('getEntityId')->willReturn(7);
        $order->method('getIncrementId')->willReturn('000000007');
        $order->method('getGrandTotal')->willReturn(150.0);
        $order->method('getCustomerId')->willReturn(42);

        $orderCollection = $this->createStub(OrderCollection::class);
        $orderCollection->method('addFieldToFilter')->willReturnSelf();
        $orderCollection->method('getFirstItem')->willReturn($order);
        $this->orderCollectionFactory->method('create')->willReturn($orderCollection);

        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn(1);
        $store->method('getBaseUrl')->willReturn('https://example.com/');
        $this->storeManager->method('getStore')->willReturn($store);

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

        $order = $this->createStub(Order::class);
        $order->method('getId')->willReturn(null);

        $orderCollection = $this->createStub(OrderCollection::class);
        $orderCollection->method('addFieldToFilter')->willReturnSelf();
        $orderCollection->method('getFirstItem')->willReturn($order);
        $this->orderCollectionFactory->method('create')->willReturn($orderCollection);

        $this->orderApprovalResource->expects(self::never())->method('save');

        $this->makeCron()->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLogsErrorWhenEmailSendingThrows(): void
    {
        $approval = $this->createMock(OrderApproval::class);
        $approval->method('getRemindersSent')->willReturn(0);
        $approval->method('getOrderId')->willReturn(7);

        $collection = $this->createStub(ApprovalCollection::class);
        $collection->method('addStalePendingFilter');
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$approval]));
        $this->approvalCollectionFactory->method('create')->willReturn($collection);

        $order = $this->createStub(Order::class);
        $order->method('getId')->willReturn(7);
        $order->method('getEntityId')->willReturn(7);

        $orderCollection = $this->createStub(OrderCollection::class);
        $orderCollection->method('addFieldToFilter')->willReturnSelf();
        $orderCollection->method('getFirstItem')->willReturn($order);
        $this->orderCollectionFactory->method('create')->willReturn($orderCollection);

        $this->storeManager->method('getStore')->willThrowException(new \RuntimeException('no store'));

        $this->orderApprovalResource->expects(self::never())->method('save');
        $this->logger->expects(self::once())->method('error');

        $this->makeCron()->execute();
    }
}
