<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml\ScoreRule;

use Magento\Backend\Model\View\Result\Redirect;
use Ordo\Automation\Controller\Adminhtml\ScoreRule\Delete;
use Ordo\Automation\Model\ResourceModel\ScoreRule as ScoreRuleResource;
use Ordo\Automation\Model\ScoreRule;
use Ordo\Automation\Model\ScoreRuleFactory;
use Ordo\Automation\Test\Unit\Controller\AbstractAdminActionTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class DeleteTest extends AbstractAdminActionTestCase
{
    public function testExecuteRedirectsWithErrorWhenEntityIdMissing(): void
    {
        $context = $this->makeContext();
        $this->request->method('getParam')->with('entity_id')->willReturn(null);

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->with('*/*/')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $this->messageManager->expects(self::once())->method('addErrorMessage');

        $scoreRuleFactory = $this->createMock(ScoreRuleFactory::class);
        $scoreRuleFactory->expects(self::never())->method('create');
        $scoreRuleResource = $this->createStub(ScoreRuleResource::class);

        $controller = new Delete($context, $scoreRuleFactory, $scoreRuleResource);
        self::assertSame($redirect, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteDeletesAndRedirectsOnSuccess(): void
    {
        $context = $this->makeContext();
        $this->request->method('getParam')->with('entity_id')->willReturn(5);

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->with('*/*/')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $this->messageManager->expects(self::once())->method('addSuccessMessage');

        $scoreRule = $this->createStub(ScoreRule::class);
        $scoreRuleFactory = $this->createMock(ScoreRuleFactory::class);
        $scoreRuleFactory->method('create')->willReturn($scoreRule);

        $scoreRuleResource = $this->createMock(ScoreRuleResource::class);
        $scoreRuleResource->expects(self::once())->method('load')->with($scoreRule, 5);
        $scoreRuleResource->expects(self::once())->method('delete')->with($scoreRule);

        $controller = new Delete($context, $scoreRuleFactory, $scoreRuleResource);
        self::assertSame($redirect, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRedirectsWithErrorWhenDeleteThrows(): void
    {
        $context = $this->makeContext();
        $this->request->method('getParam')->with('entity_id')->willReturn(5);

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->with('*/*/')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $this->messageManager->expects(self::once())->method('addErrorMessage');

        $scoreRule = $this->createStub(ScoreRule::class);
        $scoreRuleFactory = $this->createMock(ScoreRuleFactory::class);
        $scoreRuleFactory->method('create')->willReturn($scoreRule);

        $scoreRuleResource = $this->createMock(ScoreRuleResource::class);
        $scoreRuleResource->method('delete')->willThrowException(new \RuntimeException('locked'));

        $controller = new Delete($context, $scoreRuleFactory, $scoreRuleResource);
        self::assertSame($redirect, $controller->execute());
    }
}
