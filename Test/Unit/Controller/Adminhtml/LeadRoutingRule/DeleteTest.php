<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml\LeadRoutingRule;

use Magento\Backend\Model\View\Result\Redirect;
use Ordo\Automation\Controller\Adminhtml\LeadRoutingRule\Delete;
use Ordo\Automation\Model\LeadRoutingRule;
use Ordo\Automation\Model\LeadRoutingRuleFactory;
use Ordo\Automation\Model\ResourceModel\LeadRoutingRule as LeadRoutingRuleResource;
use Ordo\Automation\Test\Unit\Controller\AbstractAdminActionTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class DeleteTest extends AbstractAdminActionTestCase
{
    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRedirectsWithErrorWhenEntityIdMissing(): void
    {
        $context = $this->makeContext();
        $this->request->method('getParam')->willReturnMap([['entity_id', null]]);

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $this->messageManager->expects(self::once())->method('addErrorMessage');

        $leadRoutingRuleFactory = $this->createMock(LeadRoutingRuleFactory::class);
        $leadRoutingRuleFactory->expects(self::never())->method('create');
        $leadRoutingRuleResource = $this->createStub(LeadRoutingRuleResource::class);

        $controller = new Delete($context, $leadRoutingRuleFactory, $leadRoutingRuleResource);
        self::assertSame($redirect, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteDeletesAndRedirectsOnSuccess(): void
    {
        $context = $this->makeContext();
        $this->request->method('getParam')->willReturnMap([['entity_id', 5]]);

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $this->messageManager->expects(self::once())->method('addSuccessMessage');

        $leadRoutingRule = $this->createStub(LeadRoutingRule::class);
        $leadRoutingRuleFactory = $this->createMock(LeadRoutingRuleFactory::class);
        $leadRoutingRuleFactory->method('create')->willReturn($leadRoutingRule);

        $leadRoutingRuleResource = $this->createMock(LeadRoutingRuleResource::class);
        $leadRoutingRuleResource->expects(self::once())->method('load')->with($leadRoutingRule, 5);
        $leadRoutingRuleResource->expects(self::once())->method('delete')->with($leadRoutingRule);

        $controller = new Delete($context, $leadRoutingRuleFactory, $leadRoutingRuleResource);
        self::assertSame($redirect, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRedirectsWithErrorWhenDeleteThrows(): void
    {
        $context = $this->makeContext();
        $this->request->method('getParam')->willReturnMap([['entity_id', 5]]);

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $this->messageManager->expects(self::once())->method('addErrorMessage');

        $leadRoutingRule = $this->createStub(LeadRoutingRule::class);
        $leadRoutingRuleFactory = $this->createMock(LeadRoutingRuleFactory::class);
        $leadRoutingRuleFactory->method('create')->willReturn($leadRoutingRule);

        $leadRoutingRuleResource = $this->createMock(LeadRoutingRuleResource::class);
        $leadRoutingRuleResource->method('delete')->willThrowException(new \RuntimeException('locked'));

        $controller = new Delete($context, $leadRoutingRuleFactory, $leadRoutingRuleResource);
        self::assertSame($redirect, $controller->execute());
    }
}
