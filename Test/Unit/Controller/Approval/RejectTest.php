<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Approval;

use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\Collection as OrderCollection;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Ordo\Automation\Controller\Approval\Reject;
use Ordo\Automation\Model\OrderApproval;
use Ordo\Automation\Model\OrderApprovalFactory;
use Ordo\Automation\Model\ResourceModel\OrderApproval as OrderApprovalResource;
use Ordo\Automation\Test\Unit\Controller\AbstractFrontendActionTestCase;

class RejectTest extends AbstractFrontendActionTestCase
{
    private OrderApprovalFactory $orderApprovalFactory;
    private OrderApprovalResource $orderApprovalResource;
    private OrderCollectionFactory $orderCollectionFactory;
    private OrderRepositoryInterface $orderRepository;

    protected function setUp(): void
    {
        $this->orderApprovalFactory = $this->createMock(OrderApprovalFactory::class);
        $this->orderApprovalResource = $this->createMock(OrderApprovalResource::class);
        $this->orderCollectionFactory = $this->createMock(OrderCollectionFactory::class);
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
    }

    private function makeController(): Reject
    {
        return new Reject(
            $this->makeContext(),
            $this->orderApprovalFactory,
            $this->orderApprovalResource,
            $this->orderCollectionFactory,
            $this->orderRepository
        );
    }

    public function testExecuteRedirectsWithErrorWhenTokenInvalid(): void
    {
        $controller = $this->makeController();
        $this->request->method('getParam')->with('token')->willReturn('');

        $this->messageManager->expects(self::once())->method('addErrorMessage');

        self::assertSame($this->resultRedirect, $controller->execute());
    }

    public function testExecuteRedirectsWithErrorWhenOrderNotFound(): void
    {
        $controller = $this->makeController();
        $this->request->method('getParam')->with('token')->willReturn('tok');

        $approval = $this->createMock(OrderApproval::class);
        $approval->method('getId')->willReturn(1);
        $approval->method('isPending')->willReturn(true);
        $approval->method('getData')->with('order_id')->willReturn(7);
        $this->orderApprovalFactory->method('create')->willReturn($approval);

        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(null);

        $orderCollection = $this->createMock(OrderCollection::class);
        $orderCollection->method('addFieldToFilter')->willReturnSelf();
        $orderCollection->method('getFirstItem')->willReturn($order);
        $this->orderCollectionFactory->method('create')->willReturn($orderCollection);

        $this->messageManager->expects(self::once())->method('addErrorMessage');

        self::assertSame($this->resultRedirect, $controller->execute());
    }

    public function testExecuteCancelsOrderAndRejectsApproval(): void
    {
        $controller = $this->makeController();
        $this->request->method('getParam')->with('token')->willReturn('tok');

        $approval = $this->createMock(OrderApproval::class);
        $approval->method('getId')->willReturn(1);
        $approval->method('isPending')->willReturn(true);
        $approval->method('getData')->with('order_id')->willReturn(7);
        $approval->expects(self::exactly(2))->method('setData');
        $this->orderApprovalFactory->method('create')->willReturn($approval);

        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(7);
        $order->method('getIncrementId')->willReturn('000000007');
        $order->expects(self::once())->method('cancel');

        $orderCollection = $this->createMock(OrderCollection::class);
        $orderCollection->method('addFieldToFilter')->willReturnSelf();
        $orderCollection->method('getFirstItem')->willReturn($order);
        $this->orderCollectionFactory->method('create')->willReturn($orderCollection);

        $this->orderRepository->expects(self::once())->method('save')->with($order);
        $this->orderApprovalResource->expects(self::once())->method('save')->with($approval);

        $this->messageManager->expects(self::once())->method('addSuccessMessage');

        self::assertSame($this->resultRedirect, $controller->execute());
    }
}
