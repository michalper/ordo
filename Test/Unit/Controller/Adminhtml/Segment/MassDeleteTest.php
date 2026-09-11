<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml\Segment;

use Magento\Backend\Model\View\Result\Redirect;
use Magento\Ui\Component\MassAction\Filter;
use Ordo\Automation\Controller\Adminhtml\Segment\MassDelete;
use Ordo\Automation\Model\ResourceModel\Segment as SegmentResource;
use Ordo\Automation\Model\ResourceModel\Segment\Collection as SegmentCollection;
use Ordo\Automation\Model\ResourceModel\Segment\CollectionFactory as SegmentCollectionFactory;
use Ordo\Automation\Model\Segment;
use Ordo\Automation\Test\Unit\Controller\AbstractAdminActionTestCase;
use Ordo\Automation\Test\Unit\Controller\MakesRealCollectionTrait;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class MassDeleteTest extends AbstractAdminActionTestCase
{
    use MakesRealCollectionTrait;

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteDeletesEverySelectedSegment(): void
    {
        $context = $this->makeContext();
        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $segmentA = $this->createStub(Segment::class);
        $segmentB = $this->createStub(Segment::class);

        $collection = $this->makeRealCollection(SegmentCollection::class, 'ordo_segment');
        $collection->addItem($segmentA);
        $collection->addItem($segmentB);

        $filter = $this->createMock(Filter::class);
        $filter->expects(self::once())->method('getCollection')->willReturn($collection);

        $segmentCollectionFactory = $this->createStub(SegmentCollectionFactory::class);
        $segmentCollectionFactory->method('create')->willReturn($this->createStub(SegmentCollection::class));

        $segmentResource = $this->createMock(SegmentResource::class);
        $segmentResource->expects(self::exactly(2))->method('delete');

        $this->messageManager->expects(self::once())->method('addSuccessMessage')
            ->with(__('A total of %1 segment(s) have been deleted.', 2));

        $controller = new MassDelete($context, $filter, $segmentCollectionFactory, $segmentResource);
        $controller->execute();
    }
}
