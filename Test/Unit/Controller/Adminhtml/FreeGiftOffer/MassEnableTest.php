<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml\FreeGiftOffer;

use Magento\Backend\Model\View\Result\Redirect;
use Magento\Ui\Component\MassAction\Filter;
use Ordo\Automation\Controller\Adminhtml\FreeGiftOffer\MassEnable;
use Ordo\Automation\Model\FreeGiftOffer;
use Ordo\Automation\Model\ResourceModel\FreeGiftOffer as FreeGiftOfferResource;
use Ordo\Automation\Model\ResourceModel\FreeGiftOffer\Collection as FreeGiftOfferCollection;
use Ordo\Automation\Model\ResourceModel\FreeGiftOffer\CollectionFactory as FreeGiftOfferCollectionFactory;
use Ordo\Automation\Test\Unit\Controller\AbstractAdminActionTestCase;
use Ordo\Automation\Test\Unit\Controller\MakesRealCollectionTrait;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class MassEnableTest extends AbstractAdminActionTestCase
{
    use MakesRealCollectionTrait;

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteEnablesEverySelectedFreeGiftOffer(): void
    {
        $context = $this->makeContext();
        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $offerA = $this->createMock(FreeGiftOffer::class);
        $offerA->expects(self::once())->method('setEnabled')->with(true)->willReturnSelf();

        $offerB = $this->createMock(FreeGiftOffer::class);
        $offerB->expects(self::once())->method('setEnabled')->with(true)->willReturnSelf();

        $collection = $this->makeRealCollection(FreeGiftOfferCollection::class, 'ordo_free_gift_offer');
        $collection->addItem($offerA);
        $collection->addItem($offerB);

        $filter = $this->createMock(Filter::class);
        $filter->expects(self::once())->method('getCollection')->willReturn($collection);

        $offerCollectionFactory = $this->createStub(FreeGiftOfferCollectionFactory::class);
        $offerCollectionFactory->method('create')->willReturn($this->createStub(FreeGiftOfferCollection::class));

        $offerResource = $this->createMock(FreeGiftOfferResource::class);
        $offerResource->expects(self::exactly(2))->method('save');

        $this->messageManager->expects(self::once())->method('addSuccessMessage')
            ->with(__('A total of %1 free gift offer(s) have been enabled.', 2));

        $controller = new MassEnable($context, $filter, $offerCollectionFactory, $offerResource);
        $controller->execute();
    }
}
