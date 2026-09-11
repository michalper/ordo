<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml\Campaign;

use Magento\Backend\Model\View\Result\Redirect;
use Magento\Ui\Component\MassAction\Filter;
use Ordo\Automation\Api\CampaignRepositoryInterface;
use Ordo\Automation\Controller\Adminhtml\Campaign\MassDelete;
use Ordo\Automation\Model\Campaign;
use Ordo\Automation\Model\ResourceModel\Campaign\Collection as CampaignCollection;
use Ordo\Automation\Model\ResourceModel\Campaign\CollectionFactory as CampaignCollectionFactory;
use Ordo\Automation\Test\Unit\Controller\AbstractAdminActionTestCase;
use Ordo\Automation\Test\Unit\Controller\MakesRealCollectionTrait;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class MassDeleteTest extends AbstractAdminActionTestCase
{
    use MakesRealCollectionTrait;

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteDeletesEverySelectedCampaignThroughTheRepository(): void
    {
        $context = $this->makeContext();
        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $campaignA = $this->createStub(Campaign::class);
        $campaignB = $this->createStub(Campaign::class);

        $collection = $this->makeRealCollection(CampaignCollection::class, 'ordo_campaign');
        $collection->addItem($campaignA);
        $collection->addItem($campaignB);

        $filter = $this->createMock(Filter::class);
        $filter->expects(self::once())->method('getCollection')->willReturn($collection);

        $campaignCollectionFactory = $this->createStub(CampaignCollectionFactory::class);
        $campaignCollectionFactory->method('create')->willReturn($this->createStub(CampaignCollection::class));

        $campaignRepository = $this->createMock(CampaignRepositoryInterface::class);
        $campaignRepository->expects(self::exactly(2))->method('delete');

        $this->messageManager->expects(self::once())->method('addSuccessMessage')
            ->with(__('A total of %1 campaign(s) have been deleted.', 2));

        $controller = new MassDelete($context, $filter, $campaignCollectionFactory, $campaignRepository);
        $controller->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReportsZeroWhenNothingIsSelected(): void
    {
        $context = $this->makeContext();
        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $collection = $this->makeRealCollection(CampaignCollection::class, 'ordo_campaign');

        $filter = $this->createStub(Filter::class);
        $filter->method('getCollection')->willReturn($collection);

        $campaignCollectionFactory = $this->createStub(CampaignCollectionFactory::class);
        $campaignCollectionFactory->method('create')->willReturn($this->createStub(CampaignCollection::class));

        $campaignRepository = $this->createMock(CampaignRepositoryInterface::class);
        $campaignRepository->expects(self::never())->method('delete');

        $controller = new MassDelete($context, $filter, $campaignCollectionFactory, $campaignRepository);
        $controller->execute();
    }
}
