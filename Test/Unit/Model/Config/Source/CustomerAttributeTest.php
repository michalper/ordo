<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Config\Source;

use Magento\Customer\Model\Attribute;
use Magento\Customer\Model\ResourceModel\Attribute\Collection;
use Magento\Customer\Model\ResourceModel\Attribute\CollectionFactory as CustomerAttributeCollectionFactory;
use Ordo\Automation\Model\Config\Source\CustomerAttribute;
use PHPUnit\Framework\TestCase;

class CustomerAttributeTest extends TestCase
{
    /**
     * getFrontendLabel() is a magic accessor (via DataObject::__call(), never declared on
     * Attribute/AbstractAttribute itself, since frontend_label is just a plain eav_attribute
     * column) — mocking __call() itself is the supported way to stub it, same technique
     * RuleProductListerTest uses for Rule::getRuleId().
     */
    private function makeAttribute(string $code, string $label): Attribute
    {
        $attribute = $this->createStub(Attribute::class);
        $attribute->method('getAttributeCode')->willReturn($code);
        $attribute->method('__call')->willReturnCallback(static fn (string $method, array $args) => match ($method) {
            'getFrontendLabel' => $label,
            default => null,
        });

        return $attribute;
    }

    public function testMapsAttributesToValueLabelPairsSortedByLabel(): void
    {
        $collection = $this->createMock(Collection::class);
        $collection->expects(self::once())->method('addVisibleFilter');
        $collection->expects(self::once())->method('getIterator')->willReturn(new \ArrayIterator([
            $this->makeAttribute('tier', 'Tier'),
            $this->makeAttribute('email', 'Email'),
        ]));

        $collectionFactory = $this->createMock(CustomerAttributeCollectionFactory::class);
        $collectionFactory->expects(self::once())->method('create')->willReturn($collection);

        $options = (new CustomerAttribute($collectionFactory))->toOptionArray();

        self::assertSame(
            [
                ['value' => 'email', 'label' => 'Email'],
                ['value' => 'tier', 'label' => 'Tier'],
                ['value' => 'store_id', 'label' => 'store_id'],
            ],
            $options
        );
    }

    public function testFallsBackToAttributeCodeWhenFrontendLabelIsEmpty(): void
    {
        $collection = $this->createMock(Collection::class);
        $collection->expects(self::once())->method('addVisibleFilter');
        $collection->expects(self::once())->method('getIterator')->willReturn(new \ArrayIterator([
            $this->makeAttribute('custom_flag', ''),
        ]));

        $collectionFactory = $this->createMock(CustomerAttributeCollectionFactory::class);
        $collectionFactory->expects(self::once())->method('create')->willReturn($collection);

        $options = (new CustomerAttribute($collectionFactory))->toOptionArray();

        self::assertContains(['value' => 'custom_flag', 'label' => 'custom_flag'], $options);
    }

    /**
     * store_id is is_visible=0 in a stock Magento install (confirmed against a real database),
     * yet it's one of ScoreRuleEvaluator's 4 hardcoded core-getter codes — addVisibleFilter()
     * alone would silently drop it from the picker, so it must always be present regardless of
     * what the (filtered) collection itself returns.
     */
    public function testAlwaysIncludesStoreIdEvenWhenNotReturnedByTheVisibleCollection(): void
    {
        $collection = $this->createMock(Collection::class);
        $collection->expects(self::once())->method('addVisibleFilter');
        $collection->expects(self::once())->method('getIterator')->willReturn(new \ArrayIterator([]));

        $collectionFactory = $this->createMock(CustomerAttributeCollectionFactory::class);
        $collectionFactory->expects(self::once())->method('create')->willReturn($collection);

        $options = (new CustomerAttribute($collectionFactory))->toOptionArray();

        self::assertSame([['value' => 'store_id', 'label' => 'store_id']], $options);
    }

    public function testDoesNotDuplicateStoreIdWhenTheCollectionAlreadyReturnsIt(): void
    {
        $collection = $this->createMock(Collection::class);
        $collection->expects(self::once())->method('addVisibleFilter');
        $collection->expects(self::once())->method('getIterator')->willReturn(new \ArrayIterator([
            $this->makeAttribute('store_id', 'Create In'),
        ]));

        $collectionFactory = $this->createMock(CustomerAttributeCollectionFactory::class);
        $collectionFactory->expects(self::once())->method('create')->willReturn($collection);

        $options = (new CustomerAttribute($collectionFactory))->toOptionArray();

        self::assertSame([['value' => 'store_id', 'label' => 'Create In']], $options);
    }
}
