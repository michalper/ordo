<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Ordo\Automation\Model\ConsentChannel;
use Ordo\Automation\Model\ConsentManager;
use Ordo\Automation\Model\CustomerConsent;
use Ordo\Automation\Model\CustomerConsentFactory;
use Ordo\Automation\Model\CustomerConsentLog;
use Ordo\Automation\Model\CustomerConsentLogFactory;
use Ordo\Automation\Model\ResourceModel\CustomerConsent as CustomerConsentResource;
use Ordo\Automation\Model\ResourceModel\CustomerConsent\Collection as CustomerConsentCollection;
use Ordo\Automation\Model\ResourceModel\CustomerConsent\CollectionFactory as CustomerConsentCollectionFactory;
use Ordo\Automation\Model\ResourceModel\CustomerConsentLog as CustomerConsentLogResource;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class ConsentManagerTest extends TestCase
{
    private CustomerConsentCollectionFactory&\PHPUnit\Framework\MockObject\MockObject $collectionFactory;
    private CustomerConsentFactory&\PHPUnit\Framework\MockObject\MockObject $consentFactory;
    private CustomerConsentResource&\PHPUnit\Framework\MockObject\MockObject $consentResource;
    private CustomerConsentLogFactory&\PHPUnit\Framework\MockObject\MockObject $consentLogFactory;
    private CustomerConsentLogResource&\PHPUnit\Framework\MockObject\MockObject $consentLogResource;
    private ConsentManager $manager;

    protected function setUp(): void
    {
        $this->collectionFactory = $this->createMock(CustomerConsentCollectionFactory::class);
        $this->consentFactory = $this->createMock(CustomerConsentFactory::class);
        $this->consentResource = $this->createMock(CustomerConsentResource::class);
        $this->consentLogFactory = $this->createMock(CustomerConsentLogFactory::class);
        $this->consentLogResource = $this->createMock(CustomerConsentLogResource::class);
        $this->consentLogFactory->method('create')->willReturn($this->createStub(CustomerConsentLog::class));
        $this->manager = new ConsentManager(
            $this->collectionFactory,
            $this->consentFactory,
            $this->consentResource,
            $this->consentLogFactory,
            $this->consentLogResource
        );
    }

    private function makeCollection(?CustomerConsent $consent): CustomerConsentCollection
    {
        $collection = $this->createStub(CustomerConsentCollection::class);
        $collection->method('addCustomerAndChannelFilter')->willReturnSelf();
        $collection->method('addCustomerFilter')->willReturnSelf();
        $collection->method('getFirstItem')->willReturn(
            $consent ?? $this->createStub(CustomerConsent::class)
        );
        $collection->method('getIterator')->willReturn(new \ArrayIterator($consent ? [$consent] : []));

        return $collection;
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testHasConsentReturnsTrueWhenNoRowExists(): void
    {
        $noRow = $this->createStub(CustomerConsent::class);
        $noRow->method('getId')->willReturn(null);
        $this->collectionFactory->method('create')->willReturn($this->makeCollection($noRow));

        self::assertTrue($this->manager->hasConsent(42, ConsentChannel::Email));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testHasConsentReturnsFalseWhenExplicitOptOutRowExists(): void
    {
        $row = $this->createStub(CustomerConsent::class);
        $row->method('getId')->willReturn(1);
        $row->method('isConsented')->willReturn(false);
        $this->collectionFactory->method('create')->willReturn($this->makeCollection($row));

        self::assertFalse($this->manager->hasConsent(42, ConsentChannel::Email));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testHasConsentReturnsTrueWhenExplicitOptInRowExists(): void
    {
        $row = $this->createStub(CustomerConsent::class);
        $row->method('getId')->willReturn(1);
        $row->method('isConsented')->willReturn(true);
        $this->collectionFactory->method('create')->willReturn($this->makeCollection($row));

        self::assertTrue($this->manager->hasConsent(42, ConsentChannel::Email));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSetConsentCreatesNewRowWhenNoneExists(): void
    {
        $noRow = $this->createStub(CustomerConsent::class);
        $noRow->method('getId')->willReturn(null);
        $this->collectionFactory->method('create')->willReturn($this->makeCollection($noRow));

        $newConsent = $this->createMock(CustomerConsent::class);
        $newConsent->expects(self::once())->method('setCustomerId')->with(42);
        $newConsent->expects(self::once())->method('setChannel')->with(ConsentChannel::Sms->value);
        $newConsent->expects(self::once())->method('setConsented')->with(false);
        $newConsent->expects(self::once())->method('setSource')->with('admin');
        $this->consentFactory->method('create')->willReturn($newConsent);
        $this->consentResource->expects(self::once())->method('save')->with($newConsent);

        $this->manager->setConsent(42, ConsentChannel::Sms, false, 'admin');
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSetConsentAlwaysAppendsALogRowRegardlessOfWhetherTheCurrentStateRowIsNewOrExisting(): void
    {
        $existing = $this->createStub(CustomerConsent::class);
        $existing->method('getId')->willReturn(5);
        $this->collectionFactory->method('create')->willReturn($this->makeCollection($existing));

        $logEntry = $this->createMock(CustomerConsentLog::class);
        $logEntry->expects(self::once())->method('setCustomerId')->with(42);
        $logEntry->expects(self::once())->method('setChannel')->with(ConsentChannel::Sms->value);
        $logEntry->expects(self::once())->method('setConsented')->with(true);
        $logEntry->expects(self::once())->method('setSource')->with('unsubscribe_link');
        $this->consentLogFactory = $this->createMock(CustomerConsentLogFactory::class);
        $this->consentLogFactory->method('create')->willReturn($logEntry);
        $this->manager = new ConsentManager(
            $this->collectionFactory,
            $this->consentFactory,
            $this->consentResource,
            $this->consentLogFactory,
            $this->consentLogResource
        );

        $this->consentLogResource->expects(self::once())->method('save')->with($logEntry);

        $this->manager->setConsent(42, ConsentChannel::Sms, true, 'unsubscribe_link');
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSetConsentNeverWritesALogRowBeforeTheStateRowItselfIsSaved(): void
    {
        $noRow = $this->createStub(CustomerConsent::class);
        $noRow->method('getId')->willReturn(null);
        $this->collectionFactory->method('create')->willReturn($this->makeCollection($noRow));
        $this->consentFactory->method('create')->willReturn($this->createStub(CustomerConsent::class));

        $callOrder = [];
        $this->consentResource->method('save')->willReturnCallback(function () use (&$callOrder) {
            $callOrder[] = 'state';
        });
        $this->consentLogResource->method('save')->willReturnCallback(function () use (&$callOrder) {
            $callOrder[] = 'log';
        });

        $this->manager->setConsent(42, ConsentChannel::Sms, false, 'admin');

        self::assertSame(['state', 'log'], $callOrder);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSetConsentUpdatesExistingRow(): void
    {
        $existing = $this->createMock(CustomerConsent::class);
        $existing->method('getId')->willReturn(5);
        $existing->expects(self::once())->method('setConsented')->with(true);
        $this->collectionFactory->method('create')->willReturn($this->makeCollection($existing));

        $this->consentFactory->expects(self::never())->method('create');
        $this->consentResource->expects(self::once())->method('save')->with($existing);

        $this->manager->setConsent(42, ConsentChannel::Sms, true, 'unsubscribe_link');
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testHasConsentForCustomersDefaultsToTrueForRowsWithNoExplicitOptOut(): void
    {
        $this->collectionFactory->method('create')->willReturn($this->makeCollection(null));

        self::assertSame(
            [1 => true, 2 => true],
            $this->manager->hasConsentForCustomers([1, 2], ConsentChannel::Email)
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testHasConsentForCustomersReflectsExplicitOptOutRow(): void
    {
        $row = $this->createStub(CustomerConsent::class);
        $row->method('getCustomerId')->willReturn(2);
        $row->method('isConsented')->willReturn(false);
        $this->collectionFactory->method('create')->willReturn($this->makeCollection($row));

        self::assertSame(
            [1 => true, 2 => false],
            $this->manager->hasConsentForCustomers([1, 2], ConsentChannel::Email)
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testHasConsentForCustomersReturnsEmptyArrayForEmptyInputWithoutQuerying(): void
    {
        $this->collectionFactory->expects(self::never())->method('create');

        self::assertSame([], $this->manager->hasConsentForCustomers([], ConsentChannel::Email));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetConsentStatesReturnsChannelMap(): void
    {
        $row = $this->createStub(CustomerConsent::class);
        $row->method('getChannel')->willReturn('sms');
        $row->method('isConsented')->willReturn(false);
        $this->collectionFactory->method('create')->willReturn($this->makeCollection($row));

        self::assertSame(['sms' => false], $this->manager->getConsentStates(42));
    }
}
