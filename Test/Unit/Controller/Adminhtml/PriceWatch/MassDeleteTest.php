<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml\PriceWatch;

use Magento\Backend\Model\View\Result\Redirect;
use Magento\Ui\Component\MassAction\Filter;
use Ordo\Automation\Controller\Adminhtml\PriceWatch\MassDelete;
use Ordo\Automation\Model\PriceWatch\PriceWatchSubscription;
use Ordo\Automation\Model\ResourceModel\PriceWatch\PriceWatchSubscription as PriceWatchSubscriptionResource;
use Ordo\Automation\Model\ResourceModel\PriceWatch\PriceWatchSubscription\Collection as PriceWatchSubscriptionCollection;
use Ordo\Automation\Model\ResourceModel\PriceWatch\PriceWatchSubscription\CollectionFactory as PriceWatchSubscriptionCollectionFactory;
use Ordo\Automation\Test\Unit\Controller\AbstractAdminActionTestCase;
use Ordo\Automation\Test\Unit\Controller\MakesRealCollectionTrait;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class MassDeleteTest extends AbstractAdminActionTestCase
{
    use MakesRealCollectionTrait;

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteDeletesEverySelectedSubscription(): void
    {
        $context = $this->makeContext();
        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $entryA = $this->createStub(PriceWatchSubscription::class);
        $entryB = $this->createStub(PriceWatchSubscription::class);

        $collection = $this->makeRealCollection(
            PriceWatchSubscriptionCollection::class,
            'ordo_price_watch_subscription'
        );
        $collection->addItem($entryA);
        $collection->addItem($entryB);

        $filter = $this->createMock(Filter::class);
        $filter->expects(self::once())->method('getCollection')->willReturn($collection);

        $priceWatchSubscriptionCollectionFactory = $this->createStub(PriceWatchSubscriptionCollectionFactory::class);
        $priceWatchSubscriptionCollectionFactory->method('create')
            ->willReturn($this->createStub(PriceWatchSubscriptionCollection::class));

        $priceWatchSubscriptionResource = $this->createMock(PriceWatchSubscriptionResource::class);
        $priceWatchSubscriptionResource->expects(self::exactly(2))->method('delete');

        $this->messageManager->expects(self::once())->method('addSuccessMessage')
            ->with(__('A total of %1 price watch subscription(s) have been deleted.', 2));

        $controller = new MassDelete(
            $context,
            $filter,
            $priceWatchSubscriptionCollectionFactory,
            $priceWatchSubscriptionResource
        );
        $controller->execute();
    }
}
