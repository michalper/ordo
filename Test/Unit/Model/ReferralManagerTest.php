<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Ordo\Automation\Model\Referral;
use Ordo\Automation\Model\ReferralCode;
use Ordo\Automation\Model\ReferralCodeFactory;
use Ordo\Automation\Model\ReferralCodeGenerator;
use Ordo\Automation\Model\ReferralFactory;
use Ordo\Automation\Model\ReferralManager;
use Ordo\Automation\Model\ResourceModel\Referral as ReferralResource;
use Ordo\Automation\Model\ResourceModel\Referral\Collection as ReferralCollection;
use Ordo\Automation\Model\ResourceModel\Referral\CollectionFactory as ReferralCollectionFactory;
use Ordo\Automation\Model\ResourceModel\ReferralCode as ReferralCodeResource;
use Ordo\Automation\Model\ResourceModel\ReferralCode\Collection as ReferralCodeCollection;
use Ordo\Automation\Model\ResourceModel\ReferralCode\CollectionFactory as ReferralCodeCollectionFactory;
use PHPUnit\Framework\TestCase;

class ReferralManagerTest extends TestCase
{
    private ReferralCodeCollectionFactory $referralCodeCollectionFactory;
    private ReferralCodeResource $referralCodeResource;
    private ReferralCodeFactory $referralCodeFactory;
    private ReferralCodeGenerator $referralCodeGenerator;
    private ReferralCollectionFactory $referralCollectionFactory;
    private ReferralResource $referralResource;
    private ReferralFactory $referralFactory;

    protected function setUp(): void
    {
        $this->referralCodeCollectionFactory = $this->createStub(ReferralCodeCollectionFactory::class);
        $this->referralCodeResource = $this->createStub(ReferralCodeResource::class);
        $this->referralCodeFactory = $this->createStub(ReferralCodeFactory::class);
        $this->referralCodeGenerator = $this->createStub(ReferralCodeGenerator::class);
        $this->referralCollectionFactory = $this->createStub(ReferralCollectionFactory::class);
        $this->referralResource = $this->createStub(ReferralResource::class);
        $this->referralFactory = $this->createStub(ReferralFactory::class);
    }

    private function makeManager(): ReferralManager
    {
        return new ReferralManager(
            $this->referralCodeCollectionFactory,
            $this->referralCodeResource,
            $this->referralCodeFactory,
            $this->referralCodeGenerator,
            $this->referralCollectionFactory,
            $this->referralResource,
            $this->referralFactory
        );
    }

    /**
     * @param ReferralCode|null $item stub found by getFirstItem() - null means "not found"
     *   (id 0, same as a fresh model AbstractCollection::getFirstItem() returns on an empty set)
     */
    private function makeReferralCodeCollection(?ReferralCode $item): ReferralCodeCollection
    {
        if ($item === null) {
            $item = $this->createStub(ReferralCode::class);
            $item->method('getId')->willReturn(null);
        }

        $collection = $this->createStub(ReferralCodeCollection::class);
        $collection->method('addCustomerFilter')->willReturnSelf();
        $collection->method('addCodeFilter')->willReturnSelf();
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('getFirstItem')->willReturn($item);

        return $collection;
    }

    private function makeReferralCollection(?Referral $item, int $size = 0): ReferralCollection
    {
        if ($item === null) {
            $item = $this->createStub(Referral::class);
            $item->method('getId')->willReturn(null);
        }

        $collection = $this->createStub(ReferralCollection::class);
        $collection->method('addReferredCustomerFilter')->willReturnSelf();
        $collection->method('addStatusFilter')->willReturnSelf();
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('getSize')->willReturn($size);
        $collection->method('getFirstItem')->willReturn($item);

        return $collection;
    }

    public function testGetOrCreateCodeReturnsExistingCode(): void
    {
        $existing = $this->createStub(ReferralCode::class);
        $existing->method('getId')->willReturn(1);
        $existing->method('getCode')->willReturn('EXIST123');

        $this->referralCodeCollectionFactory->method('create')
            ->willReturn($this->makeReferralCodeCollection($existing));

        self::assertSame('EXIST123', $this->makeManager()->getOrCreateCode(5));
    }

