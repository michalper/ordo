<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml\Segment;

use Magento\Backend\Model\View\Result\Redirect;
use Magento\Ui\Component\MassAction\Filter;
use Ordo\Automation\Controller\Adminhtml\Segment\MassEnable;
use Ordo\Automation\Model\ResourceModel\Segment as SegmentResource;
use Ordo\Automation\Model\ResourceModel\Segment\Collection as SegmentCollection;
use Ordo\Automation\Model\ResourceModel\Segment\CollectionFactory as SegmentCollectionFactory;
use Ordo\Automation\Model\Segment;
use Ordo\Automation\Test\Unit\Controller\AbstractAdminActionTestCase;
use Ordo\Automation\Test\Unit\Controller\MakesRealCollectionTrait;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class MassEnableTest extends AbstractAdminActionTestCase
{
    use MakesRealCollectionTrait;

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteEnablesEverySelectedSegment(): void
    {
        $context = $this->makeContext();
        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $segmentA = $this->createMock(Segment::class);
        $segmentA->expects(self::once())->method('setEnabled')->with(true)->willReturnSelf();

        $segmentB = $this->createMock(Segment::class);
        $segmentB->expects(self::once())->method('setEnabled')->with(true)->willReturnSelf();

        $collection = $this->makeRealCollection(SegmentCollection::class, 'ordo_segment');
        $collection->addItem($segmentA);
        $collection->addItem($segmentB);

        $filter = $this->createMock(Filter::class);
        $filter->expects(self::once())->method('getCollection')->willReturn($collection);

        $segmentCollectionFactory = $this->createStub(SegmentCollectionFactory::class);
        $segmentCollectionFactory->method('create')->willReturn($this->createStub(SegmentCollection::class));

        $segmentResource = $this->createMock(SegmentResource::class);
        $segmentResource->expects(self::exactly(2))->method('save');

        $this->messageManager->expects(self::once())->method('addSuccessMessage')
            ->with(__('A total of %1 segment(s) have been enabled.', 2));

        $controller = new MassEnable($context, $filter, $segmentCollectionFactory, $segmentResource);
        $controller->execute();
    }
}
