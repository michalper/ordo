<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Campaign\Action;

use Ordo\Automation\Model\Campaign\Action\GenerateCoupon;
use Ordo\Automation\Model\CouponGenerator;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

class GenerateCouponTest extends TestCase
{
    public function testExecuteSetsCouponCodeInContext(): void
    {
        $generator = $this->createMock(CouponGenerator::class);
        $generator->method('generate')->with(5, 'COMEBACK')->willReturn('COMEBACK-XYZ');

        $action = new GenerateCoupon($generator, $this->createMock(LoggerInterface::class));

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
