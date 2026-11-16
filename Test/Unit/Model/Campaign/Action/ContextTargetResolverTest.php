<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Campaign\Action;

use Ordo\Automation\Model\Campaign\Action\ContextTargetResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ContextTargetResolverTest extends TestCase
{
    private function makeResolver(?LoggerInterface $logger = null): ContextTargetResolver
    {
        return new ContextTargetResolver($logger ?? $this->createStub(LoggerInterface::class));
    }

    public function testResolveCustomerOrVisitorReturnsCustomerIdWhenPresent(): void
    {
        $target = $this->makeResolver()->resolveCustomerOrVisitor(['customer_id' => 42]);

        self::assertSame(42, $target->customerId);
        self::assertNull($target->visitorId);
        self::assertFalse($target->isEmpty());
    }

    public function testResolveCustomerOrVisitorReturnsVisitorIdWhenPresent(): void
    {
        $target = $this->makeResolver()->resolveCustomerOrVisitor(['visitor_id' => 'v1']);

        self::assertNull($target->customerId);
        self::assertSame('v1', $target->visitorId);
        self::assertFalse($target->isEmpty());
    }

    public function testResolveCustomerOrVisitorTreatsZeroOrNegativeCustomerIdAsAbsent(): void
    {
        $target = $this->makeResolver()->resolveCustomerOrVisitor(['customer_id' => 0]);

        self::assertNull($target->customerId);
        self::assertTrue($target->isEmpty());
    }

    public function testResolveCustomerOrVisitorTreatsEmptyVisitorIdAsAbsent(): void
    {
        $target = $this->makeResolver()->resolveCustomerOrVisitor(['visitor_id' => '']);

        self::assertNull($target->visitorId);
        self::assertTrue($target->isEmpty());
    }

    public function testResolveCustomerOrVisitorIsEmptyWhenNeitherIsPresent(): void
    {
        $target = $this->makeResolver()->resolveCustomerOrVisitor([]);

        self::assertTrue($target->isEmpty());
    }

    public function testNullableStringTrimsAndReturnsNullForBlank(): void
    {
        $resolver = $this->makeResolver();

        self::assertSame('hello', $resolver->nullableString('  hello  '));
        self::assertNull($resolver->nullableString('   '));
        self::assertNull($resolver->nullableString(null));
    }

    public function testResolveTargetAndRequiredFieldReturnsTargetAndTrimmedValue(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');

        $resolved = $this->makeResolver($logger)->resolveTargetAndRequiredField(
            ['customer_id' => 42],
            ['headline' => '  Hello  '],
            'headline',
            'notify'
        );

        self::assertNotNull($resolved);
        self::assertSame(42, $resolved['target']->customerId);
        self::assertSame('Hello', $resolved['value']);
    }

    public function testResolveTargetAndRequiredFieldLogsAndReturnsNullWhenTargetIsEmpty(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(
            'Ordo_Automation: notify action has no customer_id or visitor_id in context to target.'
        );

        $resolved = $this->makeResolver($logger)
            ->resolveTargetAndRequiredField([], ['headline' => 'Hello'], 'headline', 'notify');

        self::assertNull($resolved);
    }

    public function testResolveTargetAndRequiredFieldLogsAndReturnsNullWhenFieldIsBlank(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')
            ->with('Ordo_Automation: nps_survey action is missing a question.');

        $resolved = $this->makeResolver($logger)
            ->resolveTargetAndRequiredField(['customer_id' => 42], [], 'question', 'nps_survey');

        self::assertNull($resolved);
    }
}
