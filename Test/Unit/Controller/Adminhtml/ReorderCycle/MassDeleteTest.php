<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml\ReorderCycle;

use Magento\Backend\Model\View\Result\Redirect;
use Magento\Ui\Component\MassAction\Filter;
use Ordo\Automation\Controller\Adminhtml\ReorderCycle\MassDelete;
use Ordo\Automation\Model\ReorderCycle;
use Ordo\Automation\Model\ResourceModel\ReorderCycle as ReorderCycleResource;
use Ordo\Automation\Model\ResourceModel\ReorderCycle\Collection as ReorderCycleCollection;
use Ordo\Automation\Model\ResourceModel\ReorderCycle\CollectionFactory as ReorderCycleCollectionFactory;
use Ordo\Automation\Test\Unit\Controller\AbstractAdminActionTestCase;
use Ordo\Automation\Test\Unit\Controller\MakesRealCollectionTrait;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class MassDeleteTest extends AbstractAdminActionTestCase
{
    use MakesRealCollectionTrait;

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteDeletesEverySelectedReorderCycle(): void
    {
        $context = $this->makeContext();
        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $cycleA = $this->createStub(ReorderCycle::class);
        $cycleB = $this->createStub(ReorderCycle::class);

        $collection = $this->makeRealCollection(ReorderCycleCollection::class, 'ordo_reorder_cycle');
        $collection->addItem($cycleA);
        $collection->addItem($cycleB);

        $filter = $this->createMock(Filter::class);
        $filter->expects(self::once())->method('getCollection')->willReturn($collection);

        $reorderCycleCollectionFactory = $this->createStub(ReorderCycleCollectionFactory::class);
        $reorderCycleCollectionFactory->method('create')->willReturn($this->createStub(ReorderCycleCollection::class));

        $reorderCycleResource = $this->createMock(ReorderCycleResource::class);
        $reorderCycleResource->expects(self::exactly(2))->method('delete');

        $this->messageManager->expects(self::once())->method('addSuccessMessage')
            ->with(__('A total of %1 reorder cycle(s) have been deleted.', 2));

        $controller = new MassDelete($context, $filter, $reorderCycleCollectionFactory, $reorderCycleResource);
        $controller->execute();
    }
}
