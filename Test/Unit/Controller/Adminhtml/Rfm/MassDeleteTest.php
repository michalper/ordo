<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml\Rfm;

use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\DataObject;
use Magento\Ui\Component\MassAction\Filter;
use Ordo\Automation\Controller\Adminhtml\Rfm\MassDelete;
use Ordo\Automation\Model\ResourceModel\Rfm\Grid\Collection as RfmGridCollection;
use Ordo\Automation\Model\ResourceModel\Rfm\Grid\CollectionFactory as RfmGridCollectionFactory;
use Ordo\Automation\Model\Rfm\RfmCalculator;
use Ordo\Automation\Test\Unit\Controller\AbstractAdminActionTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

/**
 * Unlike every other MassDelete test in this module, the collection here can't be built as a
 * real AbstractCollection via MakesRealCollectionTrait: Rfm\Grid\Collection's own constructor
 * (see its docblock) requires a live ResourceConnection and calls _initSelect() against it, which
 * needs a real database. getIterator() (Collection implements IteratorAggregate) is stubbed
 * directly instead - this test only needs foreach($collection as $customer) to yield the two
 * DataObject stand-ins below, not a real query.
 */
class MassDeleteTest extends AbstractAdminActionTestCase
{
    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteResetsCachedScoreForEverySelectedCustomer(): void
    {
        $context = $this->makeContext();
        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $customerA = $this->createStub(DataObject::class);
        $customerA->method('getData')->willReturn(5);
        $customerB = $this->createStub(DataObject::class);
        $customerB->method('getData')->willReturn(9);

        $collection = $this->createMock(RfmGridCollection::class);
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$customerA, $customerB]));

        $filter = $this->createMock(Filter::class);
        $filter->expects(self::once())->method('getCollection')->willReturn($collection);

        $rfmGridCollectionFactory = $this->createStub(RfmGridCollectionFactory::class);
        $rfmGridCollectionFactory->method('create')->willReturn($this->createStub(RfmGridCollection::class));

        $rfmCalculator = $this->createMock(RfmCalculator::class);
        $rfmCalculator->expects(self::once())->method('resetScoresForCustomers')->with([5, 9]);

        $this->messageManager->expects(self::once())->method('addSuccessMessage');

        $controller = new MassDelete($context, $filter, $rfmGridCollectionFactory, $rfmCalculator);
        $controller->execute();
    }
}
