<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Config as OrderConfig;
use Magento\Sales\Model\ResourceModel\Order\Collection as OrderCollection;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Store\Model\Store;
use Ordo\Automation\Model\OrderApproval;
use Ordo\Automation\Model\OrderApprovalDecisionLinks;
use Ordo\Automation\Model\OrderApprovalDecisionLinksFactory;
use Ordo\Automation\Model\OrderApprovalFactory;
use Ordo\Automation\Model\OrderApprovalManagement;
use Ordo\Automation\Model\ResourceModel\OrderApproval as OrderApprovalResource;
use Ordo\Automation\Setup\Patch\Data\AddPendingApprovalOrderStatus;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class OrderApprovalManagementTest extends TestCase
{
    private OrderApprovalFactory $orderApprovalFactory;
    private OrderApprovalResource $orderApprovalResource;
    private OrderCollectionFactory $orderCollectionFactory;
    private OrderConfig $orderConfig;
    private OrderRepositoryInterface $orderRepository;
    private OrderApprovalDecisionLinksFactory $decisionLinksFactory;
    private OrderApprovalManagement $management;

    protected function setUp(): void
    {
        $this->orderApprovalFactory = $this->createMock(OrderApprovalFactory::class);
        $this->orderApprovalResource = $this->createMock(OrderApprovalResource::class);
        $this->orderCollectionFactory = $this->createStub(OrderCollectionFactory::class);
        $this->orderConfig = $this->createMock(OrderConfig::class);
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->decisionLinksFactory = $this->createStub(OrderApprovalDecisionLinksFactory::class);

        $this->management = new OrderApprovalManagement(
            $this->orderApprovalFactory,
            $this->orderApprovalResource,
            $this->orderCollectionFactory,
            $this->orderConfig,
            $this->orderRepository,
            $this->decisionLinksFactory
        );
    }

    /**
     * @param \PHPUnit\Framework\MockObject\MockObject&Order $order
     */
    private function makePendingOrder($order, int $orderId = 7): void
    {
        $order->method('getId')->willReturn($orderId);
        $order->method('getStatus')->willReturn(AddPendingApprovalOrderStatus::STATUS_PENDING_APPROVAL);
    }

    private function makeOrderCollection(Order $order): OrderCollection
    {
        $orderCollection = $this->createStub(OrderCollection::class);
        $orderCollection->method('addFieldToFilter')->willReturnSelf();
        $orderCollection->method('getFirstItem')->willReturn($order);
        $this->orderCollectionFactory->method('create')->willReturn($orderCollection);

        return $orderCollection;
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testApproveByTokenThrowsWhenTokenEmpty(): void
    {
        $this->orderApprovalFactory->expects(self::never())->method('create');

        $this->expectException(NoSuchEntityException::class);
        $this->management->approveByToken('');
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testApproveByTokenThrowsWhenApprovalNotPending(): void
    {
        $approval = $this->createMock(OrderApproval::class);
        $approval->method('getId')->willReturn(null);
        $this->orderApprovalFactory->method('create')->willReturn($approval);

        $this->expectException(NoSuchEntityException::class);
        $this->management->approveByToken('tok');
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testApproveByTokenThrowsWhenOrderNotFound(): void
    {
        $approval = $this->createMock(OrderApproval::class);
        $approval->method('getId')->willReturn(1);
        $approval->method('isPending')->willReturn(true);
        $approval->method('getOrderId')->willReturn(7);
        $this->orderApprovalFactory->method('create')->willReturn($approval);
        $this->orderApprovalResource->method('claimPending')->willReturn(true);

        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(null);
        $this->makeOrderCollection($order);

        $this->expectException(LocalizedException::class);
        $this->management->approveByToken('tok');
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testApproveByTokenReleasesOrderAndMarksApproved(): void
    {
        $approval = $this->createMock(OrderApproval::class);
        $approval->method('getId')->willReturn(1);
        $approval->method('isPending')->willReturn(true);
        $approval->method('getOrderId')->willReturn(7);
        $this->orderApprovalFactory->method('create')->willReturn($approval);

        $order = $this->createMock(Order::class);
        $this->makePendingOrder($order);
        $order->expects(self::once())->method('setStatus')->with('processing');
        $this->makeOrderCollection($order);

        $this->orderConfig->method('getStateDefaultStatus')->willReturnMap([[Order::STATE_NEW, 'processing']]);
        $this->orderRepository->expects(self::once())->method('save')->with($order);
        $this->orderApprovalResource->expects(self::once())->method('claimPending')
            ->with($approval, OrderApproval::STATUS_APPROVED)->willReturn(true);

        self::assertSame($approval, $this->management->approveByToken('tok'));
    }

    /**
     * Regression test for a real bug a code audit found: approveByToken()/rejectByToken() used to
     * blindly overwrite the order's status once the approval-row claim succeeded, without
     * re-checking that the *order itself* was still in the held "pending approval" state. An
     * admin manually moving the order elsewhere (e.g. to Complete or Canceled) between the hold
     * and this decision - while `ordo_order_approval` still sat "pending" - meant a stale decision
     * link would silently revert that manual change.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testApproveByTokenThrowsWhenOrderIsNoLongerAwaitingApproval(): void
    {
        $approval = $this->createMock(OrderApproval::class);
        $approval->method('getId')->willReturn(1);
        $approval->method('isPending')->willReturn(true);
        $approval->method('getOrderId')->willReturn(7);
        $this->orderApprovalFactory->method('create')->willReturn($approval);
        $this->orderApprovalResource->method('claimPending')->willReturn(true);

        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(7);
        $order->method('getStatus')->willReturn('complete');
        $order->expects(self::never())->method('setStatus');
        $this->makeOrderCollection($order);

        $this->orderRepository->expects(self::never())->method('save');

        $this->expectException(LocalizedException::class);
        $this->management->approveByToken('tok');
    }

    /**
     * Regression test for a real race-condition bug a code audit found: approveByToken()/
     * rejectByToken() used to load-then-save without a lock, so two concurrent requests for the
     * same token could both pass the "still pending" check before either wrote, and one order
     * could end up both approved and rejected. claimPending() returning false - meaning another
     * request's conditional UPDATE already won - must stop this request before it ever touches
     * the order.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testApproveByTokenThrowsWhenAnotherRequestAlreadyClaimedTheApproval(): void
    {
        $approval = $this->createMock(OrderApproval::class);
        $approval->method('getId')->willReturn(1);
        $approval->method('isPending')->willReturn(true);
        $approval->method('getOrderId')->willReturn(7);
        $this->orderApprovalFactory->method('create')->willReturn($approval);

        $this->orderApprovalResource->method('claimPending')->willReturn(false);
        $this->orderRepository->expects(self::never())->method('save');

        $this->expectException(NoSuchEntityException::class);
        $this->management->approveByToken('tok');
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testRejectByTokenCancelsOrderAndMarksRejected(): void
    {
        $approval = $this->createMock(OrderApproval::class);
        $approval->method('getId')->willReturn(1);
        $approval->method('isPending')->willReturn(true);
        $approval->method('getOrderId')->willReturn(7);
        $this->orderApprovalFactory->method('create')->willReturn($approval);

        $order = $this->createMock(Order::class);
        $this->makePendingOrder($order);
        $order->expects(self::once())->method('cancel');
        $this->makeOrderCollection($order);

        $this->orderRepository->expects(self::once())->method('save')->with($order);
        $this->orderApprovalResource->expects(self::once())->method('claimPending')
            ->with($approval, OrderApproval::STATUS_REJECTED)->willReturn(true);

        self::assertSame($approval, $this->management->rejectByToken('tok'));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testRejectByTokenThrowsWhenOrderIsNoLongerAwaitingApproval(): void
    {
        $approval = $this->createMock(OrderApproval::class);
        $approval->method('getId')->willReturn(1);
        $approval->method('isPending')->willReturn(true);
        $approval->method('getOrderId')->willReturn(7);
        $this->orderApprovalFactory->method('create')->willReturn($approval);
        $this->orderApprovalResource->method('claimPending')->willReturn(true);

        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(7);
        $order->method('getStatus')->willReturn('canceled');
        $order->expects(self::never())->method('cancel');
        $this->makeOrderCollection($order);

        $this->orderRepository->expects(self::never())->method('save');

        $this->expectException(LocalizedException::class);
        $this->management->rejectByToken('tok');
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testRejectByTokenThrowsWhenTokenEmpty(): void
    {
        $this->expectException(NoSuchEntityException::class);
        $this->management->rejectByToken('');
    }

    /**
     * Same race-condition guard as testApproveByTokenThrowsWhenAnotherRequestAlreadyClaimedTheApproval,
     * for the reject side.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testRejectByTokenThrowsWhenAnotherRequestAlreadyClaimedTheApproval(): void
    {
        $approval = $this->createMock(OrderApproval::class);
        $approval->method('getId')->willReturn(1);
        $approval->method('isPending')->willReturn(true);
        $approval->method('getOrderId')->willReturn(7);
        $this->orderApprovalFactory->method('create')->willReturn($approval);

        $this->orderApprovalResource->method('claimPending')->willReturn(false);
        $this->orderRepository->expects(self::never())->method('save');

        $this->expectException(NoSuchEntityException::class);
        $this->management->rejectByToken('tok');
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testRejectByTokenThrowsWhenOrderNotFound(): void
    {
        $approval = $this->createMock(OrderApproval::class);
        $approval->method('getId')->willReturn(1);
        $approval->method('isPending')->willReturn(true);
        $approval->method('getOrderId')->willReturn(7);
        $this->orderApprovalFactory->method('create')->willReturn($approval);
        $this->orderApprovalResource->method('claimPending')->willReturn(true);

        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(null);
        $this->makeOrderCollection($order);

        $this->expectException(LocalizedException::class);
        $this->management->rejectByToken('tok');
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetDecisionLinksByIdThrowsWhenNotPending(): void
    {
        $approval = $this->createMock(OrderApproval::class);
        $approval->method('getId')->willReturn(null);
        $this->orderApprovalFactory->method('create')->willReturn($approval);

        $this->expectException(NoSuchEntityException::class);
        $this->management->getDecisionLinksById(5);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetDecisionLinksByIdBuildsUrlsFromTheOrdersOwnStore(): void
    {
        $approval = $this->createMock(OrderApproval::class);
        $approval->method('getId')->willReturn(5);
        $approval->method('isPending')->willReturn(true);
        $approval->method('getToken')->willReturn('secret-token');
        $approval->method('getOrderId')->willReturn(7);
        $this->orderApprovalFactory->method('create')->willReturn($approval);

        $store = $this->createStub(Store::class);
        $store->method('getBaseUrl')->willReturn('https://example.com/');
        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(7);
        $order->method('getStore')->willReturn($store);
        $this->makeOrderCollection($order);

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
