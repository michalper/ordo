<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Plugin\Quote;

use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\CreditLimitCalculator;
use Ordo\Automation\Plugin\Quote\BlockOverLimitCheckout;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class BlockOverLimitCheckoutTest extends TestCase
{
    private Config $config;
    private CartRepositoryInterface $cartRepository;
    private CreditLimitCalculator $creditLimitCalculator;
    private CartManagementInterface $subject;
    private BlockOverLimitCheckout $plugin;

    protected function setUp(): void
    {
        $this->config = $this->createStub(Config::class);
        $this->cartRepository = $this->createMock(CartRepositoryInterface::class);
        $this->creditLimitCalculator = $this->createMock(CreditLimitCalculator::class);
        $this->subject = $this->createStub(CartManagementInterface::class);

        $this->plugin = new BlockOverLimitCheckout(
            $this->config,
            $this->cartRepository,
            $this->creditLimitCalculator
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testDoesNotBlockWhenConfigDisabled(): void
    {
        $this->config->method('isCreditLimitCheckoutBlockEnabled')->willReturn(false);
        $this->cartRepository->expects(self::never())->method('get');

        $result = $this->plugin->beforePlaceOrder($this->subject, 42);

        self::assertSame([42, null], $result);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testDoesNotBlockGuestQuote(): void
    {
        $this->config->method('isCreditLimitCheckoutBlockEnabled')->willReturn(true);

        $quote = $this->createQuoteStub(null, 0.0);
        $this->cartRepository->method('get')->willReturnMap([[42, $quote]]);

        $this->creditLimitCalculator->expects(self::never())->method('getProjectedUtilizationPercent');

        $result = $this->plugin->beforePlaceOrder($this->subject, 42);

        self::assertSame([42, null], $result);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testDoesNotBlockWhenUnderLimit(): void
    {
        $this->config->method('isCreditLimitCheckoutBlockEnabled')->willReturn(true);

        $quote = $this->createQuoteStub(7, 50.0);
        $this->cartRepository->method('get')->willReturnMap([[42, $quote]]);

        $this->creditLimitCalculator->method('getProjectedUtilizationPercent')->willReturnMap([[7, 50.0, 99.99]]);

        $result = $this->plugin->beforePlaceOrder($this->subject, 42);

        self::assertSame([42, null], $result);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testBlocksWhenOverLimit(): void
    {
        $this->config->method('isCreditLimitCheckoutBlockEnabled')->willReturn(true);

        $quote = $this->createQuoteStub(7, 50000.0);
        $this->cartRepository->method('get')->willReturnMap([[42, $quote]]);

        $this->creditLimitCalculator->method('getProjectedUtilizationPercent')->willReturnMap([[7, 50000.0, 150.0]]);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage(
            'Your order could not be placed because your account has reached its credit limit. '
            . 'Please contact your sales representative.'
        );

        $this->plugin->beforePlaceOrder($this->subject, 42);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testBlocksExactlyAtLimitBoundary(): void
    {
        $this->config->method('isCreditLimitCheckoutBlockEnabled')->willReturn(true);

        $quote = $this->createQuoteStub(7, 50.0);
        $this->cartRepository->method('get')->willReturnMap([[42, $quote]]);

        $this->creditLimitCalculator->method('getProjectedUtilizationPercent')->willReturnMap([[7, 50.0, 100.0]]);

        $this->expectException(LocalizedException::class);

        $this->plugin->beforePlaceOrder($this->subject, 42);
    }

    /**
     * Regression: a single order can blow straight past the limit without ever being blocked if
     * only already-placed orders count as "used" - the quote's own grand total (not yet an
     * order) must be folded into the projected usage too.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testBlocksASingleOrderThatAloneWouldExceedTheLimit(): void
    {
        $this->config->method('isCreditLimitCheckoutBlockEnabled')->willReturn(true);

        $quote = $this->createQuoteStub(7, 50000.0);
        $this->cartRepository->method('get')->willReturnMap([[42, $quote]]);

        // $0 already used, but this quote's own $50,000 pushes projected usage over the limit.
        $this->creditLimitCalculator->expects(self::once())->method('getProjectedUtilizationPercent')
            ->with(7, 50000.0)
            ->willReturn(5000.0);

        $this->expectException(LocalizedException::class);

        $this->plugin->beforePlaceOrder($this->subject, 42);
    }

    private function createQuoteStub(?int $customerId, float $baseGrandTotal): Quote
    {
        return new class ($customerId, $baseGrandTotal) extends Quote {
            public function __construct(
                private readonly ?int $customerId,
                private readonly float $baseGrandTotal
            ) {
            }

            public function getCustomerId(): ?int
            {
                return $this->customerId;
            }

            public function getBaseGrandTotal(): float
            {
                return $this->baseGrandTotal;
            }
        };
    }
}
