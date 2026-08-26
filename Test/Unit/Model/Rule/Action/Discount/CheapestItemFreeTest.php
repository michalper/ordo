<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Rule\Action\Discount;

use Magento\Quote\Model\Quote\Item\AbstractItem;
use Magento\SalesRule\Model\Rule;
use Magento\SalesRule\Model\Rule\Action\Discount\Data as DiscountData;
use Magento\SalesRule\Model\Rule\Action\Discount\DataFactory as DiscountDataFactory;
use Ordo\Automation\Model\Rule\Action\Discount\CheapestItemFree;
use Ordo\Automation\Model\Rule\Action\Discount\QualifyingSetTracker;
use PHPUnit\Framework\TestCase;

class CheapestItemFreeTest extends TestCase
{
    public function testFixQuantityReturnsQtyUnchanged(): void
    {
        $tracker = $this->createMock(QualifyingSetTracker::class);
        $discountDataFactory = $this->createMock(DiscountDataFactory::class);

        $action = new CheapestItemFree($tracker, $discountDataFactory);

        self::assertSame(3.0, $action->fixQuantity(3.0, $this->createMock(Rule::class)));
    }

    public function testCalculateReturnsZeroDiscountWhenNotFreeItem(): void
    {
        $tracker = $this->createMock(QualifyingSetTracker::class);
        $tracker->method('isFreeItem')->willReturn(false);

        $discountData = $this->createMock(DiscountData::class);
        $discountData->expects(self::never())->method('setAmount');

        $discountDataFactory = $this->createMock(DiscountDataFactory::class);
        $discountDataFactory->method('create')->willReturn($discountData);

        $action = new CheapestItemFree($tracker, $discountDataFactory);
        $rule = $this->createMock(Rule::class);
        $item = $this->createMock(AbstractItem::class);

        self::assertSame($discountData, $action->calculate($rule, $item, 2));
    }

    public function testCalculateDiscountsOneUnitWhenFreeItem(): void
    {
        $tracker = $this->createMock(QualifyingSetTracker::class);
        $tracker->method('isFreeItem')->willReturn(true);

        $discountData = $this->createMock(DiscountData::class);
        $discountData->expects(self::once())->method('setAmount')->with(10.0);
        $discountData->expects(self::once())->method('setBaseAmount')->with(10.0);
        $discountData->expects(self::once())->method('setOriginalAmount')->with(10.0);
        $discountData->expects(self::once())->method('setBaseOriginalAmount')->with(10.0);

        $discountDataFactory = $this->createMock(DiscountDataFactory::class);
        $discountDataFactory->method('create')->willReturn($discountData);

        $action = new CheapestItemFree($tracker, $discountDataFactory);
        $rule = $this->createMock(Rule::class);

        $item = $this->createMock(AbstractItem::class);
        $item->method('getCalculationPrice')->willReturn(10.0);
        $item->method('getBaseCalculationPrice')->willReturn(10.0);

        self::assertSame($discountData, $action->calculate($rule, $item, 3));
    }
}
