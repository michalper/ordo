<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml\AdAudience;

use Magento\Backend\Model\View\Result\Redirect;
use Magento\Ui\Component\MassAction\Filter;
use Ordo\Automation\Controller\Adminhtml\AdAudience\MassDisable;
use Ordo\Automation\Model\AdAudience;
use Ordo\Automation\Model\ResourceModel\AdAudience as AdAudienceResource;
use Ordo\Automation\Model\ResourceModel\AdAudience\Collection as AdAudienceCollection;
use Ordo\Automation\Model\ResourceModel\AdAudience\CollectionFactory as AdAudienceCollectionFactory;
use Ordo\Automation\Test\Unit\Controller\AbstractAdminActionTestCase;
use Ordo\Automation\Test\Unit\Controller\MakesRealCollectionTrait;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class MassDisableTest extends AbstractAdminActionTestCase
{
    use MakesRealCollectionTrait;

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteDisablesEverySelectedAdAudience(): void
    {
        $context = $this->makeContext();
        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $adAudience = $this->createMock(AdAudience::class);
        $adAudience->expects(self::once())->method('setEnabled')->with(false)->willReturnSelf();

        $collection = $this->makeRealCollection(AdAudienceCollection::class, 'ordo_ad_audience');
        $collection->addItem($adAudience);

        $filter = $this->createMock(Filter::class);
        $filter->expects(self::once())->method('getCollection')->willReturn($collection);

        $adAudienceCollectionFactory = $this->createStub(AdAudienceCollectionFactory::class);
        $adAudienceCollectionFactory->method('create')->willReturn($this->createStub(AdAudienceCollection::class));

        $adAudienceResource = $this->createMock(AdAudienceResource::class);
        $adAudienceResource->expects(self::once())->method('save')->with($adAudience);

        $this->messageManager->expects(self::once())->method('addSuccessMessage')
            ->with(__('A total of %1 ad audience(s) have been disabled.', 1));

        $controller = new MassDisable($context, $filter, $adAudienceCollectionFactory, $adAudienceResource);
        $controller->execute();
    }
}
