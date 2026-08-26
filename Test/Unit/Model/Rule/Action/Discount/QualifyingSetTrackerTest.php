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
        $expensive = $this->item(sku: 'expensive-sku', price: 100.0);
        $cheap = $this->item(sku: 'cheap-sku', price: 20.0);
        $quote = $this->quoteWithItems(5, [$expensive, $cheap]);
        $this->assignQuote($expensive, $quote);
        $this->assignQuote($cheap, $quote);

        $rule = $this->ruleThatQualifiesEverything(ruleId: 10);

        self::assertFalse($this->tracker->isFreeItem($rule, $expensive));
        self::assertTrue($this->tracker->isFreeItem($rule, $cheap));
    }

    public function testItemNotMatchingRuleConditionsIsNeverFree(): void
    {
        $onlyItem = $this->item(sku: 'only-sku', price: 20.0);
        $quote = $this->quoteWithItems(5, [$onlyItem]);
        $this->assignQuote($onlyItem, $quote);

        $rule = $this->ruleThatQualifiesNothing(ruleId: 10);

        self::assertFalse($this->tracker->isFreeItem($rule, $onlyItem));
    }

    public function testDecisionIsCachedAcrossCallsForTheSameRuleAndQuote(): void
    {
        $cheap = $this->item(sku: 'cheap-sku', price: 20.0);
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

    /**
     * Deliberately does NOT mock getItemId() — real discount collection calls this with a
     * Quote\Address\Item whose getItemId() is reliably null (see
     * Model\Rule\Action\Discount\QualifyingSetTracker's docblock and VERIFICATION.md #17),
     * which is exactly the real bug a getItemId()-based mock here would have hidden.
     */
    private function item(string $sku, float $price): Item&\PHPUnit\Framework\MockObject\MockObject
    {
        $item = $this->createMock(Item::class);
        $item->method('getSku')->willReturn($sku);
        $item->method('getCalculationPrice')->willReturn($price);

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
