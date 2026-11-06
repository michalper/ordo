<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Ordo\Automation\Model\ConsentChannel;
use Ordo\Automation\Model\ConsentManager;
use Ordo\Automation\Model\ConsentTopic;
use Ordo\Automation\Model\CustomerConsent;
use Ordo\Automation\Model\CustomerConsentFactory;
use Ordo\Automation\Model\CustomerConsentLog;
use Ordo\Automation\Model\CustomerConsentLogFactory;
use Ordo\Automation\Model\CustomerConsentTopic;
use Ordo\Automation\Model\CustomerConsentTopicFactory;
use Ordo\Automation\Model\ResourceModel\CustomerConsent as CustomerConsentResource;
use Ordo\Automation\Model\ResourceModel\CustomerConsent\Collection as CustomerConsentCollection;
use Ordo\Automation\Model\ResourceModel\CustomerConsent\CollectionFactory as CustomerConsentCollectionFactory;
use Ordo\Automation\Model\ResourceModel\CustomerConsentLog as CustomerConsentLogResource;
use Ordo\Automation\Model\ResourceModel\CustomerConsentTopic as CustomerConsentTopicResource;
use Ordo\Automation\Model\ResourceModel\CustomerConsentTopic\Collection as CustomerConsentTopicCollection;
use Ordo\Automation\Model\ResourceModel\CustomerConsentTopic\CollectionFactory as CustomerConsentTopicCollectionFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class ConsentManagerTest extends TestCase
{
    private CustomerConsentCollectionFactory&\PHPUnit\Framework\MockObject\MockObject $collectionFactory;
    private CustomerConsentFactory&\PHPUnit\Framework\MockObject\MockObject $consentFactory;
    private CustomerConsentResource&\PHPUnit\Framework\MockObject\MockObject $consentResource;
    private CustomerConsentLogFactory&\PHPUnit\Framework\MockObject\MockObject $consentLogFactory;
    private CustomerConsentLogResource&\PHPUnit\Framework\MockObject\MockObject $consentLogResource;
    private CustomerConsentTopicCollectionFactory&\PHPUnit\Framework\MockObject\MockObject $topicCollectionFactory;
    private CustomerConsentTopicFactory&\PHPUnit\Framework\MockObject\MockObject $topicFactory;
    private CustomerConsentTopicResource&\PHPUnit\Framework\MockObject\MockObject $topicResource;
    private ConsentManager $manager;

    protected function setUp(): void
    {
        $this->collectionFactory = $this->createMock(CustomerConsentCollectionFactory::class);
        $this->consentFactory = $this->createMock(CustomerConsentFactory::class);
        $this->consentResource = $this->createMock(CustomerConsentResource::class);
        $this->consentLogFactory = $this->createMock(CustomerConsentLogFactory::class);
        $this->consentLogResource = $this->createMock(CustomerConsentLogResource::class);
        $this->consentLogFactory->method('create')->willReturn($this->createStub(CustomerConsentLog::class));
        $this->topicCollectionFactory = $this->createMock(CustomerConsentTopicCollectionFactory::class);
        $this->topicFactory = $this->createMock(CustomerConsentTopicFactory::class);
        $this->topicResource = $this->createMock(CustomerConsentTopicResource::class);
        $this->manager = $this->makeManager();
    }

    private function makeManager(): ConsentManager
    {
        return new ConsentManager(
            $this->collectionFactory,
            $this->consentFactory,
            $this->consentResource,
            $this->consentLogFactory,
            $this->consentLogResource,
            $this->topicCollectionFactory,
            $this->topicFactory,
            $this->topicResource
        );
    }

    private function makeCollection(?CustomerConsent $consent): CustomerConsentCollection
    {
        $collection = $this->createStub(CustomerConsentCollection::class);
        $collection->method('addCustomerAndChannelFilter')->willReturnSelf();
        $collection->method('addCustomerFilter')->willReturnSelf();
        $collection->method('addCustomerIdsAndChannelFilter')->willReturnSelf();
        $collection->method('getFirstItem')->willReturn(
            $consent ?? $this->createStub(CustomerConsent::class)
        );
        $collection->method('getIterator')->willReturn(new \ArrayIterator($consent ? [$consent] : []));

        return $collection;
    }

    private function makeTopicCollection(?CustomerConsentTopic $consent): CustomerConsentTopicCollection
    {
        $collection = $this->createStub(CustomerConsentTopicCollection::class);
        $collection->method('addCustomerAndChannelFilter')->willReturnSelf();
        $collection->method('addCustomerChannelAndTopicFilter')->willReturnSelf();
        $collection->method('addCustomerIdsChannelAndTopicFilter')->willReturnSelf();
        $collection->method('getFirstItem')->willReturn(
            $consent ?? $this->createStub(CustomerConsentTopic::class)
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
        $this->manager = $this->makeManager();

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

    #[AllowMockObjectsWithoutExpectations]
    public function testHasTopicConsentReturnsFalseWhenChannelItselfIsOptedOut(): void
    {
        $channelOptOut = $this->createStub(CustomerConsent::class);
        $channelOptOut->method('getId')->willReturn(1);
        $channelOptOut->method('isConsented')->willReturn(false);
        $this->collectionFactory->method('create')->willReturn($this->makeCollection($channelOptOut));

        $this->topicCollectionFactory->expects(self::never())->method('create');

        self::assertFalse($this->manager->hasTopicConsent(42, ConsentChannel::Email, ConsentTopic::Promotional));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testHasTopicConsentReturnsTrueWhenChannelOptedInAndNoTopicRowExists(): void
    {
        $this->collectionFactory->method('create')->willReturn($this->makeCollection(null));

        $noTopicRow = $this->createStub(CustomerConsentTopic::class);
        $noTopicRow->method('getId')->willReturn(null);
        $this->topicCollectionFactory->method('create')->willReturn($this->makeTopicCollection($noTopicRow));

        self::assertTrue($this->manager->hasTopicConsent(42, ConsentChannel::Email, ConsentTopic::Promotional));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testHasTopicConsentReturnsFalseWhenChannelOptedInButTopicExplicitlyOptedOut(): void
    {
        $this->collectionFactory->method('create')->willReturn($this->makeCollection(null));

        $topicRow = $this->createStub(CustomerConsentTopic::class);
        $topicRow->method('getId')->willReturn(1);
        $topicRow->method('isConsented')->willReturn(false);
        $this->topicCollectionFactory->method('create')->willReturn($this->makeTopicCollection($topicRow));

        self::assertFalse($this->manager->hasTopicConsent(42, ConsentChannel::Email, ConsentTopic::Promotional));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSetTopicConsentCreatesNewRowAndLogsWithTopicSet(): void
    {
        $noRow = $this->createStub(CustomerConsentTopic::class);
        $noRow->method('getId')->willReturn(null);
        $this->topicCollectionFactory->method('create')->willReturn($this->makeTopicCollection($noRow));

        $newTopicConsent = $this->createMock(CustomerConsentTopic::class);
        $newTopicConsent->expects(self::once())->method('setCustomerId')->with(42);
        $newTopicConsent->expects(self::once())->method('setChannel')->with(ConsentChannel::Email->value);
        $newTopicConsent->expects(self::once())->method('setTopic')->with(ConsentTopic::Promotional->value);
        $newTopicConsent->expects(self::once())->method('setConsented')->with(false);
        $this->topicFactory->method('create')->willReturn($newTopicConsent);
        $this->topicResource->expects(self::once())->method('save')->with($newTopicConsent);

        $logEntry = $this->createMock(CustomerConsentLog::class);
        $logEntry->expects(self::once())->method('setChannel')->with(ConsentChannel::Email->value);
        $logEntry->expects(self::once())->method('setTopic')->with(ConsentTopic::Promotional->value);
        $logEntry->expects(self::once())->method('setConsented')->with(false);
        $this->consentLogFactory = $this->createMock(CustomerConsentLogFactory::class);
        $this->consentLogFactory->method('create')->willReturn($logEntry);
        $this->manager = $this->makeManager();
        $this->consentLogResource->expects(self::once())->method('save')->with($logEntry);

        $this->manager->setTopicConsent(42, ConsentChannel::Email, ConsentTopic::Promotional, false, 'admin');
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetTopicConsentStatesReturnsTopicMap(): void
    {
        $row = $this->createStub(CustomerConsentTopic::class);
        $row->method('getTopic')->willReturn(ConsentTopic::AccountUpdates->value);
        $row->method('isConsented')->willReturn(false);
        $this->topicCollectionFactory->method('create')->willReturn($this->makeTopicCollection($row));

        self::assertSame(
            [ConsentTopic::AccountUpdates->value => false],
            $this->manager->getTopicConsentStates(42, ConsentChannel::Email)
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testHasTopicConsentForCustomersCombinesChannelAndTopicRegisters(): void
    {
        $channelOptOut = $this->createStub(CustomerConsent::class);
        $channelOptOut->method('getCustomerId')->willReturn(3);
        $channelOptOut->method('isConsented')->willReturn(false);
        $this->collectionFactory->method('create')->willReturn($this->makeCollection($channelOptOut));

        $topicOptOut = $this->createStub(CustomerConsentTopic::class);
        $topicOptOut->method('getCustomerId')->willReturn(2);
        $topicOptOut->method('isConsented')->willReturn(false);
        $this->topicCollectionFactory->method('create')->willReturn($this->makeTopicCollection($topicOptOut));

        self::assertSame(
            [1 => true, 2 => false, 3 => false],
            $this->manager->hasTopicConsentForCustomers([1, 2, 3], ConsentChannel::Email, ConsentTopic::Promotional)
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testHasTopicConsentForCustomersReturnsEmptyArrayForEmptyInputWithoutQueryingTopics(): void
    {
        $this->collectionFactory->method('create')->willReturn($this->makeCollection(null));
        $this->topicCollectionFactory->expects(self::never())->method('create');

        self::assertSame(
            [],
            $this->manager->hasTopicConsentForCustomers([], ConsentChannel::Email, ConsentTopic::Promotional)
        );
    }
}
