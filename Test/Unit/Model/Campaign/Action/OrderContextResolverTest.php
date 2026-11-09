<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Campaign\Action;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Ordo\Automation\Model\Campaign\Action\OrderContextResolver;
use PHPUnit\Framework\TestCase;

class OrderContextResolverTest extends TestCase
{
    public function testResolveOrderLoadsTheOrderIdFromContext(): void
    {
        $order = $this->createStub(OrderInterface::class);
        $orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $orderRepository->expects(self::once())->method('get')->with(55)->willReturn($order);

        $result = (new OrderContextResolver($orderRepository))->resolveOrder(['order_id' => 55]);

        self::assertSame($order, $result);
    }

    public function testResolveOrderReturnsNullWhenOrderIdMissing(): void
    {
        $orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $orderRepository->expects(self::never())->method('get');

        $result = (new OrderContextResolver($orderRepository))->resolveOrder([]);

        self::assertNull($result);
    }

    public function testResolveOrderReturnsNullWhenOrderIdIsZeroOrNegative(): void
    {
        $orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $orderRepository->expects(self::never())->method('get');

        self::assertNull((new OrderContextResolver($orderRepository))->resolveOrder(['order_id' => 0]));
        self::assertNull((new OrderContextResolver($orderRepository))->resolveOrder(['order_id' => -5]));
    }

    public function testResolveOrderReturnsNullWhenTheOrderCannotBeLoaded(): void
    {
        $orderRepository = $this->createStub(OrderRepositoryInterface::class);
        $orderRepository->method('get')->willThrowException(new NoSuchEntityException());

        $result = (new OrderContextResolver($orderRepository))->resolveOrder(['order_id' => 999]);

        self::assertNull($result);
    }
}
