<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml\ReorderCycle;

use Magento\Backend\Model\View\Result\Redirect;
use Ordo\Automation\Controller\Adminhtml\ReorderCycle\RecalculateNow;
use Ordo\Automation\Cron\CalculateReorderCycle;
use Ordo\Automation\Test\Unit\Controller\AbstractAdminActionTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class RecalculateNowTest extends AbstractAdminActionTestCase
{
    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRecalculatesAndRedirectsWithSuccessMessage(): void
    {
        $context = $this->makeContext();

        $redirect = $this->createMock(Redirect::class);
        $redirect->expects(self::once())->method('setPath')->with('ordo/reordercycle/index')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $calculateReorderCycle = $this->createMock(CalculateReorderCycle::class);
        $calculateReorderCycle->expects(self::once())->method('execute')->willReturn(12);

        $this->messageManager->expects(self::once())->method('addSuccessMessage');
        $this->messageManager->expects(self::never())->method('addErrorMessage');

        $controller = new RecalculateNow($context, $calculateReorderCycle);
        self::assertSame($redirect, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteAddsErrorMessageAndRedirectsWhenRecalculationThrows(): void
    {
        $context = $this->makeContext();

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $calculateReorderCycle = $this->createMock(CalculateReorderCycle::class);
        $calculateReorderCycle->method('execute')->willThrowException(new \RuntimeException('db error'));

        $this->messageManager->expects(self::once())->method('addErrorMessage');
        $this->messageManager->expects(self::never())->method('addSuccessMessage');

        $controller = new RecalculateNow($context, $calculateReorderCycle);
        self::assertSame($redirect, $controller->execute());
    }
}
