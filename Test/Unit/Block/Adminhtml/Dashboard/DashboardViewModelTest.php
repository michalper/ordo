<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Block\Adminhtml\Dashboard;

use Ordo\Automation\Api\Data\CampaignTriggerInterface;
use Ordo\Automation\Block\Adminhtml\Dashboard\DashboardViewModel;
use Ordo\Automation\Model\Campaign;
use Ordo\Automation\Model\ResourceModel\Campaign\Collection as CampaignCollection;
use Ordo\Automation\Model\ResourceModel\Campaign\CollectionFactory as CampaignCollectionFactory;
use Ordo\Automation\Model\ResourceModel\Campaign\Trigger\Collection as CampaignTriggerCollection;
use Ordo\Automation\Model\ResourceModel\Campaign\Trigger\CollectionFactory as CampaignTriggerCollectionFactory;
use Ordo\Automation\Model\ResourceModel\FreeGiftOffer\Collection as FreeGiftOfferCollection;
use Ordo\Automation\Model\ResourceModel\FreeGiftOffer\CollectionFactory as FreeGiftOfferCollectionFactory;
use Ordo\Automation\Model\ResourceModel\ReorderCycle\Collection as ReorderCycleCollection;
use Ordo\Automation\Model\ResourceModel\ReorderCycle\CollectionFactory as ReorderCycleCollectionFactory;
use Ordo\Automation\Model\TriggerOutcomeLogger;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class DashboardViewModelTest extends TestCase
{
    private function makeViewModel(
        ?CampaignCollectionFactory $campaignCollectionFactory = null,
        ?ReorderCycleCollectionFactory $reorderCycleCollectionFactory = null,
        ?FreeGiftOfferCollectionFactory $freeGiftOfferCollectionFactory = null,
        ?CampaignTriggerCollectionFactory $campaignTriggerCollectionFactory = null,
        ?TriggerOutcomeLogger $triggerOutcomeLogger = null
    ): DashboardViewModel {
        return new DashboardViewModel(
            $campaignCollectionFactory ?? $this->createStub(CampaignCollectionFactory::class),
            $campaignTriggerCollectionFactory ?? $this->createStub(CampaignTriggerCollectionFactory::class),
            $reorderCycleCollectionFactory ?? $this->createStub(ReorderCycleCollectionFactory::class),
            $freeGiftOfferCollectionFactory ?? $this->createStub(FreeGiftOfferCollectionFactory::class),
            $triggerOutcomeLogger ?? $this->createStub(TriggerOutcomeLogger::class)
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetCampaignsOrdersByEntityIdDescending(): void
    {
        $campaign = $this->createStub(Campaign::class);
        $campaign->method('getEntityId')->willReturn(5);

        $collection = $this->createMock(CampaignCollection::class);
        $collection->expects(self::once())->method('setOrder')->with('entity_id', 'DESC');
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$campaign]));

        $campaignCollectionFactory = $this->createStub(CampaignCollectionFactory::class);
        $campaignCollectionFactory->method('create')->willReturn($collection);

        $triggerCollection = $this->createStub(CampaignTriggerCollection::class);
        $triggerCollection->method('getIterator')->willReturn(new \ArrayIterator([]));

        $campaignTriggerCollectionFactory = $this->createStub(CampaignTriggerCollectionFactory::class);
        $campaignTriggerCollectionFactory->method('create')->willReturn($triggerCollection);

        $viewModel = $this->makeViewModel($campaignCollectionFactory, null, null, $campaignTriggerCollectionFactory);

        self::assertSame([$campaign], $viewModel->getCampaigns());
    }

    public function testGetCampaignsLoadsTriggerLabelsForAllCampaignsInOneQuery(): void
    {
        $campaignOne = $this->createStub(Campaign::class);
        $campaignOne->method('getEntityId')->willReturn(5);

        $campaignTwo = $this->createStub(Campaign::class);
        $campaignTwo->method('getEntityId')->willReturn(9);

        $collection = $this->createStub(CampaignCollection::class);
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$campaignOne, $campaignTwo]));

        $campaignCollectionFactory = $this->createStub(CampaignCollectionFactory::class);
        $campaignCollectionFactory->method('create')->willReturn($collection);

        $triggerForFive = $this->createStub(\Ordo\Automation\Model\CampaignTrigger::class);
        $triggerForFive->method('getCampaignId')->willReturn(5);
        $triggerForFive->method('getTriggerEvent')->willReturn(CampaignTriggerInterface::TRIGGER_ORDER_PLACED);

        $triggerForNine = $this->createStub(\Ordo\Automation\Model\CampaignTrigger::class);
        $triggerForNine->method('getCampaignId')->willReturn(9);
        $triggerForNine->method('getTriggerEvent')->willReturn(CampaignTriggerInterface::TRIGGER_TAG_ADDED);

        $triggerCollection = $this->createMock(CampaignTriggerCollection::class);
        $triggerCollection->expects(self::once())->method('addFieldToFilter')
            ->with('campaign_id', ['in' => [5, 9]]);
        $triggerCollection->method('getIterator')->willReturn(new \ArrayIterator([$triggerForFive, $triggerForNine]));

        $campaignTriggerCollectionFactory = $this->createStub(CampaignTriggerCollectionFactory::class);
        $campaignTriggerCollectionFactory->method('create')->willReturn($triggerCollection);

        $viewModel = $this->makeViewModel($campaignCollectionFactory, null, null, $campaignTriggerCollectionFactory);
        $viewModel->getCampaigns();

        self::assertSame('Order Placed', $viewModel->getTriggerLabelsForCampaign(5));
        self::assertSame('Tag Added', $viewModel->getTriggerLabelsForCampaign(9));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetTotalCampaignCount(): void
    {
        $collection = $this->createMock(CampaignCollection::class);
        $collection->method('getSize')->willReturn(3);

        $campaignCollectionFactory = $this->createStub(CampaignCollectionFactory::class);
        $campaignCollectionFactory->method('create')->willReturn($collection);

        $viewModel = $this->makeViewModel($campaignCollectionFactory);

        self::assertSame(3, $viewModel->getTotalCampaignCount());
    }

    public function testGetEnabledCampaignCountFilters(): void
    {
        $collection = $this->createMock(CampaignCollection::class);
        $collection->expects(self::once())->method('addFieldToFilter')->with('enabled', '1');
        $collection->method('getSize')->willReturn(2);

        $campaignCollectionFactory = $this->createStub(CampaignCollectionFactory::class);
        $campaignCollectionFactory->method('create')->willReturn($collection);

        $viewModel = $this->makeViewModel($campaignCollectionFactory);

        self::assertSame(2, $viewModel->getEnabledCampaignCount());
    }

    public function testGetReorderCycleCount(): void
    {
        $reorderCollection = $this->createStub(ReorderCycleCollection::class);
        $reorderCollection->method('getSize')->willReturn(7);

        $reorderCycleCollectionFactory = $this->createStub(ReorderCycleCollectionFactory::class);
        $reorderCycleCollectionFactory->method('create')->willReturn($reorderCollection);

        $viewModel = $this->makeViewModel(null, $reorderCycleCollectionFactory);

        self::assertSame(7, $viewModel->getReorderCycleCount());
    }

    public function testGetFreeGiftOfferCount(): void
    {
        $offerCollection = $this->createStub(FreeGiftOfferCollection::class);
        $offerCollection->method('getSize')->willReturn(4);

        $freeGiftOfferCollectionFactory = $this->createStub(FreeGiftOfferCollectionFactory::class);
        $freeGiftOfferCollectionFactory->method('create')->willReturn($offerCollection);

        $viewModel = $this->makeViewModel(null, null, $freeGiftOfferCollectionFactory);

        self::assertSame(4, $viewModel->getFreeGiftOfferCount());
    }

    public function testGetTriggerLabelReturnsKnownLabel(): void
    {
        $viewModel = $this->makeViewModel();

        self::assertSame('Order Placed', $viewModel->getTriggerLabel(CampaignTriggerInterface::TRIGGER_ORDER_PLACED));
    }

    public function testGetTriggerLabelFallsBackToRawValue(): void
    {
        $viewModel = $this->makeViewModel();

        self::assertSame('unknown_trigger', $viewModel->getTriggerLabel('unknown_trigger'));
    }

    public function testGetTriggerLabelsForCampaignJoinsAllLabels(): void
    {
        $triggerOne = $this->createStub(\Ordo\Automation\Model\CampaignTrigger::class);
        $triggerOne->method('getTriggerEvent')->willReturn(CampaignTriggerInterface::TRIGGER_ORDER_PLACED);

        $triggerTwo = $this->createStub(\Ordo\Automation\Model\CampaignTrigger::class);
        $triggerTwo->method('getTriggerEvent')->willReturn(CampaignTriggerInterface::TRIGGER_TAG_ADDED);

        $triggerOne->method('getCampaignId')->willReturn(5);
        $triggerTwo->method('getCampaignId')->willReturn(5);

        $collection = $this->createMock(CampaignTriggerCollection::class);
        $collection->expects(self::once())->method('addFieldToFilter')->with('campaign_id', ['in' => [5]]);
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$triggerOne, $triggerTwo]));

        $campaignTriggerCollectionFactory = $this->createStub(CampaignTriggerCollectionFactory::class);
        $campaignTriggerCollectionFactory->method('create')->willReturn($collection);

        $viewModel = $this->makeViewModel(null, null, null, $campaignTriggerCollectionFactory);

        self::assertSame('Order Placed, Tag Added', $viewModel->getTriggerLabelsForCampaign(5));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetTriggerLabelsForCampaignFallsBackWhenEmpty(): void
    {
        $collection = $this->createMock(CampaignTriggerCollection::class);
        $collection->method('addFieldToFilter');
        $collection->method('getIterator')->willReturn(new \ArrayIterator([]));

        $campaignTriggerCollectionFactory = $this->createStub(CampaignTriggerCollectionFactory::class);
        $campaignTriggerCollectionFactory->method('create')->willReturn($collection);

        $viewModel = $this->makeViewModel(null, null, null, $campaignTriggerCollectionFactory);

        self::assertSame('No trigger configured', $viewModel->getTriggerLabelsForCampaign(5));
    }

    public function testGetTriggerLabelIncludesVisitorTagAdded(): void
    {
        $viewModel = $this->makeViewModel();

        self::assertSame(
            'Visitor Tag Added (anonymous)',
            $viewModel->getTriggerLabel(CampaignTriggerInterface::TRIGGER_VISITOR_TAG_ADDED)
        );
    }

    public function testGetCampaignCountForTriggerFiltersByTriggerEvent(): void
    {
        $collection = $this->createMock(CampaignTriggerCollection::class);
        $collection->expects(self::once())->method('addFieldToFilter')
            ->with('trigger_event', CampaignTriggerInterface::TRIGGER_ORDER_PLACED);
        $collection->method('getSize')->willReturn(3);

        $campaignTriggerCollectionFactory = $this->createStub(CampaignTriggerCollectionFactory::class);
        $campaignTriggerCollectionFactory->method('create')->willReturn($collection);

        $viewModel = $this->makeViewModel(null, null, null, $campaignTriggerCollectionFactory);

        self::assertSame(3, $viewModel->getCampaignCountForTrigger(CampaignTriggerInterface::TRIGGER_ORDER_PLACED));
    }

    public function testGetFixedTriggerEventsReturnsAllKnownTriggers(): void
    {
        $viewModel = $this->makeViewModel();

        self::assertSame(
            [
                CampaignTriggerInterface::TRIGGER_ORDER_PLACED,
                CampaignTriggerInterface::TRIGGER_CUSTOMER_REGISTERED,
                CampaignTriggerInterface::TRIGGER_TAG_ADDED,
                CampaignTriggerInterface::TRIGGER_CART_ABANDONED,
                CampaignTriggerInterface::TRIGGER_VISITOR_TAG_ADDED,
            ],
            $viewModel->getFixedTriggerEvents()
        );
    }

    public function testGetTriggerOutcomeLabelReturnsKnownLabel(): void
    {
        $viewModel = $this->makeViewModel();

        self::assertSame(
            'Win-Back',
            $viewModel->getTriggerOutcomeLabel(TriggerOutcomeLogger::TRIGGER_WIN_BACK)
        );
    }

    public function testGetTriggerOutcomeLabelFallsBackToRawValue(): void
    {
        $viewModel = $this->makeViewModel();

        self::assertSame('unknown_trigger', $viewModel->getTriggerOutcomeLabel('unknown_trigger'));
    }

    public function testGetTriggerStatsReturnsRowForEveryFixedTrigger(): void
    {
        $triggerOutcomeLogger = $this->createStub(TriggerOutcomeLogger::class);
        $triggerOutcomeLogger->method('getStats')->willReturn([
            TriggerOutcomeLogger::TRIGGER_WIN_BACK => ['sent' => 4, 'responded' => 1, 'response_rate' => 25.0],
        ]);

        $viewModel = $this->makeViewModel(null, null, null, null, $triggerOutcomeLogger);

        $stats = $viewModel->getTriggerStats();

        self::assertCount(5, $stats);
        self::assertSame(
            ['label' => 'Win-Back', 'sent' => 4, 'responded' => 1, 'response_rate' => 25.0],
            $stats[TriggerOutcomeLogger::TRIGGER_WIN_BACK]
        );
        self::assertSame(
            ['label' => 'Reorder Reminder', 'sent' => 0, 'responded' => 0, 'response_rate' => 0.0],
            $stats[TriggerOutcomeLogger::TRIGGER_REORDER_REMINDER]
        );
    }
}
