<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\LeadRouting;

use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Api\AttributeInterface;
use Ordo\Automation\Model\LeadRouting\LeadRoutingRuleEvaluator;
use Ordo\Automation\Model\LeadRoutingRule;
use Ordo\Automation\Model\ResourceModel\LeadRoutingRule\Collection as LeadRoutingRuleCollection;
use Ordo\Automation\Model\ResourceModel\LeadRoutingRule\CollectionFactory as LeadRoutingRuleCollectionFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class LeadRoutingRuleEvaluatorTest extends TestCase
{
    private LeadRoutingRuleCollectionFactory $collectionFactory;

    protected function setUp(): void
    {
        $this->collectionFactory = $this->createMock(LeadRoutingRuleCollectionFactory::class);
    }

    private function makeRule(int $entityId, string $attributeCode, string $operator, string $value): LeadRoutingRule
    {
        $rule = $this->createStub(LeadRoutingRule::class);
        $rule->method('getEntityId')->willReturn($entityId);
        $rule->method('getAttributeCode')->willReturn($attributeCode);
        $rule->method('getOperator')->willReturn($operator);
        $rule->method('getValue')->willReturn($value);

        return $rule;
    }

    /**
     * @param LeadRoutingRule[] $rules
     */
    private function makeEvaluator(array $rules): LeadRoutingRuleEvaluator
    {
        $collection = $this->createStub(LeadRoutingRuleCollection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('setOrder')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator($rules));

        $this->collectionFactory->method('create')->willReturn($collection);

        return new LeadRoutingRuleEvaluator($this->collectionFactory);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testFirstMatchingRuleWinsOverALaterAlsoMatchingRule(): void
    {
        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getGroupId')->willReturn(1);

        $first = $this->makeRule(1, 'group_id', 'equals', '1');
        $second = $this->makeRule(2, 'group_id', 'equals', '1');
        $evaluator = $this->makeEvaluator([$first, $second]);

        self::assertSame($first, $evaluator->getMatchingRule($customer));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testNonMatchingRuleIsSkippedInFavorOfALaterMatch(): void
    {
        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getGroupId')->willReturn(1);

        $noMatch = $this->makeRule(1, 'group_id', 'equals', '2');
        $match = $this->makeRule(2, 'group_id', 'equals', '1');
        $evaluator = $this->makeEvaluator([$noMatch, $match]);

        self::assertSame($match, $evaluator->getMatchingRule($customer));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testBlankAttributeCodeIsACatchAllMatch(): void
    {
        $customer = $this->createStub(CustomerInterface::class);

        $catchAll = $this->makeRule(1, '', '', '');
        $evaluator = $this->makeEvaluator([$catchAll]);

        self::assertSame($catchAll, $evaluator->getMatchingRule($customer));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testContainsOperatorMatches(): void
    {
        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getEmail')->willReturn('someone@example.com');

        $rule = $this->makeRule(1, 'email', 'contains', '@example.com');
        $evaluator = $this->makeEvaluator([$rule]);

        self::assertSame($rule, $evaluator->getMatchingRule($customer));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testEavCustomAttributeFallbackUsedForUnknownAttribute(): void
    {
        $customAttribute = $this->createStub(AttributeInterface::class);
        $customAttribute->method('getValue')->willReturn('gold');

        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getCustomAttribute')->willReturn($customAttribute);

        $rule = $this->makeRule(1, 'tier', 'equals', 'gold');
        $evaluator = $this->makeEvaluator([$rule]);

        self::assertSame($rule, $evaluator->getMatchingRule($customer));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testNoMatchReturnsNull(): void
    {
        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getGroupId')->willReturn(1);

        $evaluator = $this->makeEvaluator([$this->makeRule(1, 'group_id', 'equals', '2')]);

        self::assertNull($evaluator->getMatchingRule($customer));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testOnlyEnabledRulesAreLoadedInTheFirstPlace(): void
    {
        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getGroupId')->willReturn(1);

        $collection = $this->createMock(LeadRoutingRuleCollection::class);
        $collection->expects(self::once())->method('addFieldToFilter')->with('enabled', 1)->willReturnSelf();
        $collection->method('setOrder')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator([
            $this->makeRule(1, 'group_id', 'equals', '1'),
        ]));

        $this->collectionFactory->method('create')->willReturn($collection);

        $evaluator = new LeadRoutingRuleEvaluator($this->collectionFactory);

        self::assertNotNull($evaluator->getMatchingRule($customer));
    }
}
