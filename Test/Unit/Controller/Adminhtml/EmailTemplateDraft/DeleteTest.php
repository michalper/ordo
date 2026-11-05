<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml\EmailTemplateDraft;

use Magento\Backend\Model\View\Result\Redirect;
use Ordo\Automation\Controller\Adminhtml\EmailTemplateDraft\Delete;
use Ordo\Automation\Model\EmailTemplateDraft;
use Ordo\Automation\Model\EmailTemplateDraftFactory;
use Ordo\Automation\Model\ResourceModel\EmailTemplateDraft as EmailTemplateDraftResource;
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

        $draftFactory = $this->createMock(EmailTemplateDraftFactory::class);
        $draftFactory->expects(self::never())->method('create');
        $draftResource = $this->createStub(EmailTemplateDraftResource::class);

        $controller = new Delete($context, $draftFactory, $draftResource);
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

        $draft = $this->createStub(EmailTemplateDraft::class);
        $draftFactory = $this->createMock(EmailTemplateDraftFactory::class);
        $draftFactory->method('create')->willReturn($draft);

        $draftResource = $this->createMock(EmailTemplateDraftResource::class);
        $draftResource->expects(self::once())->method('load')->with($draft, 5);
        $draftResource->expects(self::once())->method('delete')->with($draft);

        $controller = new Delete($context, $draftFactory, $draftResource);
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

        $draft = $this->createStub(EmailTemplateDraft::class);
        $draftFactory = $this->createMock(EmailTemplateDraftFactory::class);
        $draftFactory->method('create')->willReturn($draft);

        $draftResource = $this->createMock(EmailTemplateDraftResource::class);
        $draftResource->method('delete')->willThrowException(new \RuntimeException('locked'));

        $controller = new Delete($context, $draftFactory, $draftResource);
        self::assertSame($redirect, $controller->execute());
    }
}
