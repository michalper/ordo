<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml\AdAudience;

use Magento\Backend\Model\View\Result\Redirect;
use Magento\Ui\Component\MassAction\Filter;
use Ordo\Automation\Controller\Adminhtml\AdAudience\MassDelete;
use Ordo\Automation\Model\AdAudience;
use Ordo\Automation\Model\ResourceModel\AdAudience as AdAudienceResource;
use Ordo\Automation\Model\ResourceModel\AdAudience\Collection as AdAudienceCollection;
use Ordo\Automation\Model\ResourceModel\AdAudience\CollectionFactory as AdAudienceCollectionFactory;
use Ordo\Automation\Test\Unit\Controller\AbstractAdminActionTestCase;
use Ordo\Automation\Test\Unit\Controller\MakesRealCollectionTrait;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class MassDeleteTest extends AbstractAdminActionTestCase
{
    use MakesRealCollectionTrait;

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteDeletesEverySelectedAdAudience(): void
    {
        $context = $this->makeContext();
        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $adAudienceA = $this->createStub(AdAudience::class);
        $adAudienceB = $this->createStub(AdAudience::class);

        $collection = $this->makeRealCollection(AdAudienceCollection::class, 'ordo_ad_audience');
        $collection->addItem($adAudienceA);
        $collection->addItem($adAudienceB);

        $filter = $this->createMock(Filter::class);
        $filter->expects(self::once())->method('getCollection')->willReturn($collection);

        $adAudienceCollectionFactory = $this->createStub(AdAudienceCollectionFactory::class);
        $adAudienceCollectionFactory->method('create')->willReturn($this->createStub(AdAudienceCollection::class));

        $adAudienceResource = $this->createMock(AdAudienceResource::class);
        $adAudienceResource->expects(self::exactly(2))->method('delete');

        $this->messageManager->expects(self::once())->method('addSuccessMessage')
            ->with(__('A total of %1 ad audience(s) have been deleted.', 2));

        $controller = new MassDelete($context, $filter, $adAudienceCollectionFactory, $adAudienceResource);
        $controller->execute();
    }
}
