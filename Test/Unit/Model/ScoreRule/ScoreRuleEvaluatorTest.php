<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ScoreRule;

use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Api\AttributeInterface;
use Ordo\Automation\Model\ResourceModel\ScoreRule\Collection as ScoreRuleCollection;
use Ordo\Automation\Model\ResourceModel\ScoreRule\CollectionFactory as ScoreRuleCollectionFactory;
use Ordo\Automation\Model\ScoreRule;
use Ordo\Automation\Model\ScoreRule\ScoreRuleEvaluator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class ScoreRuleEvaluatorTest extends TestCase
{
    private ScoreRuleCollectionFactory $collectionFactory;

    protected function setUp(): void
    {
        $this->collectionFactory = $this->createMock(ScoreRuleCollectionFactory::class);
    }

    private function makeRule(string $attributeCode, string $operator, string $value, int $points): ScoreRule
    {
        $rule = $this->createStub(ScoreRule::class);
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

        return new ScoreRuleEvaluator($this->collectionFactory);
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

        $evaluator = new ScoreRuleEvaluator($this->collectionFactory);

        self::assertSame(10, $evaluator->getMatchingRulePoints($customer));
    }
}
