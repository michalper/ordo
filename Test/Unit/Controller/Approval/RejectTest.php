<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Approval;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Ordo\Automation\Controller\Approval\Reject;
use Ordo\Automation\Model\OrderApproval;
use Ordo\Automation\Model\OrderApprovalManagement;
use Ordo\Automation\Test\Unit\Controller\AbstractFrontendActionTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class RejectTest extends AbstractFrontendActionTestCase
{
    private OrderApprovalManagement $orderApprovalManagement;
    private OrderRepositoryInterface $orderRepository;

    protected function setUp(): void
    {
        $this->orderApprovalManagement = $this->createMock(OrderApprovalManagement::class);
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
    }

    private function makeController(): Reject
    {
        return new Reject($this->makeContext(), $this->orderApprovalManagement, $this->orderRepository);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRedirectsWithErrorWhenTokenInvalid(): void
    {
        $controller = $this->makeController();
        $this->request->method('getParam')->with('token')->willReturn('');
        $this->orderApprovalManagement->method('rejectByToken')
            ->willThrowException(new NoSuchEntityException(__('invalid')));

        $this->messageManager->expects(self::once())->method('addErrorMessage');
        $this->orderRepository->expects(self::never())->method('get');

        self::assertSame($this->resultRedirect, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRedirectsWithErrorWhenOrderCannotBeFound(): void
    {
        $controller = $this->makeController();
        $this->request->method('getParam')->with('token')->willReturn('tok');
        $this->orderApprovalManagement->method('rejectByToken')
            ->willThrowException(new LocalizedException(__('The order for this approval could not be found.')));

        $this->messageManager->expects(self::once())->method('addErrorMessage');

        self::assertSame($this->resultRedirect, $controller->execute());
    }

    public function testExecuteRejectsOrderAndRedirectsWithSuccess(): void
    {
        $controller = $this->makeController();
        $this->request->method('getParam')->with('token')->willReturn('tok');

        $approval = $this->createStub(OrderApproval::class);
        $approval->method('getOrderId')->willReturn(7);
        $this->orderApprovalManagement->method('rejectByToken')->with('tok')->willReturn($approval);

        $order = $this->createStub(OrderInterface::class);
        $order->method('getIncrementId')->willReturn('000000007');
        $this->orderRepository->method('get')->with(7)->willReturn($order);

        $this->messageManager->expects(self::once())->method('addSuccessMessage');

        self::assertSame($this->resultRedirect, $controller->execute());
    }
}
