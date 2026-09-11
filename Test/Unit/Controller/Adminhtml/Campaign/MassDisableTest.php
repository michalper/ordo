<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\Adminhtml\Campaign;

use Magento\Backend\Model\View\Result\Redirect;
use Magento\Ui\Component\MassAction\Filter;
use Ordo\Automation\Api\CampaignRepositoryInterface;
use Ordo\Automation\Controller\Adminhtml\Campaign\MassDisable;
use Ordo\Automation\Model\Campaign;
use Ordo\Automation\Model\ResourceModel\Campaign\Collection as CampaignCollection;
use Ordo\Automation\Model\ResourceModel\Campaign\CollectionFactory as CampaignCollectionFactory;
use Ordo\Automation\Test\Unit\Controller\AbstractAdminActionTestCase;
use Ordo\Automation\Test\Unit\Controller\MakesRealCollectionTrait;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class MassDisableTest extends AbstractAdminActionTestCase
{
    use MakesRealCollectionTrait;

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteDisablesEverySelectedCampaign(): void
    {
        $context = $this->makeContext();
        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $this->resultRedirectFactory->method('create')->willReturn($redirect);

        $campaign = $this->createMock(Campaign::class);
        $campaign->expects(self::once())->method('setEnabled')->with(false)->willReturnSelf();

        $collection = $this->makeRealCollection(CampaignCollection::class, 'ordo_campaign');
        $collection->addItem($campaign);

        $filter = $this->createMock(Filter::class);
        $filter->expects(self::once())->method('getCollection')->willReturn($collection);

        $campaignCollectionFactory = $this->createStub(CampaignCollectionFactory::class);
        $campaignCollectionFactory->method('create')->willReturn($this->createStub(CampaignCollection::class));

        $campaignRepository = $this->createMock(CampaignRepositoryInterface::class);
        $campaignRepository->expects(self::once())->method('save')->with($campaign);

        $this->messageManager->expects(self::once())->method('addSuccessMessage')
            ->with(__('A total of %1 campaign(s) have been disabled.', 1));

        $controller = new MassDisable($context, $filter, $campaignCollectionFactory, $campaignRepository);
        $controller->execute();
    }
}
