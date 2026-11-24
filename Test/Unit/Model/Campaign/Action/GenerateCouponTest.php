<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Campaign\Action;

use Ordo\Automation\Model\Campaign\Action\GenerateCoupon;
use Ordo\Automation\Model\Config\Source\CartPriceRuleOptions;
use Ordo\Automation\Model\CouponGenerator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class GenerateCouponTest extends TestCase
{
    private function makeCartPriceRuleOptions(): CartPriceRuleOptions
    {
        $options = $this->createStub(CartPriceRuleOptions::class);
        $options->method('toOptionArray')->willReturn([]);

        return $options;
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSetsCouponCodeInContext(): void
    {
        $generator = $this->createMock(CouponGenerator::class);
        $generator->method('generate')->willReturn('COMEBACK-XYZ');

        $action = new GenerateCoupon(
            $generator,
            $this->createStub(LoggerInterface::class),
            $this->makeCartPriceRuleOptions()
        );

        $context = [];
        $action->execute($context, ['rule_id' => '5', 'prefix' => 'COMEBACK']);

        self::assertSame('COMEBACK-XYZ', $context['coupon_code']);
    }

    /**
     * runActionsFrom() stamps context['campaign_id'] before any action runs (see
     * CampaignDispatcher's own docblock) - this is what lets RevenueAnalytics later attribute the
     * coupon back to the campaign that generated it (ADR 0028).
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testExecutePassesCampaignIdFromContextToGenerator(): void
    {
        $generator = $this->createMock(CouponGenerator::class);
        $generator->expects(self::once())->method('generate')->with(5, 'COMEBACK', 1, 42)
            ->willReturn('COMEBACK-XYZ');

        $action = new GenerateCoupon(
            $generator,
            $this->createStub(LoggerInterface::class),
            $this->makeCartPriceRuleOptions()
        );

        $context = ['campaign_id' => 42];
        $action->execute($context, ['rule_id' => '5', 'prefix' => 'COMEBACK']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecutePassesNullCampaignIdWhenNotInContext(): void
    {
        $generator = $this->createMock(CouponGenerator::class);
        $generator->expects(self::once())->method('generate')->with(5, 'ORDO', 1, null)
            ->willReturn('ORDO-XYZ');

        $action = new GenerateCoupon(
            $generator,
            $this->createStub(LoggerInterface::class),
            $this->makeCartPriceRuleOptions()
        );

        $context = [];
        $action->execute($context, ['rule_id' => '5']);
    }

    public function testExecuteLogsErrorWhenRuleIdMissing(): void
    {
        $generator = $this->createMock(CouponGenerator::class);
        $generator->expects(self::never())->method('generate');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        $action = new GenerateCoupon($generator, $logger, $this->makeCartPriceRuleOptions());

        $context = [];
        $action->execute($context, []);

        self::assertArrayNotHasKey('coupon_code', $context);
    }

    /**
     * Deliberately NOT caught here - CampaignDispatcher::runOneActionOrSplitStoppingChainOnFailure()
     * is the one place that's supposed to catch an action's own exception (fail-closed, ADR 0068).
     * Swallowing it here used to let the chain continue to a later send_email referencing
     * {{var coupon_code}} with no code ever set.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteLetsAGeneratorFailurePropagate(): void
    {
        $generator = $this->createMock(CouponGenerator::class);
        $generator->method('generate')->willThrowException(new \RuntimeException('rule not found'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');

        $action = new GenerateCoupon($generator, $logger, $this->makeCartPriceRuleOptions());

        $context = [];
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('rule not found');
        $action->execute($context, ['rule_id' => 5]);
    }

    public function testGetParamFieldsListsRuleIdAndPrefix(): void
    {
        $action = new GenerateCoupon(
            $this->createStub(CouponGenerator::class),
            $this->createStub(LoggerInterface::class),
            $this->makeCartPriceRuleOptions()
        );

        self::assertSame(['rule_id', 'prefix'], array_column($action->getParamFields(), 'name'));
    }
}
