<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml\Campaign;

use Magento\Backend\Model\View\Result\Redirect;
use Magento\Ui\Component\MassAction\Filter;
use Ordo\Automation\Api\CampaignRepositoryInterface;
use Ordo\Automation\Controller\Adminhtml\Campaign\MassEnable;
use Ordo\Automation\Model\Campaign;
use Ordo\Automation\Model\ResourceModel\Campaign\Collection as CampaignCollection;
use Ordo\Automation\Model\ResourceModel\Campaign\CollectionFactory as CampaignCollectionFactory;
use Ordo\Automation\Test\Unit\Controller\AbstractAdminActionTestCase;
use Ordo\Automation\Test\Unit\Controller\MakesRealCollectionTrait;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class MassEnableTest extends AbstractAdminActionTestCase
{
    use MakesRealCollectionTrait;

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteEnablesEverySelectedCampaign(): void
    {
        $context = $this->makeContext();
        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $campaignA = $this->createMock(Campaign::class);
        $campaignA->expects(self::once())->method('setEnabled')->with(true)->willReturnSelf();

        $campaignB = $this->createMock(Campaign::class);
        $campaignB->expects(self::once())->method('setEnabled')->with(true)->willReturnSelf();

        $collection = $this->makeRealCollection(CampaignCollection::class, 'ordo_campaign');
        $collection->addItem($campaignA);
        $collection->addItem($campaignB);

        $filter = $this->createMock(Filter::class);
        $filter->expects(self::once())->method('getCollection')->willReturn($collection);

        $campaignCollectionFactory = $this->createStub(CampaignCollectionFactory::class);
        $campaignCollectionFactory->method('create')->willReturn($this->createStub(CampaignCollection::class));

        $campaignRepository = $this->createMock(CampaignRepositoryInterface::class);
        $campaignRepository->expects(self::exactly(2))->method('save');

        $this->messageManager->expects(self::once())->method('addSuccessMessage')
            ->with(__('A total of %1 campaign(s) have been enabled.', 2));

        $controller = new MassEnable($context, $filter, $campaignCollectionFactory, $campaignRepository);
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
        $campaignRepository->expects(self::never())->method('save');

        $this->messageManager->expects(self::once())->method('addSuccessMessage')
            ->with(__('A total of %1 campaign(s) have been enabled.', 0));

        $controller = new MassEnable($context, $filter, $campaignCollectionFactory, $campaignRepository);
        $controller->execute();
    }
}
