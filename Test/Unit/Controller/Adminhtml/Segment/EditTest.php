<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml\Segment;

use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\Registry;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Framework\View\Page\Title;
use Magento\Backend\Model\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;
use Ordo\Automation\Controller\Adminhtml\Segment\Edit;
use Ordo\Automation\Model\Segment;
use Ordo\Automation\Model\SegmentFactory;
use Ordo\Automation\Model\ResourceModel\Segment as SegmentResource;
use Ordo\Automation\Test\Unit\Controller\AbstractAdminActionTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class EditTest extends AbstractAdminActionTestCase
{
    private function makeResultPage(string $expectedTitle): Page
    {
        $title = $this->createMock(Title::class);
        $title->expects(self::once())->method('prepend')->with(self::callback(
            fn ($phrase) => (string) $phrase === $expectedTitle
        ));

        $pageConfig = $this->createStub(PageConfig::class);
        $pageConfig->method('getTitle')->willReturn($title);

        $resultPage = $this->createStub(Page::class);
        $resultPage->method('setActiveMenu')->willReturnSelf();
        $resultPage->method('getConfig')->willReturn($pageConfig);

        return $resultPage;
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteBuildsNewSegmentPageWhenNoEntityId(): void
    {
        $context = $this->makeContext();
        $this->request->method('getParam')->with('entity_id')->willReturn(0);

        $segment = $this->createStub(Segment::class);
        $segmentFactory = $this->createStub(SegmentFactory::class);
        $segmentFactory->method('create')->willReturn($segment);

        $segmentResource = $this->createMock(SegmentResource::class);
        $segmentResource->expects(self::never())->method('load');

        $registry = $this->createMock(Registry::class);
        $registry->expects(self::once())->method('register')->with('ordo_segment', $segment);

        $resultPage = $this->makeResultPage('New Segment');
        $resultPageFactory = $this->createMock(PageFactory::class);
        $resultPageFactory->method('create')->willReturn($resultPage);

        $controller = new Edit($context, $resultPageFactory, $registry, $segmentFactory, $segmentResource);
        self::assertSame($resultPage, $controller->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLoadsExistingSegment(): void
    {
        $context = $this->makeContext();
        $this->request->method('getParam')->with('entity_id')->willReturn(5);

        $segment = $this->createStub(Segment::class);
        $segment->method('getEntityId')->willReturn(5);
        $segment->method('getName')->willReturn('VIP customers');

        $segmentFactory = $this->createStub(SegmentFactory::class);
        $segmentFactory->method('create')->willReturn($segment);

        $segmentResource = $this->createMock(SegmentResource::class);
        $segmentResource->expects(self::once())->method('load')->with($segment, 5);

        $registry = $this->createStub(Registry::class);

        $resultPage = $this->makeResultPage('Edit Segment "VIP customers"');
        $resultPageFactory = $this->createMock(PageFactory::class);
        $resultPageFactory->method('create')->willReturn($resultPage);

        $controller = new Edit($context, $resultPageFactory, $registry, $segmentFactory, $segmentResource);
        self::assertSame($resultPage, $controller->execute());
    }

    public function testExecuteRedirectsWhenSegmentNotFound(): void
    {
        $context = $this->makeContext();
        $this->request->method('getParam')->with('entity_id')->willReturn(99);

        $segment = $this->createStub(Segment::class);
        $segment->method('getEntityId')->willReturn(null);

        $segmentFactory = $this->createStub(SegmentFactory::class);
        $segmentFactory->method('create')->willReturn($segment);

        $segmentResource = $this->createStub(SegmentResource::class);

        $registry = $this->createMock(Registry::class);
        $registry->expects(self::never())->method('register');

        $this->messageManager->expects(self::once())->method('addErrorMessage');

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->with('*/*/')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $resultPageFactory = $this->createMock(PageFactory::class);
        $resultPageFactory->expects(self::never())->method('create');

        $controller = new Edit($context, $resultPageFactory, $registry, $segmentFactory, $segmentResource);
        self::assertSame($redirect, $controller->execute());
    }
}
