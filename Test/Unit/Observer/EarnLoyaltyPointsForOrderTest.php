<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Observer;

use Magento\Framework\Event;
use Magento\Framework\Event\Observer as EventObserver;
use Magento\Sales\Model\Order;
use Ordo\Automation\Model\LoyaltyPointsManager;
use Ordo\Automation\Observer\EarnLoyaltyPointsForOrder;
use PHPUnit\Framework\TestCase;

class EarnLoyaltyPointsForOrderTest extends TestCase
{
    private function makeObserver(Order $order): EventObserver
    {
        $observer = $this->createStub(EventObserver::class);
        $observer->method('getEvent')->willReturn(new Event(['order' => $order]));

        return $observer;
    }

    public function testExecuteEarnsPointsForAnOrderWithACustomer(): void
    {
        $order = $this->createStub(Order::class);
        $order->method('getCustomerId')->willReturn(42);
        $order->method('getEntityId')->willReturn(123);
        // Deliberately different from getBaseGrandTotal() - proves points are earned off the
        // store's own base-currency total, not whatever currency this order happened to be
        // placed in (getLoyaltyPointsPerDollar() is one flat store-wide rate).
        $order->method('getGrandTotal')->willReturn(430.0);
        $order->method('getBaseGrandTotal')->willReturn(99.95);

        $loyaltyPointsManager = $this->createMock(LoyaltyPointsManager::class);
        $loyaltyPointsManager->expects(self::once())->method('earnForOrder')->with(42, 123, 99.95);

        (new EarnLoyaltyPointsForOrder($loyaltyPointsManager))->execute($this->makeObserver($order));
    }

    public function testExecuteDoesNothingWhenOrderHasNoCustomer(): void
    {
        $order = $this->createStub(Order::class);
        $order->method('getCustomerId')->willReturn(null);

        $loyaltyPointsManager = $this->createMock(LoyaltyPointsManager::class);
        $loyaltyPointsManager->expects(self::never())->method('earnForOrder');

        (new EarnLoyaltyPointsForOrder($loyaltyPointsManager))->execute($this->makeObserver($order));
    }

    public function testExecuteDoesNothingWhenOrderHasNoEntityIdYet(): void
    {
        $order = $this->createStub(Order::class);
        $order->method('getCustomerId')->willReturn(42);
        $order->method('getEntityId')->willReturn(null);

        $loyaltyPointsManager = $this->createMock(LoyaltyPointsManager::class);
        $loyaltyPointsManager->expects(self::never())->method('earnForOrder');

        (new EarnLoyaltyPointsForOrder($loyaltyPointsManager))->execute($this->makeObserver($order));
    }
}
