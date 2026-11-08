<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\GraphQl\Resolver;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\GraphQl\Model\Query\ContextInterface;
use Ordo\Automation\Model\GraphQl\CustomerIdResolver;
use Ordo\Automation\Model\GraphQl\Resolver\UnregisterPriceWatch;
use Ordo\Automation\Model\PriceWatch\PriceWatchSubscription;
use Ordo\Automation\Model\PriceWatch\PriceWatchSubscriptionManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class UnregisterPriceWatchTest extends TestCase
{
    private PriceWatchSubscriptionManager $priceWatchSubscriptionManager;

    protected function setUp(): void
    {
        $this->priceWatchSubscriptionManager = $this->createMock(PriceWatchSubscriptionManager::class);
    }

    private function resolve(array $args, int $userId = 0): mixed
    {
        $resolver = new UnregisterPriceWatch($this->priceWatchSubscriptionManager, new CustomerIdResolver());

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
    public function testIsANoOpForMissingProductIdOrWatchType(): void
    {
        $this->priceWatchSubscriptionManager->expects(self::never())->method('unregister');

        self::assertSame(['ok' => true], $this->resolve([]));
    }

    public function testUnregistersForALoggedInCustomer(): void
    {
        $this->priceWatchSubscriptionManager->expects(self::once())->method('unregister')
            ->with(1, PriceWatchSubscription::WATCH_TYPE_PRICE_DROP, 42, null);

        self::assertSame(
            ['ok' => true],
            $this->resolve(['productId' => 1, 'watchType' => PriceWatchSubscription::WATCH_TYPE_PRICE_DROP], userId: 42)
        );
    }

    public function testUnregistersForAVisitor(): void
    {
        $this->priceWatchSubscriptionManager->expects(self::once())->method('unregister')
            ->with(1, PriceWatchSubscription::WATCH_TYPE_BACK_IN_STOCK, null, 'v1');

        self::assertSame(
            ['ok' => true],
            $this->resolve(['productId' => 1, 'watchType' => PriceWatchSubscription::WATCH_TYPE_BACK_IN_STOCK, 'visitorId' => 'v1'])
        );
    }
}
