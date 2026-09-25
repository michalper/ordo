<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Observer;

use Magento\Framework\Event;
use Magento\Framework\Event\Observer as EventObserver;
use Magento\Sales\Model\Order;
use Ordo\Automation\Api\Data\CampaignTriggerInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Queue\CampaignDispatchPublisher;
use Ordo\Automation\Model\Referral\FirstOrderChecker;
use Ordo\Automation\Model\ReferralManager;
use Ordo\Automation\Observer\DispatchReferralConvertedCampaigns;
use PHPUnit\Framework\TestCase;

class DispatchReferralConvertedCampaignsTest extends TestCase
{
    private function makeObserver(
        Config $config,
        FirstOrderChecker $firstOrderChecker,
        ReferralManager $referralManager,
        CampaignDispatchPublisher $publisher
    ): DispatchReferralConvertedCampaigns {
        return new DispatchReferralConvertedCampaigns($config, $firstOrderChecker, $referralManager, $publisher);
    }

    private function makeEventObserver(?Order $order): EventObserver
    {
        $event = new Event($order !== null ? ['order' => $order] : []);

        $observer = $this->createStub(EventObserver::class);
        $observer->method('getEvent')->willReturn($event);

        return $observer;
    }

    private function makeOrder(?int $customerId, int $entityId = 100): Order
    {
        $order = $this->createStub(Order::class);
        $order->method('getCustomerId')->willReturn($customerId);
        $order->method('getEntityId')->willReturn($entityId);

        return $order;
    }

    public function testExecuteDoesNothingWhenReferralDisabled(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isReferralEnabled')->willReturn(false);

        $firstOrderChecker = $this->createMock(FirstOrderChecker::class);
        $firstOrderChecker->expects(self::never())->method('isFirstOrder');

        $publisher = $this->createMock(CampaignDispatchPublisher::class);
        $publisher->expects(self::never())->method('publish');

        $this->makeObserver($config, $firstOrderChecker, $this->createStub(ReferralManager::class), $publisher)
            ->execute($this->makeEventObserver($this->makeOrder(5)));
    }

    public function testExecuteDoesNothingWhenOrderHasNoCustomer(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isReferralEnabled')->willReturn(true);

        $firstOrderChecker = $this->createMock(FirstOrderChecker::class);
        $firstOrderChecker->expects(self::never())->method('isFirstOrder');

        $publisher = $this->createMock(CampaignDispatchPublisher::class);
        $publisher->expects(self::never())->method('publish');

        $this->makeObserver($config, $firstOrderChecker, $this->createStub(ReferralManager::class), $publisher)
            ->execute($this->makeEventObserver($this->makeOrder(null)));
    }

    public function testExecuteDoesNothingWhenNotFirstOrder(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isReferralEnabled')->willReturn(true);

        $firstOrderChecker = $this->createStub(FirstOrderChecker::class);
        $firstOrderChecker->method('isFirstOrder')->willReturn(false);

        $referralManager = $this->createMock(ReferralManager::class);
        $referralManager->expects(self::never())->method('markConvertedAndGetReferrer');

        $publisher = $this->createMock(CampaignDispatchPublisher::class);
        $publisher->expects(self::never())->method('publish');

        $this->makeObserver($config, $firstOrderChecker, $referralManager, $publisher)
            ->execute($this->makeEventObserver($this->makeOrder(5)));
    }

    public function testExecuteDoesNothingWhenNoPendingReferral(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isReferralEnabled')->willReturn(true);

        $firstOrderChecker = $this->createStub(FirstOrderChecker::class);
        $firstOrderChecker->method('isFirstOrder')->willReturn(true);

        $referralManager = $this->createStub(ReferralManager::class);
        $referralManager->method('markConvertedAndGetReferrer')->willReturn(null);

        $publisher = $this->createMock(CampaignDispatchPublisher::class);
        $publisher->expects(self::never())->method('publish');

        $this->makeObserver($config, $firstOrderChecker, $referralManager, $publisher)
            ->execute($this->makeEventObserver($this->makeOrder(5)));
    }

    public function testExecutePublishesReferralConvertedTargetingReferrer(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isReferralEnabled')->willReturn(true);

        $firstOrderChecker = $this->createStub(FirstOrderChecker::class);
        $firstOrderChecker->method('isFirstOrder')->willReturn(true);

        $referralManager = $this->createStub(ReferralManager::class);
        $referralManager->method('markConvertedAndGetReferrer')->willReturn(1);

        $publisher = $this->createMock(CampaignDispatchPublisher::class);
        $publisher->expects(self::once())->method('publish')->with(
            CampaignTriggerInterface::TRIGGER_REFERRAL_CONVERTED,
            [
                'customer_id' => 1,
                'referred_customer_id' => 5,
                'order_id' => 100,
            ]
        );

        $this->makeObserver($config, $firstOrderChecker, $referralManager, $publisher)
            ->execute($this->makeEventObserver($this->makeOrder(5, 100)));
    }
}
