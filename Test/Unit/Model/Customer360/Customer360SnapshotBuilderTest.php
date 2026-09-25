<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Customer360;

use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Ordo\Automation\Api\CustomerTagManagementInterface;
use Ordo\Automation\Model\CustomerScoreManager;
use Ordo\Automation\Model\Customer360\Customer360SnapshotBuilder;
use Ordo\Automation\Model\LoyaltyTierCalculator;
use Ordo\Automation\Model\ResourceModel\Segment\Collection as SegmentCollection;
use Ordo\Automation\Model\ResourceModel\Segment\CollectionFactory as SegmentCollectionFactory;
use Ordo\Automation\Model\ResourceModel\SurveyPrompt\Collection as SurveyPromptCollection;
use Ordo\Automation\Model\ResourceModel\SurveyPrompt\CollectionFactory as SurveyPromptCollectionFactory;
use Ordo\Automation\Model\Rfm\RfmCalculator;
use Ordo\Automation\Model\Segment;
use Ordo\Automation\Model\Segment\SegmentMatcher;
use Ordo\Automation\Model\SurveyPrompt;
use Ordo\Automation\Test\Unit\Controller\MakesRealCollectionTrait;
use PHPUnit\Framework\TestCase;

class Customer360SnapshotBuilderTest extends TestCase
{
    use MakesRealCollectionTrait;