    public function testGetOrCreateCodeGeneratesAndSavesWhenNoneExists(): void
    {
        $this->referralCodeCollectionFactory->method('create')
            ->willReturn($this->makeReferralCodeCollection(null));

        $this->referralCodeGenerator->method('generateUnique')->willReturn('NEWCODE1');

        $newModel = $this->createMock(ReferralCode::class);
        $newModel->expects(self::once())->method('setCustomerId')->with(5);
        $newModel->expects(self::once())->method('setCode')->with('NEWCODE1');
        $this->referralCodeFactory->method('create')->willReturn($newModel);

        $this->referralCodeResource = $this->createMock(ReferralCodeResource::class);
        $this->referralCodeResource->expects(self::once())->method('save')->with($newModel);

        self::assertSame('NEWCODE1', $this->makeManager()->getOrCreateCode(5));
    }

    public function testResolveReferrerCustomerIdReturnsNullForUnknownCode(): void
    {
        $this->referralCodeCollectionFactory->method('create')
            ->willReturn($this->makeReferralCodeCollection(null));

        self::assertNull($this->makeManager()->resolveReferrerCustomerId('NOPE'));
    }

    public function testResolveReferrerCustomerIdReturnsOwnerCustomerId(): void
    {
        $existing = $this->createStub(ReferralCode::class);
        $existing->method('getId')->willReturn(1);
        $existing->method('getCustomerId')->willReturn(42);

        $this->referralCodeCollectionFactory->method('create')
            ->willReturn($this->makeReferralCodeCollection($existing));

        self::assertSame(42, $this->makeManager()->resolveReferrerCustomerId('CODE'));
    }

    public function testRecordSignupRejectsSelfReferral(): void
    {
        $this->referralCollectionFactory = $this->createMock(ReferralCollectionFactory::class);
        $this->referralCollectionFactory->expects(self::never())->method('create');

        self::assertFalse($this->makeManager()->recordSignup(1, 1));
    }

    public function testRecordSignupRejectsAlreadyReferredCustomer(): void
    {
        $this->referralCollectionFactory->method('create')
            ->willReturn($this->makeReferralCollection(null, 1));

        $this->referralResource = $this->createMock(ReferralResource::class);
        $this->referralResource->expects(self::never())->method('save');

        self::assertFalse($this->makeManager()->recordSignup(1, 2));
    }

    public function testRecordSignupSavesNewReferral(): void
    {
        $this->referralCollectionFactory->method('create')
            ->willReturn($this->makeReferralCollection(null, 0));

        $newReferral = $this->createMock(Referral::class);
        $newReferral->expects(self::once())->method('setReferrerCustomerId')->with(1);
        $newReferral->expects(self::once())->method('setReferredCustomerId')->with(2);
        $newReferral->expects(self::once())->method('setStatus')->with(Referral::STATUS_PENDING);
        $this->referralFactory->method('create')->willReturn($newReferral);

        $this->referralResource = $this->createMock(ReferralResource::class);
        $this->referralResource->expects(self::once())->method('save')->with($newReferral);

        self::assertTrue($this->makeManager()->recordSignup(1, 2));
    }

    public function testMarkConvertedAndGetReferrerReturnsNullWhenNoPendingReferral(): void
    {
        $this->referralCollectionFactory->method('create')
            ->willReturn($this->makeReferralCollection(null));

        self::assertNull($this->makeManager()->markConvertedAndGetReferrer(2));
    }

    public function testMarkConvertedAndGetReferrerConvertsAndReturnsReferrer(): void
    {
        $pending = $this->createMock(Referral::class);
        $pending->method('getId')->willReturn(9);
        $pending->method('getReferrerCustomerId')->willReturn(1);
        $pending->expects(self::once())->method('setStatus')->with(Referral::STATUS_CONVERTED);
        $pending->expects(self::once())->method('setData')
            ->with(Referral::CONVERTED_AT, self::callback(static fn ($value): bool => is_string($value)));

        $this->referralCollectionFactory->method('create')
            ->willReturn($this->makeReferralCollection($pending));

        $this->referralResource = $this->createMock(ReferralResource::class);
        $this->referralResource->expects(self::once())->method('save')->with($pending);

        self::assertSame(1, $this->makeManager()->markConvertedAndGetReferrer(2));
    }
}
