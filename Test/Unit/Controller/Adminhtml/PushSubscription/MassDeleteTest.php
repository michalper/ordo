<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml\PushSubscription;

use Magento\Backend\Model\View\Result\Redirect;
use Magento\Ui\Component\MassAction\Filter;
use Ordo\Automation\Controller\Adminhtml\PushSubscription\MassDelete;
use Ordo\Automation\Model\PushSubscription;
use Ordo\Automation\Model\ResourceModel\PushSubscription as PushSubscriptionResource;
use Ordo\Automation\Model\ResourceModel\PushSubscription\Collection as PushSubscriptionCollection;
use Ordo\Automation\Model\ResourceModel\PushSubscription\CollectionFactory as PushSubscriptionCollectionFactory;
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

        $entryA = $this->createStub(PushSubscription::class);
        $entryB = $this->createStub(PushSubscription::class);

        $collection = $this->makeRealCollection(PushSubscriptionCollection::class, 'ordo_push_subscription');
        $collection->addItem($entryA);
        $collection->addItem($entryB);

        $filter = $this->createMock(Filter::class);
        $filter->expects(self::once())->method('getCollection')->willReturn($collection);

        $pushSubscriptionCollectionFactory = $this->createStub(PushSubscriptionCollectionFactory::class);
        $pushSubscriptionCollectionFactory->method('create')
            ->willReturn($this->createStub(PushSubscriptionCollection::class));

        $pushSubscriptionResource = $this->createMock(PushSubscriptionResource::class);
        $pushSubscriptionResource->expects(self::exactly(2))->method('delete');

        $this->messageManager->expects(self::once())->method('addSuccessMessage')
            ->with(__('A total of %1 push subscription(s) have been deleted.', 2));

        $controller = new MassDelete($context, $filter, $pushSubscriptionCollectionFactory, $pushSubscriptionResource);
        $controller->execute();
    }
}