    private function makeResourceConnection(int $orderCount, float $orderTotal): ResourceConnection
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchRow')->willReturn(['order_count' => $orderCount, 'order_total' => $orderTotal]);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        return $resourceConnection;
    }

    public function testBuildAggregatesEveryDataSourceIntoOneSnapshot(): void
    {
        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getId')->willReturn(42);
        $customer->method('getEmail')->willReturn('jan@example.com');
        $customer->method('getFirstname')->willReturn('Jan');
        $customer->method('getLastname')->willReturn('Kowalski');

        $customerScoreManager = $this->createStub(CustomerScoreManager::class);
        $customerScoreManager->method('getScore')->willReturn(150);

        $loyaltyTierCalculator = $this->createStub(LoyaltyTierCalculator::class);
        $loyaltyTierCalculator->method('getTierForScore')->willReturn(LoyaltyTierCalculator::SILVER);
        $loyaltyTierCalculator->method('getTierLabel')->willReturn('Silver');

        $rfmCalculator = $this->createStub(RfmCalculator::class);
        $rfmCalculator->method('getRecencyDays')->willReturn(5);
        $rfmCalculator->method('getFrequency')->willReturn(3);
        $rfmCalculator->method('getMonetaryTotal')->willReturn(199.99);
        $rfmCalculator->method('getRfmScoreLabel')->willReturn('4-3-3');

        $customerTagManager = $this->createStub(CustomerTagManagementInterface::class);
        $customerTagManager->method('getTags')->willReturn(['vip', 'wholesale']);

        $prompt = $this->createStub(SurveyPrompt::class);
        $prompt->method('getScore')->willReturn(9);
        $surveyPromptCollection = $this->createStub(SurveyPromptCollection::class);
        $surveyPromptCollection->method('addLatestResponseFilter')->willReturnSelf();
        $surveyPromptCollection->method('getFirstItem')->willReturn($prompt);
        $surveyPromptCollectionFactory = $this->createStub(SurveyPromptCollectionFactory::class);
        $surveyPromptCollectionFactory->method('create')->willReturn($surveyPromptCollection);

        $matchingSegment = $this->createStub(Segment::class);
        $matchingSegment->method('getId')->willReturn(5);
        $matchingSegment->method('getName')->willReturn('High spenders');
        $nonMatchingSegment = $this->createStub(Segment::class);
        $nonMatchingSegment->method('getId')->willReturn(6);
        $nonMatchingSegment->method('getName')->willReturn('New customers');

        $segmentCollection = $this->makeRealCollection(SegmentCollection::class, 'ordo_segment');
        $segmentCollection->addItem($matchingSegment);
        $segmentCollection->addItem($nonMatchingSegment);
        $segmentCollectionFactory = $this->createStub(SegmentCollectionFactory::class);
        $segmentCollectionFactory->method('create')->willReturn($segmentCollection);

        $segmentMatcher = $this->createStub(SegmentMatcher::class);
        $segmentMatcher->method('isCustomerInSegment')->willReturnMap([
            [5, 42, [], true],
            [6, 42, [], false],
        ]);

        $builder = new Customer360SnapshotBuilder(
            $customerScoreManager,
            $loyaltyTierCalculator,
            $rfmCalculator,
            $customerTagManager,
            $surveyPromptCollectionFactory,
            $segmentCollectionFactory,
            $segmentMatcher,
            $this->makeResourceConnection(3, 199.99)
        );

        $snapshot = $builder->build($customer);

        self::assertSame(42, $snapshot->customerId);
        self::assertSame('jan@example.com', $snapshot->email);
        self::assertSame('Jan Kowalski', $snapshot->name);
        self::assertSame(150, $snapshot->leadScore);
        self::assertSame('Silver', $snapshot->loyaltyTier);
        self::assertSame(5, $snapshot->recencyDays);
        self::assertSame(3, $snapshot->orderFrequency);
        self::assertSame(199.99, $snapshot->monetaryTotal);
        self::assertSame('4-3-3', $snapshot->rfmScoreLabel);
        self::assertSame(['vip', 'wholesale'], $snapshot->tags);
        self::assertSame(9, $snapshot->npsScore);
        self::assertSame(['High spenders'], $snapshot->matchingSegmentNames);
        self::assertSame(3, $snapshot->orderCount);
        self::assertSame(199.99, $snapshot->orderTotal);
    }

    public function testBuildHandlesACustomerWithNoOrdersOrDataGracefully(): void
    {
        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getId')->willReturn(7);
        $customer->method('getEmail')->willReturn('new@example.com');
        $customer->method('getFirstname')->willReturn('');
        $customer->method('getLastname')->willReturn('');

        $customerScoreManager = $this->createStub(CustomerScoreManager::class);
        $customerScoreManager->method('getScore')->willReturn(0);

        $loyaltyTierCalculator = $this->createStub(LoyaltyTierCalculator::class);
        $loyaltyTierCalculator->method('getTierForScore')->willReturn(LoyaltyTierCalculator::BRONZE);
        $loyaltyTierCalculator->method('getTierLabel')->willReturn('Bronze');

        $rfmCalculator = $this->createStub(RfmCalculator::class);
        $rfmCalculator->method('getRecencyDays')->willReturn(null);
        $rfmCalculator->method('getFrequency')->willReturn(0);
        $rfmCalculator->method('getMonetaryTotal')->willReturn(0.0);
        $rfmCalculator->method('getRfmScoreLabel')->willReturn(null);

        $customerTagManager = $this->createStub(CustomerTagManagementInterface::class);
        $customerTagManager->method('getTags')->willReturn([]);

        $emptyPrompt = $this->createStub(SurveyPrompt::class);
        $emptyPrompt->method('getScore')->willReturn(null);
        $surveyPromptCollection = $this->createStub(SurveyPromptCollection::class);
        $surveyPromptCollection->method('addLatestResponseFilter')->willReturnSelf();
        $surveyPromptCollection->method('getFirstItem')->willReturn($emptyPrompt);
        $surveyPromptCollectionFactory = $this->createStub(SurveyPromptCollectionFactory::class);
        $surveyPromptCollectionFactory->method('create')->willReturn($surveyPromptCollection);

        $segmentCollection = $this->makeRealCollection(SegmentCollection::class, 'ordo_segment');
        $segmentCollectionFactory = $this->createStub(SegmentCollectionFactory::class);
        $segmentCollectionFactory->method('create')->willReturn($segmentCollection);

        $segmentMatcher = $this->createStub(SegmentMatcher::class);

        $builder = new Customer360SnapshotBuilder(
            $customerScoreManager,
            $loyaltyTierCalculator,
            $rfmCalculator,
            $customerTagManager,
            $surveyPromptCollectionFactory,
            $segmentCollectionFactory,
            $segmentMatcher,
            $this->makeResourceConnection(0, 0.0)
        );

        $snapshot = $builder->build($customer);

        self::assertSame('', $snapshot->name);
        self::assertNull($snapshot->recencyDays);
        self::assertNull($snapshot->rfmScoreLabel);
        self::assertNull($snapshot->npsScore);
        self::assertSame([], $snapshot->tags);
        self::assertSame([], $snapshot->matchingSegmentNames);
        self::assertSame(0, $snapshot->orderCount);
        self::assertSame(0.0, $snapshot->orderTotal);
    }
}
