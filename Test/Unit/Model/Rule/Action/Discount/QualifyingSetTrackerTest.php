<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Rule\Action\Discount;

use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item;
use Magento\SalesRule\Model\Rule;
use Magento\SalesRule\Model\Rule\Condition\Combine;
use Ordo\Automation\Model\Rule\Action\Discount\QualifyingSetTracker;
use PHPUnit\Framework\TestCase;

class QualifyingSetTrackerTest extends TestCase
{
    private QualifyingSetTracker $tracker;

    protected function setUp(): void
    {
        $this->tracker = new QualifyingSetTracker();
    }

    public function testCheapestQualifyingItemIsChosenAsFree(): void
    {
        $expensive = $this->item(itemId: 1, price: 100.0, quoteId: 5);
        $cheap = $this->item(itemId: 2, price: 20.0, quoteId: 5);
        $quote = $this->quoteWithItems(5, [$expensive, $cheap]);
        $this->assignQuote($expensive, $quote);
        $this->assignQuote($cheap, $quote);

        $rule = $this->ruleThatQualifiesEverything(ruleId: 10);

        self::assertFalse($this->tracker->isFreeItem($rule, $expensive));
        self::assertTrue($this->tracker->isFreeItem($rule, $cheap));
    }

    public function testItemNotMatchingRuleConditionsIsNeverFree(): void
    {
        $onlyItem = $this->item(itemId: 1, price: 20.0, quoteId: 5);
        $quote = $this->quoteWithItems(5, [$onlyItem]);
        $this->assignQuote($onlyItem, $quote);

        $rule = $this->ruleThatQualifiesNothing(ruleId: 10);

        self::assertFalse($this->tracker->isFreeItem($rule, $onlyItem));
    }

    public function testDecisionIsCachedAcrossCallsForTheSameRuleAndQuote(): void
    {
        $cheap = $this->item(itemId: 2, price: 20.0, quoteId: 5);
        $quote = $this->quoteWithItems(5, [$cheap]);
        $this->assignQuote($cheap, $quote);

        $conditions = $this->createMock(Combine::class);
        // If the tracker re-evaluated conditions on every call instead of caching, this
        // would be called more than twice (once per isFreeItem() call below).
        $conditions->expects(self::exactly(1))->method('validate')->willReturn(true);

        $rule = $this->createMock(Rule::class);
        $rule->method('getId')->willReturn(10);
        $rule->method('getConditions')->willReturn($conditions);

        self::assertTrue($this->tracker->isFreeItem($rule, $cheap));
        self::assertTrue($this->tracker->isFreeItem($rule, $cheap));
    }

    private function item(int $itemId, float $price, int $quoteId): Item&\PHPUnit\Framework\MockObject\MockObject
    {
        $item = $this->createMock(Item::class);
        $item->method('getItemId')->willReturn($itemId);
        $item->method('getCalculationPrice')->willReturn($price);
        $item->method('getQuoteId')->willReturn($quoteId);

        return $item;
    }

    private function quoteWithItems(int $quoteId, array $items): Quote&\PHPUnit\Framework\MockObject\MockObject
    {
        $quote = $this->createMock(Quote::class);
        $quote->method('getId')->willReturn($quoteId);
        $quote->method('getAllItems')->willReturn($items);

        return $quote;
    }

    private function assignQuote(Item&\PHPUnit\Framework\MockObject\MockObject $item, Quote $quote): void
    {
        $item->method('getQuote')->willReturn($quote);
    }

    private function ruleThatQualifiesEverything(int $ruleId): Rule&\PHPUnit\Framework\MockObject\MockObject
    {
        $conditions = $this->createMock(Combine::class);
        $conditions->method('validate')->willReturn(true);

        $rule = $this->createMock(Rule::class);
        $rule->method('getId')->willReturn($ruleId);
        $rule->method('getConditions')->willReturn($conditions);

        return $rule;
    }

    private function ruleThatQualifiesNothing(int $ruleId): Rule&\PHPUnit\Framework\MockObject\MockObject
    {
        $conditions = $this->createMock(Combine::class);
        $conditions->method('validate')->willReturn(false);

        $rule = $this->createMock(Rule::class);
        $rule->method('getId')->willReturn($ruleId);
        $rule->method('getConditions')->willReturn($conditions);

        return $rule;
    }
}
