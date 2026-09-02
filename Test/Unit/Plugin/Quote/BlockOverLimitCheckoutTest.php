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

    public function testDoesNotBlockWhenConfigDisabled(): void
    {
        $this->config->method('isCreditLimitCheckoutBlockEnabled')->willReturn(false);
        $this->cartRepository->expects(self::never())->method('get');

        $result = $this->plugin->beforePlaceOrder($this->subject, 42);

        self::assertSame([42, null], $result);
    }

    public function testDoesNotBlockGuestQuote(): void
    {
        $this->config->method('isCreditLimitCheckoutBlockEnabled')->willReturn(true);

        $quote = $this->createQuoteStub(null);
        $this->cartRepository->method('get')->with(42)->willReturn($quote);

        $this->creditLimitCalculator->expects(self::never())->method('getUtilizationPercent');

        $result = $this->plugin->beforePlaceOrder($this->subject, 42);

        self::assertSame([42, null], $result);
    }

    public function testDoesNotBlockWhenUnderLimit(): void
    {
        $this->config->method('isCreditLimitCheckoutBlockEnabled')->willReturn(true);

        $quote = $this->createQuoteStub(7);
        $this->cartRepository->method('get')->with(42)->willReturn($quote);

        $this->creditLimitCalculator->method('getUtilizationPercent')->with(7)->willReturn(99.99);

        $result = $this->plugin->beforePlaceOrder($this->subject, 42);

        self::assertSame([42, null], $result);
    }

    public function testBlocksWhenOverLimit(): void
    {
        $this->config->method('isCreditLimitCheckoutBlockEnabled')->willReturn(true);

        $quote = $this->createQuoteStub(7);
        $this->cartRepository->method('get')->with(42)->willReturn($quote);

        $this->creditLimitCalculator->method('getUtilizationPercent')->with(7)->willReturn(150.0);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage(
            'Your order could not be placed because your account has reached its credit limit. '
            . 'Please contact your sales representative.'
        );

        $this->plugin->beforePlaceOrder($this->subject, 42);
    }

    public function testBlocksExactlyAtLimitBoundary(): void
    {
        $this->config->method('isCreditLimitCheckoutBlockEnabled')->willReturn(true);

        $quote = $this->createQuoteStub(7);
        $this->cartRepository->method('get')->with(42)->willReturn($quote);

        $this->creditLimitCalculator->method('getUtilizationPercent')->with(7)->willReturn(100.0);

        $this->expectException(LocalizedException::class);

        $this->plugin->beforePlaceOrder($this->subject, 42);
    }

    private function createQuoteStub(?int $customerId): Quote
    {
        return new class ($customerId) extends Quote {
            public function __construct(private readonly ?int $customerId)
            {
            }

            public function getCustomerId(): ?int
            {
                return $this->customerId;
            }
        };
    }
}
