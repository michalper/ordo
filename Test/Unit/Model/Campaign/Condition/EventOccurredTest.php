<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Campaign\Condition;

use Ordo\Automation\Model\Campaign\Condition\EventOccurred;
use Ordo\Automation\Model\Event\EventOccurredResolver;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class EventOccurredTest extends TestCase
{
    private EventOccurredResolver&\PHPUnit\Framework\MockObject\MockObject $eventOccurredResolver;
    private EventOccurred $condition;

    protected function setUp(): void
    {
        $this->eventOccurredResolver = $this->createMock(EventOccurredResolver::class);
        $this->condition = new EventOccurred($this->eventOccurredResolver);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSatisfiedWhenTheEventOccurredWithinTheWindow(): void
    {
        $this->eventOccurredResolver->method('hasEventOccurred')
            ->willReturnMap([[42, 'cart_add', '24-MB01', 14, true]]);

        self::assertTrue($this->condition->isSatisfied(
            ['customer_id' => 42],
            ['event_type' => 'cart_add', 'event_key' => '24-MB01', 'within_days' => 14]
        ));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testNotSatisfiedWhenTheEventDidNotOccur(): void
    {
        $this->eventOccurredResolver->method('hasEventOccurred')
            ->willReturnMap([[42, 'cart_add', '24-MB01', 14, false]]);

        self::assertFalse($this->condition->isSatisfied(
            ['customer_id' => 42],
            ['event_type' => 'cart_add', 'event_key' => '24-MB01', 'within_days' => 14]
        ));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testBlankEventKeyPassesNullForAnySku(): void
    {
        $this->eventOccurredResolver->expects(self::once())->method('hasEventOccurred')
            ->with(42, 'cart_add', null, 14)->willReturn(true);

        self::assertTrue($this->condition->isSatisfied(
            ['customer_id' => 42],
            ['event_type' => 'cart_add', 'event_key' => '', 'within_days' => 14]
        ));
    }

    public function testNotSatisfiedWhenContextIsMissingCustomerId(): void
    {
        $this->eventOccurredResolver->expects(self::never())->method('hasEventOccurred');

        self::assertFalse($this->condition->isSatisfied([], ['event_type' => 'cart_add', 'within_days' => 14]));
    }

    public function testNotSatisfiedWhenParamsIsMissingEventType(): void
    {
        $this->eventOccurredResolver->expects(self::never())->method('hasEventOccurred');

        self::assertFalse($this->condition->isSatisfied(['customer_id' => 42], ['within_days' => 14]));
    }

    public function testNotSatisfiedWhenEventTypeIsBlank(): void
    {
        $this->eventOccurredResolver->expects(self::never())->method('hasEventOccurred');

        self::assertFalse($this->condition->isSatisfied(
            ['customer_id' => 42],
            ['event_type' => '   ', 'within_days' => 14]
        ));
    }
}
