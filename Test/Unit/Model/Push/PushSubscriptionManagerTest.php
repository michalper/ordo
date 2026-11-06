<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Push;

use ArrayIterator;
use Magento\Framework\Exception\AlreadyExistsException;
use Ordo\Automation\Model\Push\PushSubscriptionManager;
use Ordo\Automation\Model\PushSubscription;
use Ordo\Automation\Model\ResourceModel\PushSubscription as PushSubscriptionResource;
use Ordo\Automation\Model\ResourceModel\PushSubscription\Collection as PushSubscriptionCollection;
use Ordo\Automation\Model\ResourceModel\PushSubscription\CollectionFactory as PushSubscriptionCollectionFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class PushSubscriptionManagerTest extends TestCase
{
    private PushSubscriptionResource&MockObject $resource;
    private PushSubscriptionCollectionFactory&MockObject $collectionFactory;
    private PushSubscriptionManager $manager;

    protected function setUp(): void
    {
        $this->resource = $this->createMock(PushSubscriptionResource::class);
        $this->collectionFactory = $this->createMock(PushSubscriptionCollectionFactory::class);
        $this->manager = new PushSubscriptionManager($this->resource, $this->collectionFactory);
    }

    private function makeCollection(array $items, ?PushSubscription $firstItem = null): PushSubscriptionCollection
    {
        $collection = $this->createStub(PushSubscriptionCollection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('addCustomerFilter')->willReturnSelf();
        $collection->method('addVisitorFilter')->willReturnSelf();
        $collection->method('setOrder')->willReturnSelf();
        $collection->method('getFirstItem')->willReturn($firstItem ?? $this->createStub(PushSubscription::class));
        $collection->method('getIterator')->willReturn(new ArrayIterator($items));
        $collection->method('getItems')->willReturn($items);

        return $collection;
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testRegisterCreatesNewRowWhenNoneExists(): void
    {
        $noRow = $this->createMock(PushSubscription::class);
        $noRow->method('getId')->willReturn(null);
        $this->collectionFactory->method('create')->willReturn($this->makeCollection([], $noRow));

        $noRow->expects(self::once())->method('setEndpoint')->with('https://push.example.com/1');
        $noRow->expects(self::once())->method('setP256dhKey')->with('p256dh-key');
        $noRow->expects(self::once())->method('setAuthKey')->with('auth-key');
        $noRow->expects(self::once())->method('setVisitorId')->with('visitor-1');
        $noRow->expects(self::once())->method('setCreatedAt');
        $this->resource->expects(self::once())->method('save')->with($noRow);

        $this->manager->register('https://push.example.com/1', 'p256dh-key', 'auth-key', null, 'visitor-1');
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testRegisterUpdatesExistingRowInsteadOfCreatingDuplicate(): void
    {
        $existing = $this->createMock(PushSubscription::class);
        $existing->method('getId')->willReturn(5);
        $this->collectionFactory->method('create')->willReturn($this->makeCollection([], $existing));

        $existing->expects(self::never())->method('setCreatedAt');
        $existing->expects(self::never())->method('setVisitorId');
        $existing->expects(self::once())->method('setLastSeenAt');
        $this->resource->expects(self::once())->method('save')->with($existing);

        $this->manager->register('https://push.example.com/1', 'p256dh-key', 'auth-key', null, 'visitor-1');
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testRegisterNeverOverwritesKnownCustomerIdWithNull(): void
    {
        $existing = $this->createMock(PushSubscription::class);
        $existing->method('getId')->willReturn(5);
        $this->collectionFactory->method('create')->willReturn($this->makeCollection([], $existing));

        $existing->expects(self::never())->method('setCustomerId');

        $this->manager->register('https://push.example.com/1', 'p256dh-key', 'auth-key', null, null);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testRegisterSetsCustomerIdWhenGiven(): void
    {
        $existing = $this->createMock(PushSubscription::class);
        $existing->method('getId')->willReturn(5);
        $this->collectionFactory->method('create')->willReturn($this->makeCollection([], $existing));

        $existing->expects(self::once())->method('setCustomerId')->with(42);

        $this->manager->register('https://push.example.com/1', 'p256dh-key', 'auth-key', 42, null);
    }

    /**
     * The "shared computer" scenario the class docblock's upsert reasoning implies: a second,
     * different customer registering on the same already-known browser/subscription overwrites
     * the stored customer_id outright - a deliberate design choice (Web Push has no concept of
     * "logged out of this subscription"), not a bug, but worth a regression test since it's the
     * closest thing to a real data-attribution edge case in this class.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testRegisterOverwritesCustomerIdWhenADifferentCustomerRegistersOnTheSameSubscription(): void
    {
        $existing = $this->createMock(PushSubscription::class);
        $existing->method('getId')->willReturn(5);
        $existing->method('getCustomerId')->willReturn(1);
        $this->collectionFactory->method('create')->willReturn($this->makeCollection([], $existing));

        $existing->expects(self::once())->method('setCustomerId')->with(2);

        $this->manager->register('https://push.example.com/1', 'p256dh-key', 'auth-key', 2, null);
    }

    /**
     * Two concurrent register() calls for the same brand-new endpoint (e.g. push-sw.js's own
     * pushsubscriptionchange handler firing at the same moment tracker.js's subscribeToPush()
     * resolves) both see an id-less model and both attempt an insert - the second one must lose
     * the unique-constraint race gracefully (re-fetch and update) rather than surface an error.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testRegisterRecoversFromAlreadyExistsExceptionByUpdatingTheWinningRow(): void
    {
        $noRow = $this->createStub(PushSubscription::class);
        $noRow->method('getId')->willReturn(null);

        $winningRow = $this->createMock(PushSubscription::class);
        $winningRow->method('getId')->willReturn(9);

        $callCount = 0;
        $this->collectionFactory->method('create')->willReturnCallback(function () use (&$callCount, $noRow, $winningRow) {
            $callCount++;
            return $callCount === 1 ? $this->makeCollection([], $noRow) : $this->makeCollection([], $winningRow);
        });

        $this->resource->expects(self::exactly(2))->method('save')
            ->willReturnCallback(function ($subscription) use ($noRow) {
                if ($subscription === $noRow) {
                    throw new AlreadyExistsException(__('duplicate'));
                }
            });

        $winningRow->expects(self::once())->method('setP256dhKey')->with('p256dh-key');
        $winningRow->expects(self::once())->method('setAuthKey')->with('auth-key');
        // The retry path must not re-run the "new row" branch against the row someone else just
        // inserted - it already has an id and its own created_at/visitor_id.
        $winningRow->expects(self::never())->method('setCreatedAt');
        $winningRow->expects(self::never())->method('setVisitorId');

        $this->manager->register('https://push.example.com/1', 'p256dh-key', 'auth-key', null, 'visitor-1');
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testUnregisterDeletesEveryMatchingRowThatBelongsToTheCaller(): void
    {
        $row = $this->createStub(PushSubscription::class);
        $row->method('belongsTo')->willReturn(true);
        $this->collectionFactory->method('create')->willReturn($this->makeCollection([$row]));

        $this->resource->expects(self::once())->method('delete')->with($row);

        $this->manager->unregister('https://push.example.com/1', null, 'visitor-1');
    }

    /**
     * Regression test: the endpoint alone is not proof of ownership - a caller whose own
     * customer_id/visitor_id doesn't match the row's must not be able to delete it.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testUnregisterDoesNotDeleteARowThatDoesNotBelongToTheCaller(): void
    {
        $row = $this->createStub(PushSubscription::class);
        $row->method('belongsTo')->willReturn(false);
        $this->collectionFactory->method('create')->willReturn($this->makeCollection([$row]));

        $this->resource->expects(self::never())->method('delete');

        $this->manager->unregister('https://push.example.com/1', null, 'someone-elses-visitor-id');
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testAttributeVisitorToCustomerUpdatesEachMatchingRow(): void
    {
        $row = $this->createMock(PushSubscription::class);
        $this->collectionFactory->method('create')->willReturn($this->makeCollection([$row]));

        $row->expects(self::once())->method('setCustomerId')->with(42);
        $this->resource->expects(self::once())->method('save')->with($row);

        $this->manager->attributeVisitorToCustomer('visitor-1', 42);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetForCustomerReturnsAllMatchingSubscriptions(): void
    {
        $row1 = $this->createStub(PushSubscription::class);
        $row2 = $this->createStub(PushSubscription::class);
        $this->collectionFactory->method('create')->willReturn($this->makeCollection([$row1, $row2]));

        self::assertSame([$row1, $row2], $this->manager->getForCustomer(42));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testDeleteDelegatesToResource(): void
    {
        $row = $this->createStub(PushSubscription::class);
        $this->resource->expects(self::once())->method('delete')->with($row);

        $this->manager->delete($row);
    }

    /**
     * A brand-new registration that pushes this owner over MAX_SUBSCRIPTIONS_PER_OWNER (20)
     * evicts the oldest surplus rows rather than growing the table without bound - without this,
     * an attacker (or a buggy client retry loop) hammering RegisterPushSubscription could fill
     * ordo_push_subscription indefinitely.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testRegisterEvictsOldestSubscriptionsBeyondTheCapForANewRegistration(): void
    {
        $noRow = $this->createStub(PushSubscription::class);
        $noRow->method('getId')->willReturn(null);

        $newRow = $this->createStub(PushSubscription::class);
        $newRow->method('getId')->willReturn(100);

        $oldest = $this->createStub(PushSubscription::class);
        $oldest->method('getId')->willReturn(1);

        // 21 existing rows (over the cap of 20) plus the new one about to be saved.
        $overflowItems = array_merge([$oldest], array_fill(0, 20, $this->createStub(PushSubscription::class)));

        $callCount = 0;
        $this->collectionFactory->method('create')->willReturnCallback(
            function () use (&$callCount, $noRow, $newRow, $overflowItems) {
                $callCount++;
                if ($callCount === 1) {
                    return $this->makeCollection([], $noRow);
                }
                return $this->makeCollection($overflowItems, $newRow);
            }
        );
        $this->resource->expects(self::once())->method('delete')->with($oldest);

        $this->manager->register('https://push.example.com/new', 'p256dh-key', 'auth-key', 42, null);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testRegisterSkipsCapEnforcementWhenNeitherCustomerNorVisitorIdIsKnown(): void
    {
        // Shouldn't happen from a real Controller\Track\RegisterPushSubscription call (it always
        // has at least a visitor id), but the guard exists regardless - nothing to cap a limit
        // per-owner against without an owner, so enforceSubscriptionCap() bails out before ever
        // building a second collection.
        $noRow = $this->createStub(PushSubscription::class);
        $noRow->method('getId')->willReturn(null);

        // Called twice - once for findByEndpointHash(), once more for enforceSubscriptionCap()'s
        // own collection (built before the customer/visitor check, then discarded unused) - the
        // guard this test targets is "return before doing anything with it", not "never call
        // create() at all".
        $this->collectionFactory->expects(self::exactly(2))->method('create')
            ->willReturn($this->makeCollection([], $noRow));
        $this->resource->expects(self::never())->method('delete');

        $this->manager->register('https://push.example.com/anon', 'p256dh-key', 'auth-key', null, null);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testRegisterNeverEvictsTheJustCreatedSubscriptionItself(): void
    {
        // getId() is null until save() runs (isNew check at the top of register()), then 100
        // afterward (as if the DB had just assigned it) - modeling the same "id only exists
        // after save()" reality a real AbstractModel row has, since enforceSubscriptionCap()'s
        // justCreatedId is read via $subscription->getId() again AFTER the save() call.
        $saved = false;
        $row = $this->createStub(PushSubscription::class);
        $row->method('getId')->willReturnCallback(static fn () => $saved ? 100 : null);
        $this->resource->method('save')->willReturnCallback(function () use (&$saved) {
            $saved = true;
        });

        // The newly-created row (now id=100) sorts first (oldest by last_seen_at) in this
        // contrived fixture - without the "skip if this is the row we just created" guard, it
        // would be the very first one evicted. 22 items total (2 over the cap of 20): the
        // just-created row plus 21 others (each with a distinct, non-matching id), so exactly 2
        // of the OTHER rows must still be deleted once it's skipped.
        $others = [];
        for ($i = 0; $i < 21; $i++) {
            $other = $this->createStub(PushSubscription::class);
            $other->method('getId')->willReturn(200 + $i);
            $others[] = $other;
        }
        $overflowItems = array_merge([$row], $others);

        $callCount = 0;
        $this->collectionFactory->method('create')->willReturnCallback(
            function () use (&$callCount, $row, $overflowItems) {
                $callCount++;
                if ($callCount === 1) {
                    return $this->makeCollection([], $row);
                }
                return $this->makeCollection($overflowItems, $row);
            }
        );
        $deleted = [];
        $this->resource->method('delete')->willReturnCallback(function ($subscription) use (&$deleted) {
            $deleted[] = $subscription;
        });

        $this->manager->register('https://push.example.com/new', 'p256dh-key', 'auth-key', 42, null);

        self::assertCount(2, $deleted);
        foreach ($deleted as $subscription) {
            self::assertNotSame($row, $subscription, 'the just-created row must never be evicted');
        }
    }
}
