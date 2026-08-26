<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Block\Adminhtml\Dashboard;

use Ordo\Automation\Api\Data\CampaignInterface;
use Ordo\Automation\Block\Adminhtml\Dashboard\DashboardViewModel;
use Ordo\Automation\Model\ResourceModel\Campaign\Collection as CampaignCollection;
use Ordo\Automation\Model\ResourceModel\Campaign\CollectionFactory as CampaignCollectionFactory;
use Ordo\Automation\Model\ResourceModel\ReorderCycle\Collection as ReorderCycleCollection;
use Ordo\Automation\Model\ResourceModel\ReorderCycle\CollectionFactory as ReorderCycleCollectionFactory;
use PHPUnit\Framework\TestCase;

class DashboardViewModelTest extends TestCase
{
    public function testGetCampaignsOrdersByEntityIdDescending(): void
    {
        $collection = $this->createMock(CampaignCollection::class);
        $collection->expects(self::once())->method('setOrder')->with('entity_id', 'DESC');
        $collection->method('getItems')->willReturn(['campaign1']);

        $campaignCollectionFactory = $this->createMock(CampaignCollectionFactory::class);
        $campaignCollectionFactory->method('create')->willReturn($collection);

        $viewModel = new DashboardViewModel($campaignCollectionFactory, $this->createMock(ReorderCycleCollectionFactory::class));

        self::assertSame(['campaign1'], $viewModel->getCampaigns());
    }

    public function testGetTotalCampaignCount(): void
    {
        $collection = $this->createMock(CampaignCollection::class);
        $collection->method('getSize')->willReturn(3);

        $campaignCollectionFactory = $this->createMock(CampaignCollectionFactory::class);
        $campaignCollectionFactory->method('create')->willReturn($collection);

        $viewModel = new DashboardViewModel($campaignCollectionFactory, $this->createMock(ReorderCycleCollectionFactory::class));

        self::assertSame(3, $viewModel->getTotalCampaignCount());
    }

    public function testGetEnabledCampaignCountFilters(): void
    {
        $collection = $this->createMock(CampaignCollection::class);
        $collection->expects(self::once())->method('addFieldToFilter')->with('enabled', 1);
        $collection->method('getSize')->willReturn(2);

        $campaignCollectionFactory = $this->createMock(CampaignCollectionFactory::class);
        $campaignCollectionFactory->method('create')->willReturn($collection);

        $viewModel = new DashboardViewModel($campaignCollectionFactory, $this->createMock(ReorderCycleCollectionFactory::class));

        self::assertSame(2, $viewModel->getEnabledCampaignCount());
    }

    public function testGetReorderCycleCount(): void
    {
        $reorderCollection = $this->createMock(ReorderCycleCollection::class);
        $reorderCollection->method('getSize')->willReturn(7);

        $reorderCycleCollectionFactory = $this->createMock(ReorderCycleCollectionFactory::class);
        $reorderCycleCollectionFactory->method('create')->willReturn($reorderCollection);

        $viewModel = new DashboardViewModel($this->createMock(CampaignCollectionFactory::class), $reorderCycleCollectionFactory);

        self::assertSame(7, $viewModel->getReorderCycleCount());
    }

    public function testGetTriggerLabelReturnsKnownLabel(): void
    {
        $viewModel = new DashboardViewModel(
            $this->createMock(CampaignCollectionFactory::class),
            $this->createMock(ReorderCycleCollectionFactory::class)
        );

        self::assertSame('Order Placed', $viewModel->getTriggerLabel(CampaignInterface::TRIGGER_ORDER_PLACED));
    }

    public function testGetTriggerLabelFallsBackToRawValue(): void
    {
        $viewModel = new DashboardViewModel(
            $this->createMock(CampaignCollectionFactory::class),
            $this->createMock(ReorderCycleCollectionFactory::class)
        );

        self::assertSame('unknown_trigger', $viewModel->getTriggerLabel('unknown_trigger'));
    }
}
