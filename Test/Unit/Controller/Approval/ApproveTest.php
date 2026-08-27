<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Approval;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Ordo\Automation\Controller\Approval\Approve;
use Ordo\Automation\Model\OrderApproval;
use Ordo\Automation\Model\OrderApprovalManagement;
use Ordo\Automation\Test\Unit\Controller\AbstractFrontendActionTestCase;

class ApproveTest extends AbstractFrontendActionTestCase
{
    private OrderApprovalManagement $orderApprovalManagement;
    private OrderRepositoryInterface $orderRepository;

    protected function setUp(): void
    {
        $this->orderApprovalManagement = $this->createMock(OrderApprovalManagement::class);
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
    }

    private function makeController(): Approve
    {
        return new Approve($this->makeContext(), $this->orderApprovalManagement, $this->orderRepository);
    }

    public function testExecuteRedirectsWithErrorWhenTokenInvalid(): void
    {
        $controller = $this->makeController();
        $this->request->method('getParam')->with('token')->willReturn('');
        $this->orderApprovalManagement->method('approveByToken')
            ->willThrowException(new NoSuchEntityException(__('invalid')));

        $this->messageManager->expects(self::once())->method('addErrorMessage');
        $this->orderRepository->expects(self::never())->method('get');

        self::assertSame($this->resultRedirect, $controller->execute());
    }

    public function testExecuteRedirectsWithErrorWhenOrderCannotBeFound(): void
    {
        $controller = $this->makeController();
        $this->request->method('getParam')->with('token')->willReturn('tok');
        $this->orderApprovalManagement->method('approveByToken')
            ->willThrowException(new LocalizedException(__('The order for this approval could not be found.')));

        $this->messageManager->expects(self::once())->method('addErrorMessage');

        self::assertSame($this->resultRedirect, $controller->execute());
    }

    public function testExecuteApprovesOrderAndRedirectsWithSuccess(): void
    {
        $controller = $this->makeController();
        $this->request->method('getParam')->with('token')->willReturn('tok');

        $approval = $this->createMock(OrderApproval::class);
        $approval->method('getOrderId')->willReturn(7);
        $this->orderApprovalManagement->method('approveByToken')->with('tok')->willReturn($approval);

        $order = $this->createMock(OrderInterface::class);
        $order->method('getIncrementId')->willReturn('000000007');
        $this->orderRepository->method('get')->with(7)->willReturn($order);

        $this->messageManager->expects(self::once())->method('addSuccessMessage');

        self::assertSame($this->resultRedirect, $controller->execute());
    }
}
