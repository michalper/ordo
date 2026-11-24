<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Campaign;

use Ordo\Automation\Model\Campaign\CampaignEntryGuard;
use Ordo\Automation\Model\ResourceModel\Campaign\ScheduledAction\Collection as ScheduledActionCollection;
use Ordo\Automation\Model\ResourceModel\Campaign\ScheduledAction\CollectionFactory as ScheduledActionCollectionFactory;
use Ordo\Automation\Model\ResourceModel\CampaignActionRetry as CampaignActionRetryResource;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class CampaignEntryGuardTest extends TestCase
{
    private ScheduledActionCollectionFactory&\PHPUnit\Framework\MockObject\MockObject $collectionFactory;
    private CampaignActionRetryResource&\PHPUnit\Framework\MockObject\MockObject $campaignActionRetryResource;
    private CampaignEntryGuard $guard;

    protected function setUp(): void
    {
        $this->collectionFactory = $this->createMock(ScheduledActionCollectionFactory::class);
        $this->campaignActionRetryResource = $this->createMock(CampaignActionRetryResource::class);
        $this->guard = new CampaignEntryGuard($this->collectionFactory, $this->campaignActionRetryResource);
    }

    private function stubCollection(int $size): ScheduledActionCollection
    {
        $collection = $this->createMock(ScheduledActionCollection::class);
        $collection->expects(self::once())->method('addPendingForCampaignAndCustomerFilter')->with(7, 42)->willReturnSelf();
        $collection->method('getSize')->willReturn($size);

        return $collection;
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testHasPendingEntryIsFalseWhenNoPendingRowAndNoMatchingRetryExist(): void
    {
        $this->collectionFactory->method('create')->willReturn($this->stubCollection(0));
        $this->campaignActionRetryResource->expects(self::once())->method('fetchContextsForCampaign')->with(7)->willReturn([]);

        self::assertFalse($this->guard->hasPendingEntry(7, 42));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testHasPendingEntryIsTrueWhenAPendingScheduledActionRowExists(): void
    {
        $this->collectionFactory->method('create')->willReturn($this->stubCollection(1));
        $this->campaignActionRetryResource->expects(self::never())->method('fetchContextsForCampaign');

        self::assertTrue($this->guard->hasPendingEntry(7, 42));
    }

    /**
     * The actual bug this guards against: Cron\RunScheduledCampaignActions claims (sets
     * executed_at) BEFORE attempting a resume, so a resume that fails and moves into
     * ordo_campaign_action_retry is invisible to the scheduled-action collection's own "pending"
     * filter - this customer's retry context must still be found via the retry table.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testHasPendingEntryIsTrueWhenAMatchingRetryContextExists(): void
    {
        $this->collectionFactory->method('create')->willReturn($this->stubCollection(0));
        $this->campaignActionRetryResource->expects(self::once())->method('fetchContextsForCampaign')->with(7)->willReturn([
            '{"customer_id":99}',
            '{"customer_id":42}',
        ]);

        self::assertTrue($this->guard->hasPendingEntry(7, 42));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testHasPendingEntryIsFalseWhenRetryContextsExistForOtherCustomersOnly(): void
    {
        $this->collectionFactory->method('create')->willReturn($this->stubCollection(0));
        $this->campaignActionRetryResource->expects(self::once())->method('fetchContextsForCampaign')->with(7)->willReturn([
            '{"customer_id":99}',
            '{"customer_id":100}',
        ]);

        self::assertFalse($this->guard->hasPendingEntry(7, 42));
    }
}
