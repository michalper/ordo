<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Config as OrderConfig;
use Magento\Sales\Model\ResourceModel\Order as OrderResource;
use Magento\Sales\Model\ResourceModel\Order\Collection as OrderCollection;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Ordo\Automation\Model\OrderApproval;
use Ordo\Automation\Model\OrderApprovalDecisionLinks;
use Ordo\Automation\Model\OrderApprovalDecisionLinksFactory;
use Ordo\Automation\Model\OrderApprovalFactory;
use Ordo\Automation\Model\OrderApprovalManagement;
use Ordo\Automation\Model\ResourceModel\OrderApproval as OrderApprovalResource;
use PHPUnit\Framework\TestCase;

class OrderApprovalManagementTest extends TestCase
{
    private OrderApprovalFactory $orderApprovalFactory;
    private OrderApprovalResource $orderApprovalResource;
    private OrderCollectionFactory $orderCollectionFactory;
    private OrderResource $orderResource;
    private OrderConfig $orderConfig;
    private OrderRepositoryInterface $orderRepository;
    private StoreManagerInterface $storeManager;
    private OrderApprovalDecisionLinksFactory $decisionLinksFactory;
    private OrderApprovalManagement $management;

    protected function setUp(): void
    {
        $this->orderApprovalFactory = $this->createMock(OrderApprovalFactory::class);
        $this->orderApprovalResource = $this->createMock(OrderApprovalResource::class);
        $this->orderCollectionFactory = $this->createMock(OrderCollectionFactory::class);
        $this->orderResource = $this->createMock(OrderResource::class);
        $this->orderConfig = $this->createMock(OrderConfig::class);
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->decisionLinksFactory = $this->createMock(OrderApprovalDecisionLinksFactory::class);

        $this->management = new OrderApprovalManagement(
            $this->orderApprovalFactory,
            $this->orderApprovalResource,
            $this->orderCollectionFactory,
            $this->orderResource,
            $this->orderConfig,
            $this->orderRepository,
            $this->storeManager,
            $this->decisionLinksFactory
        );
    }

    public function testApproveByTokenThrowsWhenTokenEmpty(): void
    {
        $this->orderApprovalFactory->expects(self::never())->method('create');

        $this->expectException(NoSuchEntityException::class);
        $this->management->approveByToken('');
    }

    public function testApproveByTokenThrowsWhenApprovalNotPending(): void
    {
        $approval = $this->createMock(OrderApproval::class);
        $approval->method('getId')->willReturn(null);
        $this->orderApprovalFactory->method('create')->willReturn($approval);

        $this->expectException(NoSuchEntityException::class);
        $this->management->approveByToken('tok');
    }

    public function testApproveByTokenThrowsWhenOrderNotFound(): void
    {
        $approval = $this->createMock(OrderApproval::class);
        $approval->method('getId')->willReturn(1);
        $approval->method('isPending')->willReturn(true);
        $approval->method('getOrderId')->willReturn(7);
        $this->orderApprovalFactory->method('create')->willReturn($approval);

        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(null);
        $orderCollection = $this->createMock(OrderCollection::class);
        $orderCollection->method('addFieldToFilter')->willReturnSelf();
        $orderCollection->method('getFirstItem')->willReturn($order);
        $this->orderCollectionFactory->method('create')->willReturn($orderCollection);

        $this->expectException(LocalizedException::class);
        $this->management->approveByToken('tok');
    }

    public function testApproveByTokenReleasesOrderAndMarksApproved(): void
    {
        $approval = $this->createMock(OrderApproval::class);
        $approval->method('getId')->willReturn(1);
        $approval->method('isPending')->willReturn(true);
        $approval->method('getOrderId')->willReturn(7);
        $approval->expects(self::exactly(2))->method('setData');
        $this->orderApprovalFactory->method('create')->willReturn($approval);

        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(7);
        $order->expects(self::once())->method('setStatus')->with('processing');
        $orderCollection = $this->createMock(OrderCollection::class);
        $orderCollection->method('addFieldToFilter')->willReturnSelf();
        $orderCollection->method('getFirstItem')->willReturn($order);
        $this->orderCollectionFactory->method('create')->willReturn($orderCollection);

        $this->orderConfig->method('getStateDefaultStatus')->with(Order::STATE_NEW)->willReturn('processing');
        $this->orderResource->expects(self::once())->method('save')->with($order);
        $this->orderApprovalResource->expects(self::once())->method('save')->with($approval);

        self::assertSame($approval, $this->management->approveByToken('tok'));
    }

    public function testRejectByTokenCancelsOrderAndMarksRejected(): void
    {
        $approval = $this->createMock(OrderApproval::class);
        $approval->method('getId')->willReturn(1);
        $approval->method('isPending')->willReturn(true);
        $approval->method('getOrderId')->willReturn(7);
        $approval->expects(self::exactly(2))->method('setData');
        $this->orderApprovalFactory->method('create')->willReturn($approval);

        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(7);
        $order->expects(self::once())->method('cancel');
        $orderCollection = $this->createMock(OrderCollection::class);
        $orderCollection->method('addFieldToFilter')->willReturnSelf();
        $orderCollection->method('getFirstItem')->willReturn($order);
        $this->orderCollectionFactory->method('create')->willReturn($orderCollection);

        $this->orderRepository->expects(self::once())->method('save')->with($order);
        $this->orderApprovalResource->expects(self::once())->method('save')->with($approval);

        self::assertSame($approval, $this->management->rejectByToken('tok'));
    }

    public function testRejectByTokenThrowsWhenTokenEmpty(): void
    {
        $this->expectException(NoSuchEntityException::class);
        $this->management->rejectByToken('');
    }

    public function testGetDecisionLinksByIdThrowsWhenNotPending(): void
    {
        $approval = $this->createMock(OrderApproval::class);
        $approval->method('getId')->willReturn(null);
        $this->orderApprovalFactory->method('create')->willReturn($approval);

        $this->expectException(NoSuchEntityException::class);
        $this->management->getDecisionLinksById(5);
    }

    public function testGetDecisionLinksByIdBuildsUrlsFromToken(): void
    {
        $approval = $this->createMock(OrderApproval::class);
        $approval->method('getId')->willReturn(5);
        $approval->method('isPending')->willReturn(true);
        $approval->method('getToken')->willReturn('secret-token');
        $this->orderApprovalFactory->method('create')->willReturn($approval);

        $store = $this->createMock(Store::class);
        $store->method('getBaseUrl')->willReturn('https://example.com/');
        $this->storeManager->method('getStore')->willReturn($store);

        $links = $this->createMock(OrderApprovalDecisionLinks::class);
        $links->expects(self::once())->method('setApproveUrl')
            ->with('https://example.com/ordo/approval/approve/token/secret-token')
            ->willReturnSelf();
        $links->expects(self::once())->method('setRejectUrl')
            ->with('https://example.com/ordo/approval/reject/token/secret-token')
            ->willReturnSelf();
        $this->decisionLinksFactory->method('create')->willReturn($links);

        self::assertSame($links, $this->management->getDecisionLinksById(5));
    }
}
