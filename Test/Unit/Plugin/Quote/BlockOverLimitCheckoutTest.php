<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Plugin\Quote;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
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
    private AdapterInterface $connection;
    private ResourceConnection $resourceConnection;
    private CartManagementInterface $subject;
    private BlockOverLimitCheckout $plugin;

    protected function setUp(): void
    {
        $this->config = $this->createStub(Config::class);
        $this->cartRepository = $this->createMock(CartRepositoryInterface::class);
        $this->creditLimitCalculator = $this->createMock(CreditLimitCalculator::class);
        $this->subject = $this->createStub(CartManagementInterface::class);

        $this->connection = $this->createMock(AdapterInterface::class);
        // GET_LOCK() succeeds by default - only the lock-contention test overrides this to '0'.
        $this->connection->method('fetchOne')->willReturn('1');

        $this->resourceConnection = $this->createStub(ResourceConnection::class);
        $this->resourceConnection->method('getConnection')->willReturn($this->connection);

        $this->plugin = new BlockOverLimitCheckout(
            $this->config,
            $this->cartRepository,
            $this->creditLimitCalculator,
            $this->resourceConnection
        );
    }

    private function proceedReturning(int $cartId, ?object $payment = null): callable
    {
        return static fn (int $id, $pay = null): array => [$id, $pay];
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testDoesNotBlockWhenConfigDisabled(): void
    {
        $this->config->method('isCreditLimitCheckoutBlockEnabled')->willReturn(false);
        $this->cartRepository->expects(self::never())->method('get');

        $result = $this->plugin->aroundPlaceOrder($this->subject, $this->proceedReturning(42), 42);

        self::assertSame([42, null], $result);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testDoesNotBlockGuestQuote(): void
    {
        $this->config->method('isCreditLimitCheckoutBlockEnabled')->willReturn(true);

        $quote = $this->createQuoteStub(null, 0.0);
        $this->cartRepository->method('get')->willReturnMap([[42, $quote]]);

        $this->creditLimitCalculator->expects(self::never())->method('getProjectedUtilizationPercent');

        $result = $this->plugin->aroundPlaceOrder($this->subject, $this->proceedReturning(42), 42);

        self::assertSame([42, null], $result);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testDoesNotBlockWhenUnderLimit(): void
    {
        $this->config->method('isCreditLimitCheckoutBlockEnabled')->willReturn(true);

        $quote = $this->createQuoteStub(7, 50.0);
        $this->cartRepository->method('get')->willReturnMap([[42, $quote]]);

        $this->creditLimitCalculator->method('getProjectedUtilizationPercent')->willReturnMap([[7, 50.0, 99.99]]);

        $result = $this->plugin->aroundPlaceOrder($this->subject, $this->proceedReturning(42), 42);

        self::assertSame([42, null], $result);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testBlocksWhenOverLimit(): void
    {
        $this->config->method('isCreditLimitCheckoutBlockEnabled')->willReturn(true);

        $quote = $this->createQuoteStub(7, 50000.0);
        $this->cartRepository->method('get')->willReturnMap([[42, $quote]]);

        $this->creditLimitCalculator->method('getProjectedUtilizationPercent')->willReturnMap([[7, 50000.0, 150.0]]);

        $proceedCalled = false;
        $proceed = function () use (&$proceedCalled): array {
            $proceedCalled = true;
            return [42, null];
        };

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage(
            'Your order could not be placed because your account has reached its credit limit. '
            . 'Please contact your sales representative.'
        );

        try {
            $this->plugin->aroundPlaceOrder($this->subject, $proceed, 42);
        } finally {
            self::assertFalse($proceedCalled, 'placeOrder must not proceed when over the limit');
        }
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testBlocksExactlyAtLimitBoundary(): void
    {
        $this->config->method('isCreditLimitCheckoutBlockEnabled')->willReturn(true);

        $quote = $this->createQuoteStub(7, 50.0);
        $this->cartRepository->method('get')->willReturnMap([[42, $quote]]);

        $this->creditLimitCalculator->method('getProjectedUtilizationPercent')->willReturnMap([[7, 50.0, 100.0]]);

        $this->expectException(LocalizedException::class);

        $this->plugin->aroundPlaceOrder($this->subject, $this->proceedReturning(42), 42);
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

        $this->plugin->aroundPlaceOrder($this->subject, $this->proceedReturning(42), 42);
    }

    /**
     * Regression: the real fix this test class is extended for. getProjectedUtilizationPercent()
     * only sees already-placed orders, so without a lock held across the real placement, two
     * concurrent placeOrder() calls for the same customer could both pass the check before
     * either order commits. Simulating that here directly (mocking the race) isn't possible at
     * the unit level since this is a single-threaded test - instead this proves the mechanism
     * the fix relies on: the lock is actually acquired before the check, and released even when
     * $proceed() itself throws.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testAcquiresAndReleasesTheLockAroundTheCheckAndPlacement(): void
    {
        $this->config->method('isCreditLimitCheckoutBlockEnabled')->willReturn(true);

        $quote = $this->createQuoteStub(7, 50.0);
        $this->cartRepository->method('get')->willReturnMap([[42, $quote]]);
        $this->creditLimitCalculator->method('getProjectedUtilizationPercent')->willReturnMap([[7, 50.0, 10.0]]);

        $queries = [];
        $this->connection->method('query')->willReturnCallback(function (string $sql) use (&$queries) {
            $queries[] = $sql;
        });

        $proceed = function () {
            throw new LocalizedException(__('payment declined'));
        };

        try {
            $this->plugin->aroundPlaceOrder($this->subject, $proceed, 42);
            self::fail('Expected the proceed() exception to propagate');
        } catch (LocalizedException $e) {
            self::assertSame('payment declined', (string) $e->getMessage());
        }

        self::assertCount(1, $queries, 'the lock must still be released when placeOrder() itself throws');
        self::assertStringContainsString('RELEASE_LOCK', $queries[0]);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testThrowsWhenTheCheckoutLockCannotBeAcquired(): void
    {
        $this->config->method('isCreditLimitCheckoutBlockEnabled')->willReturn(true);

        $quote = $this->createQuoteStub(7, 50.0);
        $this->cartRepository->method('get')->willReturnMap([[42, $quote]]);

        $this->connection = $this->createMock(AdapterInterface::class);
        $this->connection->method('fetchOne')->willReturn('0'); // GET_LOCK() -> 0 (not acquired)
        $this->resourceConnection = $this->createStub(ResourceConnection::class);
        $this->resourceConnection->method('getConnection')->willReturn($this->connection);
        $this->plugin = new BlockOverLimitCheckout(
            $this->config,
            $this->cartRepository,
            $this->creditLimitCalculator,
            $this->resourceConnection
        );

        $this->creditLimitCalculator->expects(self::never())->method('getProjectedUtilizationPercent');
        $this->expectException(LocalizedException::class);

        $this->plugin->aroundPlaceOrder($this->subject, $this->proceedReturning(42), 42);
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
