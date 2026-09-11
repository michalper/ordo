<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Cron;

use Magento\Customer\Api\Data\CustomerInterface;
use Ordo\Automation\Api\AdAudience\SyncClientInterface;
use Ordo\Automation\Cron\SyncAdAudiences;
use Ordo\Automation\Model\AdAudience;
use Ordo\Automation\Model\AdAudience\PiiHasher;
use Ordo\Automation\Model\AdAudience\SyncClientPool;
use Ordo\Automation\Model\ConsentChannel;
use Ordo\Automation\Model\ConsentManager;
use Ordo\Automation\Model\Cron\CronRunLogger;
use Ordo\Automation\Model\CustomerMapBuilder;
use Ordo\Automation\Model\ResourceModel\AdAudience as AdAudienceResource;
use Ordo\Automation\Model\ResourceModel\AdAudience\Collection as AdAudienceCollection;
use Ordo\Automation\Model\ResourceModel\AdAudience\CollectionFactory as AdAudienceCollectionFactory;
use Ordo\Automation\Model\Segment\SegmentMemberResolver;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Ordo\Automation\Test\Unit\Cron\MakesCronRunLoggerTrait;

class SyncAdAudiencesTest extends TestCase
{
    use MakesCronRunLoggerTrait;

    private AdAudienceCollectionFactory&\PHPUnit\Framework\MockObject\MockObject $collectionFactory;
    private AdAudienceResource&\PHPUnit\Framework\MockObject\MockObject $adAudienceResource;
    private SegmentMemberResolver&\PHPUnit\Framework\MockObject\MockObject $segmentMemberResolver;
    private CustomerMapBuilder&\PHPUnit\Framework\MockObject\MockObject $customerMapBuilder;
    private SyncClientPool&\PHPUnit\Framework\MockObject\MockObject $syncClientPool;
    private ConsentManager&\PHPUnit\Framework\MockObject\MockObject $consentManager;
    private LoggerInterface&\PHPUnit\Framework\MockObject\MockObject $logger;
    private SyncAdAudiences $cron;

    protected function setUp(): void
    {
        $this->collectionFactory = $this->createMock(AdAudienceCollectionFactory::class);
        $this->adAudienceResource = $this->createMock(AdAudienceResource::class);
        $this->segmentMemberResolver = $this->createMock(SegmentMemberResolver::class);
        $this->customerMapBuilder = $this->createMock(CustomerMapBuilder::class);
        $this->syncClientPool = $this->createMock(SyncClientPool::class);
        $this->consentManager = $this->createMock(ConsentManager::class);
        $this->consentManager->method('hasConsent')->willReturn(true);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->cron = new SyncAdAudiences(
            $this->collectionFactory,
            $this->adAudienceResource,
            $this->segmentMemberResolver,
            $this->customerMapBuilder,
            new PiiHasher(),
            $this->syncClientPool,
            $this->consentManager,
            $this->makeCronRunLogger($this->createStub(LoggerInterface::class)),
            $this->logger
        );
    }

    private function makeCollection(array $rows): AdAudienceCollection
    {
        $collection = $this->createStub(AdAudienceCollection::class);
        $collection->method('addEnabledFilter')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator($rows));

        return $collection;
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSyncsEnabledRowAndRecordsSuccess(): void
    {
        $adAudience = $this->createMock(AdAudience::class);
        $adAudience->method('getEntityId')->willReturn(1);
        $adAudience->method('getPlatform')->willReturn('google_ads');
        $adAudience->method('getSegmentId')->willReturn(5);
        $adAudience->method('getExternalAudienceId')->willReturn('existing-list');
        $this->collectionFactory->method('create')->willReturn($this->makeCollection([$adAudience]));

        $this->segmentMemberResolver->expects(self::once())->method('getMatchingCustomerIds')->with(5)
            ->willReturn([42]);

        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getEmail')->willReturn('jan@example.com');
        $this->customerMapBuilder->expects(self::once())->method('build')->with([42])
            ->willReturn([42 => $customer]);

        $client = $this->createMock(SyncClientInterface::class);
        $client->expects(self::once())->method('sync')
            ->with('existing-list', [(new PiiHasher())->hashEmail('jan@example.com')])
            ->willReturn(null);
        $this->syncClientPool->expects(self::once())->method('get')->with('google_ads')->willReturn($client);

        $adAudience->expects(self::once())->method('setLastSyncStatus')->with(AdAudience::STATUS_SUCCESS);
        $adAudience->expects(self::never())->method('setExternalAudienceId');
        $this->adAudienceResource->expects(self::once())->method('save')->with($adAudience);

        $this->cron->execute();
    }

