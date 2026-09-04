<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Campaign\Action;

use Ordo\Automation\Model\Campaign\Action\GenerateCoupon;
use Ordo\Automation\Model\CouponGenerator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class GenerateCouponTest extends TestCase
{
    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSetsCouponCodeInContext(): void
    {
        $generator = $this->createMock(CouponGenerator::class);
        $generator->method('generate')->willReturnMap([[5, 'COMEBACK', 'COMEBACK-XYZ']]);

        $action = new GenerateCoupon($generator, $this->createStub(LoggerInterface::class));

        $context = [];
        $action->execute($context, ['rule_id' => '5', 'prefix' => 'COMEBACK']);

        self::assertSame('COMEBACK-XYZ', $context['coupon_code']);
    }

    public function testExecuteLogsErrorWhenRuleIdMissing(): void
    {
        $generator = $this->createMock(CouponGenerator::class);
        $generator->expects(self::never())->method('generate');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        $action = new GenerateCoupon($generator, $logger);

        $context = [];
        $action->execute($context, []);

        self::assertArrayNotHasKey('coupon_code', $context);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLogsErrorWhenGeneratorThrows(): void
    {
        $generator = $this->createMock(CouponGenerator::class);
        $generator->method('generate')->willThrowException(new \RuntimeException('rule not found'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        $action = new GenerateCoupon($generator, $logger);

        $context = [];
        $action->execute($context, ['rule_id' => 5]);

        self::assertArrayNotHasKey('coupon_code', $context);
    }
}
