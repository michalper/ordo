<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\GraphQl\Resolver;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\GraphQl\Model\Query\ContextInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\GraphQl\CustomerIdResolver;
use Ordo\Automation\Model\GraphQl\Resolver\RegisterPriceWatch;
use Ordo\Automation\Model\PriceWatch\PriceWatchSubscription;
use Ordo\Automation\Model\PriceWatch\PriceWatchSubscriptionManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class RegisterPriceWatchTest extends TestCase
{
    private PriceWatchSubscriptionManager $priceWatchSubscriptionManager;
    private ProductRepositoryInterface $productRepository;
    private Config $config;

    protected function setUp(): void
    {
        $this->priceWatchSubscriptionManager = $this->createMock(PriceWatchSubscriptionManager::class);
        $this->productRepository = $this->createMock(ProductRepositoryInterface::class);
        $this->config = $this->createStub(Config::class);
        $this->config->method('isPriceWatchEnabled')->willReturn(true);
    }

    private function resolve(array $args, int $userId = 0): mixed
    {
        $resolver = new RegisterPriceWatch(
            $this->priceWatchSubscriptionManager,
            $this->productRepository,
            new CustomerIdResolver(),
            $this->config
        );

        $context = $this->createStub(ContextInterface::class);
        $context->method('getUserId')->willReturn($userId);

        return $resolver->resolve(
            $this->createStub(Field::class),
            $context,
            $this->createStub(ResolveInfo::class),
            null,
            $args
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testReturnsFalseWhenPriceWatchDisabled(): void
    {
        $this->config = $this->createStub(Config::class);
        $this->config->method('isPriceWatchEnabled')->willReturn(false);

        $this->priceWatchSubscriptionManager->expects(self::never())->method('register');

        self::assertSame(
            ['ok' => false, 'reason' => 'price_watch_disabled'],
            $this->resolve(['productId' => 1, 'watchType' => 'price_drop', 'visitorId' => 'v1'])
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testReturnsFalseForAnInvalidWatchType(): void
    {
        $this->priceWatchSubscriptionManager->expects(self::never())->method('register');

        self::assertSame(
            ['ok' => false, 'reason' => 'invalid_payload'],
            $this->resolve(['productId' => 1, 'watchType' => 'not_a_type', 'visitorId' => 'v1'])
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testReturnsFalseWhenNeitherCustomerNorVisitorIdentityGiven(): void
    {
        $this->priceWatchSubscriptionManager->expects(self::never())->method('register');

        self::assertSame(
            ['ok' => false, 'reason' => 'invalid_payload'],
            $this->resolve(['productId' => 1, 'watchType' => 'price_drop'])
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testReturnsFalseForANonexistentProduct(): void
    {
        $this->productRepository->method('getById')->with(999)
            ->willThrowException(new NoSuchEntityException(__('not found')));
        $this->priceWatchSubscriptionManager->expects(self::never())->method('register');

        self::assertSame(
            ['ok' => false, 'reason' => 'invalid_product'],
            $this->resolve(['productId' => 999, 'watchType' => 'price_drop', 'visitorId' => 'v1'])
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testReturnsFalseForAnInvalidGuestEmail(): void
    {
        $this->priceWatchSubscriptionManager->expects(self::never())->method('register');

        self::assertSame(
            ['ok' => false, 'reason' => 'invalid_email'],
            $this->resolve([
                'productId' => 1,
                'watchType' => PriceWatchSubscription::WATCH_TYPE_PRICE_DROP,
                'visitorId' => 'v1',
                'email' => 'not-an-email',
            ])
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testRegistersAGuestWatchWithEmail(): void
    {
        $this->priceWatchSubscriptionManager->expects(self::once())->method('register')
            ->with(1, PriceWatchSubscription::WATCH_TYPE_BACK_IN_STOCK, null, 'v1', 'guest@example.test');

        self::assertSame(
            ['ok' => true],
            $this->resolve([
                'productId' => 1,
                'watchType' => PriceWatchSubscription::WATCH_TYPE_BACK_IN_STOCK,
                'visitorId' => 'v1',
                'email' => 'guest@example.test',
            ])
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testRegistersALoggedInCustomerWatchIgnoringAnyEmail(): void
    {
        $this->priceWatchSubscriptionManager->expects(self::once())->method('register')
            ->with(1, PriceWatchSubscription::WATCH_TYPE_PRICE_DROP, 42, null, null);

        self::assertSame(
            ['ok' => true],
            $this->resolve(
                ['productId' => 1, 'watchType' => PriceWatchSubscription::WATCH_TYPE_PRICE_DROP, 'email' => 'x@example.test'],
                userId: 42
            )
        );
    }
}
