<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml\FreeGiftOffer;

use Magento\Backend\Model\View\Result\Redirect;
use Magento\Ui\Component\MassAction\Filter;
use Ordo\Automation\Controller\Adminhtml\FreeGiftOffer\MassDisable;
use Ordo\Automation\Model\FreeGiftOffer;
use Ordo\Automation\Model\ResourceModel\FreeGiftOffer as FreeGiftOfferResource;
use Ordo\Automation\Model\ResourceModel\FreeGiftOffer\Collection as FreeGiftOfferCollection;
use Ordo\Automation\Model\ResourceModel\FreeGiftOffer\CollectionFactory as FreeGiftOfferCollectionFactory;
use Ordo\Automation\Test\Unit\Controller\AbstractAdminActionTestCase;
use Ordo\Automation\Test\Unit\Controller\MakesRealCollectionTrait;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class MassDisableTest extends AbstractAdminActionTestCase
{
    use MakesRealCollectionTrait;

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteDisablesEverySelectedFreeGiftOffer(): void
    {
        $context = $this->makeContext();
        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $offer = $this->createMock(FreeGiftOffer::class);
        $offer->expects(self::once())->method('setEnabled')->with(false)->willReturnSelf();

        $collection = $this->makeRealCollection(FreeGiftOfferCollection::class, 'ordo_free_gift_offer');
        $collection->addItem($offer);

        $filter = $this->createMock(Filter::class);
        $filter->expects(self::once())->method('getCollection')->willReturn($collection);

        $offerCollectionFactory = $this->createStub(FreeGiftOfferCollectionFactory::class);
        $offerCollectionFactory->method('create')->willReturn($this->createStub(FreeGiftOfferCollection::class));

        $offerResource = $this->createMock(FreeGiftOfferResource::class);
        $offerResource->expects(self::once())->method('save')->with($offer);

        $this->messageManager->expects(self::once())->method('addSuccessMessage')
            ->with(__('A total of %1 free gift offer(s) have been disabled.', 1));

        $controller = new MassDisable($context, $filter, $offerCollectionFactory, $offerResource);
        $controller->execute();
    }
}