    /**
     * Regression test for a real consent-bypass bug a code audit found: this cron used to have no
     * ConsentManager check at all, so a customer who withdrew ad-sharing consent still had their
     * (hashed) email uploaded to the ad platform.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteExcludesCustomerWhoWithdrewAdsConsent(): void
    {
        $adAudience = $this->createMock(AdAudience::class);
        $adAudience->method('getEntityId')->willReturn(1);
        $adAudience->method('getPlatform')->willReturn('google_ads');
        $adAudience->method('getSegmentId')->willReturn(5);
        $adAudience->method('getExternalAudienceId')->willReturn('existing-list');
        $this->collectionFactory->method('create')->willReturn($this->makeCollection([$adAudience]));

        $this->segmentMemberResolver->method('getMatchingCustomerIds')->willReturn([42, 43]);

        $consentedCustomer = $this->createStub(CustomerInterface::class);
        $consentedCustomer->method('getEmail')->willReturn('jan@example.com');
        $optedOutCustomer = $this->createStub(CustomerInterface::class);
        $optedOutCustomer->method('getEmail')->willReturn('opted-out@example.com');
        $this->customerMapBuilder->method('build')->willReturn([42 => $consentedCustomer, 43 => $optedOutCustomer]);

        $this->consentManager = $this->createMock(ConsentManager::class);
        $this->consentManager->method('hasConsent')->willReturnMap([
            [42, ConsentChannel::Ads, true],
            [43, ConsentChannel::Ads, false],
        ]);
        $this->cron = new SyncAdAudiences(
            $this->collectionFactory,
            $this->adAudienceResource,
            $this->segmentMemberResolver,
            $this->customerMapBuilder,
            new PiiHasher(),
            $this->syncClientPool,
            $this->consentManager,
            $this->makeCronRunLogger($this->createStub(LoggerInterface::class)),
            $this->logger
        );

        // Only the consented customer's hashed email reaches the sync client - the opted-out
        // one's is never even hashed.
        $client = $this->createMock(SyncClientInterface::class);
        $client->expects(self::once())->method('sync')
            ->with('existing-list', [(new PiiHasher())->hashEmail('jan@example.com')])
            ->willReturn(null);
        $this->syncClientPool->method('get')->willReturn($client);

        $this->cron->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteStoresNewlyCreatedExternalAudienceId(): void
    {
        $adAudience = $this->createMock(AdAudience::class);
        $adAudience->method('getEntityId')->willReturn(1);
        $adAudience->method('getPlatform')->willReturn('meta');
        $adAudience->method('getSegmentId')->willReturn(5);
        $adAudience->method('getExternalAudienceId')->willReturn(null);
        $this->collectionFactory->method('create')->willReturn($this->makeCollection([$adAudience]));

        $this->segmentMemberResolver->method('getMatchingCustomerIds')->willReturn([]);
        $this->customerMapBuilder->method('build')->willReturn([]);

        $client = $this->createMock(SyncClientInterface::class);
        $client->method('sync')->willReturn('brand-new-id');
        $this->syncClientPool->method('get')->willReturn($client);

        $adAudience->expects(self::once())->method('setExternalAudienceId')->with('brand-new-id');
        $this->adAudienceResource->expects(self::once())->method('save');

        $this->cron->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRecordsErrorAndContinuesWhenNoClientRegisteredForPlatform(): void
    {
        $adAudience = $this->createMock(AdAudience::class);
        $adAudience->method('getEntityId')->willReturn(1);
        $adAudience->method('getPlatform')->willReturn('unknown_platform');
        $this->collectionFactory->method('create')->willReturn($this->makeCollection([$adAudience]));

        $this->syncClientPool->method('get')->willReturn(null);
        $this->logger->expects(self::once())->method('error');

        $adAudience->expects(self::once())->method('setLastSyncStatus')->with(AdAudience::STATUS_ERROR);
        $this->adAudienceResource->expects(self::once())->method('save')->with($adAudience);

        $this->cron->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSkipsCustomersMissingFromTheBatchLookup(): void
    {
        $adAudience = $this->createMock(AdAudience::class);
        $adAudience->method('getEntityId')->willReturn(1);
        $adAudience->method('getPlatform')->willReturn('google_ads');
        $adAudience->method('getSegmentId')->willReturn(5);
        $adAudience->method('getExternalAudienceId')->willReturn('existing-list');
        $this->collectionFactory->method('create')->willReturn($this->makeCollection([$adAudience]));

        $this->segmentMemberResolver->method('getMatchingCustomerIds')->willReturn([42, 43]);

        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getEmail')->willReturn('jan@example.com');
        // #43 simply absent from the batch lookup result - same "not found" case getById()'s own
        // catch used to handle, now expressed as CustomerMapBuilder just not returning that id.
        $this->customerMapBuilder->method('build')->willReturn([42 => $customer]);

        $client = $this->createMock(SyncClientInterface::class);
        $client->expects(self::once())->method('sync')
            ->with('existing-list', [(new PiiHasher())->hashEmail('jan@example.com')])
            ->willReturn(null);
        $this->syncClientPool->method('get')->willReturn($client);
        $this->adAudienceResource->method('save');

        $this->cron->execute();
    }
}
