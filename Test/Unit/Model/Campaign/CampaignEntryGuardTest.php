<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Campaign;

use Ordo\Automation\Model\Campaign\CampaignEntryGuard;
use Ordo\Automation\Model\ResourceModel\Campaign\ScheduledAction\Collection as ScheduledActionCollection;
use Ordo\Automation\Model\ResourceModel\Campaign\ScheduledAction\CollectionFactory as ScheduledActionCollectionFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class CampaignEntryGuardTest extends TestCase
{
    private ScheduledActionCollectionFactory&\PHPUnit\Framework\MockObject\MockObject $collectionFactory;
    private CampaignEntryGuard $guard;

    protected function setUp(): void
    {
        $this->collectionFactory = $this->createMock(ScheduledActionCollectionFactory::class);
        $this->guard = new CampaignEntryGuard($this->collectionFactory);
    }

    private function stubCollection(int $size): ScheduledActionCollection
    {
        $collection = $this->createMock(ScheduledActionCollection::class);
        $collection->expects(self::once())->method('addPendingForCampaignAndCustomerFilter')->with(7, 42)->willReturnSelf();
        $collection->method('getSize')->willReturn($size);

        return $collection;
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testHasPendingEntryIsFalseWhenNoPendingRowExists(): void
    {
        $this->collectionFactory->method('create')->willReturn($this->stubCollection(0));

        self::assertFalse($this->guard->hasPendingEntry(7, 42));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testHasPendingEntryIsTrueWhenAPendingRowExists(): void
    {
        $this->collectionFactory->method('create')->willReturn($this->stubCollection(1));

        self::assertTrue($this->guard->hasPendingEntry(7, 42));
    }
}
