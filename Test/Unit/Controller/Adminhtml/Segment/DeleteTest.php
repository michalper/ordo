<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml\Segment;

use Magento\Backend\Model\View\Result\Redirect;
use Ordo\Automation\Controller\Adminhtml\Segment\Delete;
use Ordo\Automation\Model\Segment;
use Ordo\Automation\Model\SegmentFactory;
use Ordo\Automation\Model\ResourceModel\Segment as SegmentResource;
use Ordo\Automation\Test\Unit\Controller\AbstractAdminActionTestCase;

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

        $segmentFactory = $this->createMock(SegmentFactory::class);
        $segmentFactory->expects(self::never())->method('create');
        $segmentResource = $this->createMock(SegmentResource::class);

        $controller = new Delete($context, $segmentFactory, $segmentResource);
        self::assertSame($redirect, $controller->execute());
    }

    public function testExecuteDeletesAndRedirectsOnSuccess(): void
    {
        $context = $this->makeContext();
        $this->request->method('getParam')->with('entity_id')->willReturn(5);

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->with('*/*/')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $this->messageManager->expects(self::once())->method('addSuccessMessage');

        $segment = $this->createMock(Segment::class);
        $segmentFactory = $this->createMock(SegmentFactory::class);
        $segmentFactory->method('create')->willReturn($segment);

        $segmentResource = $this->createMock(SegmentResource::class);
        $segmentResource->expects(self::once())->method('load')->with($segment, 5);
        $segmentResource->expects(self::once())->method('delete')->with($segment);

        $controller = new Delete($context, $segmentFactory, $segmentResource);
        self::assertSame($redirect, $controller->execute());
    }

    public function testExecuteRedirectsWithErrorWhenDeleteThrows(): void
    {
        $context = $this->makeContext();
        $this->request->method('getParam')->with('entity_id')->willReturn(5);

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->with('*/*/')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $this->messageManager->expects(self::once())->method('addErrorMessage');

        $segment = $this->createMock(Segment::class);
        $segmentFactory = $this->createMock(SegmentFactory::class);
        $segmentFactory->method('create')->willReturn($segment);

        $segmentResource = $this->createMock(SegmentResource::class);
        $segmentResource->method('delete')->willThrowException(new \RuntimeException('locked'));

        $controller = new Delete($context, $segmentFactory, $segmentResource);
        self::assertSame($redirect, $controller->execute());
    }
}
