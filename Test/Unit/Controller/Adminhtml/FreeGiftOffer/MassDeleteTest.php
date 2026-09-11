<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml\FreeGiftOffer;

use Magento\Backend\Model\View\Result\Redirect;
use Magento\Ui\Component\MassAction\Filter;
use Ordo\Automation\Controller\Adminhtml\FreeGiftOffer\MassDelete;
use Ordo\Automation\Model\FreeGiftOffer;
use Ordo\Automation\Model\ResourceModel\FreeGiftOffer as FreeGiftOfferResource;
use Ordo\Automation\Model\ResourceModel\FreeGiftOffer\Collection as FreeGiftOfferCollection;
use Ordo\Automation\Model\ResourceModel\FreeGiftOffer\CollectionFactory as FreeGiftOfferCollectionFactory;
use Ordo\Automation\Test\Unit\Controller\AbstractAdminActionTestCase;
use Ordo\Automation\Test\Unit\Controller\MakesRealCollectionTrait;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class MassDeleteTest extends AbstractAdminActionTestCase
{
    use MakesRealCollectionTrait;

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteDeletesEverySelectedFreeGiftOffer(): void
    {
        $context = $this->makeContext();
        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $offerA = $this->createStub(FreeGiftOffer::class);
        $offerB = $this->createStub(FreeGiftOffer::class);

        $collection = $this->makeRealCollection(FreeGiftOfferCollection::class, 'ordo_free_gift_offer');
        $collection->addItem($offerA);
        $collection->addItem($offerB);

        $filter = $this->createMock(Filter::class);
        $filter->expects(self::once())->method('getCollection')->willReturn($collection);

        $offerCollectionFactory = $this->createStub(FreeGiftOfferCollectionFactory::class);
        $offerCollectionFactory->method('create')->willReturn($this->createStub(FreeGiftOfferCollection::class));

        $offerResource = $this->createMock(FreeGiftOfferResource::class);
        $offerResource->expects(self::exactly(2))->method('delete');

        $this->messageManager->expects(self::once())->method('addSuccessMessage')
            ->with(__('A total of %1 free gift offer(s) have been deleted.', 2));

        $controller = new MassDelete($context, $filter, $offerCollectionFactory, $offerResource);
        $controller->execute();
    }
}
