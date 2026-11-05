<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Config\Source;

use Magento\SalesRule\Model\ResourceModel\Rule\Collection as SalesRuleCollection;
use Magento\SalesRule\Model\ResourceModel\Rule\CollectionFactory as SalesRuleCollectionFactory;
use Magento\SalesRule\Model\Rule;
use Ordo\Automation\Model\Config\Source\CartPriceRuleOptions;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class CartPriceRuleOptionsTest extends TestCase
{
    private function stubRule(int $id, string $name): Rule
    {
        $rule = $this->createStub(Rule::class);
        $rule->method('getId')->willReturn($id);
        $rule->method('getData')->willReturnMap([['name', null, $name]]);

        return $rule;
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testToOptionArrayFiltersToActiveCouponRulesOnly(): void
    {
        $collection = $this->createMock(SalesRuleCollection::class);
        $collection->expects(self::exactly(2))->method('addFieldToFilter')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator([
            $this->stubRule(5, '10% Off'),
            $this->stubRule(7, 'Win-back 15%'),
        ]));

        $collectionFactory = $this->createStub(SalesRuleCollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        $options = (new CartPriceRuleOptions($collectionFactory))->toOptionArray();

        self::assertSame([
            ['value' => 5, 'label' => '10% Off'],
            ['value' => 7, 'label' => 'Win-back 15%'],
        ], $options);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testToOptionArrayFiltersToActiveRulesThatUseACouponCode(): void
    {
        $appliedFilters = [];

        $collection = $this->createMock(SalesRuleCollection::class);
        $collection->method('addFieldToFilter')->willReturnCallback(
            function (string $field, $condition) use (&$appliedFilters, $collection) {
                $appliedFilters[$field] = $condition;
                return $collection;
            }
        );
        $collection->method('getIterator')->willReturn(new \ArrayIterator([]));

        $collectionFactory = $this->createStub(SalesRuleCollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        (new CartPriceRuleOptions($collectionFactory))->toOptionArray();

        self::assertSame('1', $appliedFilters['is_active']);
        self::assertSame(['neq' => Rule::COUPON_TYPE_NO_COUPON], $appliedFilters['coupon_type']);
    }
}
