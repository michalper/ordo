<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ScoreRule;

use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Api\AttributeInterface;
use Ordo\Automation\Model\CustomerStoreResolver;
use Ordo\Automation\Model\ResourceModel\ScoreRule\Collection as ScoreRuleCollection;
use Ordo\Automation\Model\ResourceModel\ScoreRule\CollectionFactory as ScoreRuleCollectionFactory;
use Ordo\Automation\Model\ResourceModel\ScoreRule\Website\Collection as ScoreRuleWebsiteCollection;
use Ordo\Automation\Model\ResourceModel\ScoreRule\Website\CollectionFactory as ScoreRuleWebsiteCollectionFactory;
use Ordo\Automation\Model\ScoreRule;
use Ordo\Automation\Model\ScoreRule\ScoreRuleEvaluator;
use Ordo\Automation\Model\ScoreRuleWebsite;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class ScoreRuleEvaluatorTest extends TestCase
{
    private ScoreRuleCollectionFactory $collectionFactory;
    private ScoreRuleWebsiteCollectionFactory $scoreRuleWebsiteCollectionFactory;
    private CustomerStoreResolver $customerStoreResolver;
    private int $ruleIdSequence = 1;

    protected function setUp(): void
    {
        $this->collectionFactory = $this->createMock(ScoreRuleCollectionFactory::class);
        $this->scoreRuleWebsiteCollectionFactory = $this->createStub(ScoreRuleWebsiteCollectionFactory::class);
        $this->scoreRuleWebsiteCollectionFactory->method('create')->willReturn($this->makeWebsiteCollection([]));
        $this->customerStoreResolver = $this->createStub(CustomerStoreResolver::class);
    }

    /**
     * @param ScoreRuleWebsite[] $rows
     */
    private function makeWebsiteCollection(array $rows): ScoreRuleWebsiteCollection
    {
        $collection = $this->createStub(ScoreRuleWebsiteCollection::class);
        $collection->method('addScoreRuleIdsFilter')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator($rows));
        return $collection;
    }

    private function makeWebsiteRow(int $scoreRuleId, int $websiteId): ScoreRuleWebsite
    {
        $row = $this->createStub(ScoreRuleWebsite::class);
        $row->method('getScoreRuleId')->willReturn($scoreRuleId);
        $row->method('getWebsiteId')->willReturn($websiteId);
        return $row;
    }

    private function makeRule(string $attributeCode, string $operator, string $value, int $points): ScoreRule
    {
        $rule = $this->createStub(ScoreRule::class);
        $rule->method('getEntityId')->willReturn($this->ruleIdSequence++);
        $rule->method('getAttributeCode')->willReturn($attributeCode);
        $rule->method('getOperator')->willReturn($operator);
        $rule->method('getValue')->willReturn($value);
        $rule->method('getPoints')->willReturn($points);

        return $rule;
    }

    /**
     * @param ScoreRule[] $rules
     */
    private function makeEvaluator(array $rules): ScoreRuleEvaluator
    {
        $collection = $this->createStub(ScoreRuleCollection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator($rules));

        $this->collectionFactory->method('create')->willReturn($collection);

        return new ScoreRuleEvaluator(
            $this->collectionFactory,
            $this->scoreRuleWebsiteCollectionFactory,
            $this->customerStoreResolver
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testEqualsOperatorMatches(): void
    {
        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getGroupId')->willReturn(1);

        $evaluator = $this->makeEvaluator([$this->makeRule('group_id', 'equals', '1', 10)]);

        self::assertSame(10, $evaluator->getMatchingRulePoints($customer));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testNotEqualsOperatorMatches(): void
    {
        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getGroupId')->willReturn(1);

        $evaluator = $this->makeEvaluator([$this->makeRule('group_id', 'not_equals', '2', 5)]);

        self::assertSame(5, $evaluator->getMatchingRulePoints($customer));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testContainsOperatorMatches(): void
    {
        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getEmail')->willReturn('someone@example.com');

        $evaluator = $this->makeEvaluator([$this->makeRule('email', 'contains', '@example.com', 20)]);

        self::assertSame(20, $evaluator->getMatchingRulePoints($customer));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testCoreGetterUsedForKnownAttribute(): void
    {
        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getWebsiteId')->willReturn(3);

        $evaluator = $this->makeEvaluator([$this->makeRule('website_id', 'equals', '3', 7)]);

        self::assertSame(7, $evaluator->getMatchingRulePoints($customer));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testCoreGetterUsedForStoreIdAttribute(): void
    {
        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getStoreId')->willReturn(2);

        $evaluator = $this->makeEvaluator([$this->makeRule('store_id', 'equals', '2', 4)]);

        self::assertSame(4, $evaluator->getMatchingRulePoints($customer));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testEavCustomAttributeFallbackUsedForUnknownAttribute(): void
    {
        $customAttribute = $this->createStub(AttributeInterface::class);
        $customAttribute->method('getValue')->willReturn('gold');

        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getCustomAttribute')->willReturn($customAttribute);

        $evaluator = $this->makeEvaluator([$this->makeRule('tier', 'equals', 'gold', 15)]);

        self::assertSame(15, $evaluator->getMatchingRulePoints($customer));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testRuleOnNonexistentAttributeNeverMatches(): void
    {
        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getCustomAttribute')->willReturn(null);

        $evaluator = $this->makeEvaluator([$this->makeRule('does_not_exist', 'equals', 'x', 99)]);

        self::assertSame(0, $evaluator->getMatchingRulePoints($customer));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testNotEqualsOperatorMatchesWhenTheAttributeIsEntirelyMissing(): void
    {
        // Regression: a customer with no "tier" custom attribute at all is just as much
        // "not equal to gold" as one whose tier is silver - a "tier not_equals gold" rule is
        // meant to catch both, not only the second.
        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getCustomAttribute')->willReturn(null);

        $evaluator = $this->makeEvaluator([$this->makeRule('tier', 'not_equals', 'gold', 8)]);

        self::assertSame(8, $evaluator->getMatchingRulePoints($customer));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testMultipleMatchingRulesSumPoints(): void
    {
        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getGroupId')->willReturn(1);
        $customer->method('getEmail')->willReturn('someone@example.com');

        $evaluator = $this->makeEvaluator([
            $this->makeRule('group_id', 'equals', '1', 10),
            $this->makeRule('email', 'contains', '@example.com', 5),
        ]);

        self::assertSame(15, $evaluator->getMatchingRulePoints($customer));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testOnlyEnabledRulesAreLoadedInTheFirstPlace(): void
    {
        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getGroupId')->willReturn(1);

        $collection = $this->createMock(ScoreRuleCollection::class);
        $collection->expects(self::once())->method('addFieldToFilter')->with('enabled', 1)->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator([
            $this->makeRule('group_id', 'equals', '1', 10),
        ]));

        $this->collectionFactory->method('create')->willReturn($collection);

        $evaluator = new ScoreRuleEvaluator(
            $this->collectionFactory,
            $this->scoreRuleWebsiteCollectionFactory,
            $this->customerStoreResolver
        );

        self::assertSame(10, $evaluator->getMatchingRulePoints($customer));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testReturnsZeroWithoutQueryingWebsitesWhenNoRulesAreEnabled(): void
    {
        $customer = $this->createStub(CustomerInterface::class);

        $this->scoreRuleWebsiteCollectionFactory = $this->createMock(ScoreRuleWebsiteCollectionFactory::class);
        $this->scoreRuleWebsiteCollectionFactory->expects(self::never())->method('create');

        $evaluator = $this->makeEvaluator([]);

        self::assertSame(0, $evaluator->getMatchingRulePoints($customer));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testWebsiteScopedRuleMatchesWhenTheCustomersWebsiteIsIncluded(): void
    {
        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getGroupId')->willReturn(1);

        $rule = $this->makeRule('group_id', 'equals', '1', 10);
        $ruleId = (int) $rule->getEntityId();

        // A fresh stub, not a second ->method('create') on setUp()'s own - PHPUnit matches
        // configured behaviors in configuration order for an unlimited (any()) matcher, so a
        // second willReturn() on the same double never overrides the first.
        $this->scoreRuleWebsiteCollectionFactory = $this->createStub(ScoreRuleWebsiteCollectionFactory::class);
        $this->scoreRuleWebsiteCollectionFactory->method('create')
            ->willReturn($this->makeWebsiteCollection([$this->makeWebsiteRow($ruleId, 5)]));
        $this->customerStoreResolver = $this->createStub(CustomerStoreResolver::class);
        $this->customerStoreResolver->method('resolveWebsiteId')->willReturn(5);

        $evaluator = $this->makeEvaluator([$rule]);

        self::assertSame(10, $evaluator->getMatchingRulePoints($customer));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testWebsiteScopedRuleIsSkippedWhenTheCustomerIsOnADifferentWebsite(): void
    {
        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getGroupId')->willReturn(1);

        $rule = $this->makeRule('group_id', 'equals', '1', 10);
        $ruleId = (int) $rule->getEntityId();

        // A fresh stub, not a second ->method('create') on setUp()'s own - same reasoning as
        // the matching-website test above.
        $this->scoreRuleWebsiteCollectionFactory = $this->createStub(ScoreRuleWebsiteCollectionFactory::class);
        $this->scoreRuleWebsiteCollectionFactory->method('create')
            ->willReturn($this->makeWebsiteCollection([$this->makeWebsiteRow($ruleId, 5)]));
        $this->customerStoreResolver = $this->createStub(CustomerStoreResolver::class);
        $this->customerStoreResolver->method('resolveWebsiteId')->willReturn(7);

        $evaluator = $this->makeEvaluator([$rule]);

        self::assertSame(0, $evaluator->getMatchingRulePoints($customer));
    }
}
