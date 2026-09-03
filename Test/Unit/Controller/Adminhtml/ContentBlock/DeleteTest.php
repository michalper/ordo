<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml\ContentBlock;

use Magento\Backend\Model\View\Result\Redirect;
use Ordo\Automation\Controller\Adminhtml\ContentBlock\Delete;
use Ordo\Automation\Model\ContentBlock;
use Ordo\Automation\Model\ContentBlockFactory;
use Ordo\Automation\Model\ResourceModel\ContentBlock as ContentBlockResource;
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

        $contentBlockFactory = $this->createMock(ContentBlockFactory::class);
        $contentBlockFactory->expects(self::never())->method('create');
        $contentBlockResource = $this->createStub(ContentBlockResource::class);

        $controller = new Delete($context, $contentBlockFactory, $contentBlockResource);
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

        $contentBlock = $this->createStub(ContentBlock::class);
        $contentBlockFactory = $this->createMock(ContentBlockFactory::class);
        $contentBlockFactory->method('create')->willReturn($contentBlock);

        $contentBlockResource = $this->createMock(ContentBlockResource::class);
        $contentBlockResource->expects(self::once())->method('load')->with($contentBlock, 5);
        $contentBlockResource->expects(self::once())->method('delete')->with($contentBlock);

        $controller = new Delete($context, $contentBlockFactory, $contentBlockResource);
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

        $contentBlock = $this->createStub(ContentBlock::class);
        $contentBlockFactory = $this->createMock(ContentBlockFactory::class);
        $contentBlockFactory->method('create')->willReturn($contentBlock);

        $contentBlockResource = $this->createMock(ContentBlockResource::class);
        $contentBlockResource->method('delete')->willThrowException(new \RuntimeException('locked'));

        $controller = new Delete($context, $contentBlockFactory, $contentBlockResource);
        self::assertSame($redirect, $controller->execute());
    }
}
