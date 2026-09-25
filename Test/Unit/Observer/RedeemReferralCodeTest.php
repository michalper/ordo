<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Observer;

use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer as EventObserver;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\ReferralManager;
use Ordo\Automation\Observer\RedeemReferralCode;
use PHPUnit\Framework\TestCase;

class RedeemReferralCodeTest extends TestCase
{
    private function makeObserver(
        Config $config,
        CustomerSession $customerSession,
        ReferralManager $manager
    ): RedeemReferralCode {
        return new RedeemReferralCode($config, $customerSession, $manager);
    }

    private function makeEventObserver(array $eventData): EventObserver
    {
        $event = new Event($eventData);

        $observer = $this->createStub(EventObserver::class);
        $observer->method('getEvent')->willReturn($event);

        return $observer;
    }

    public function testExecuteDoesNothingWhenReferralDisabled(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isReferralEnabled')->willReturn(false);

        $customerSession = $this->createStub(CustomerSession::class);
        $customerSession->method('getData')->willReturn('CODE1234');

        $manager = $this->createMock(ReferralManager::class);
        $manager->expects(self::never())->method('resolveReferrerCustomerId');

        $this->makeObserver($config, $customerSession, $manager)->execute($this->makeEventObserver([]));
    }

    public function testExecuteDoesNothingWhenNoCodeStashed(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isReferralEnabled')->willReturn(true);

        $customerSession = $this->createStub(CustomerSession::class);
        $customerSession->method('getData')->willReturn(null);

        $manager = $this->createMock(ReferralManager::class);
        $manager->expects(self::never())->method('resolveReferrerCustomerId');

        $this->makeObserver($config, $customerSession, $manager)->execute($this->makeEventObserver([]));
    }

    public function testExecuteDoesNothingWhenCustomerMissing(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isReferralEnabled')->willReturn(true);

        $customerSession = $this->createStub(CustomerSession::class);
        $customerSession->method('getData')->willReturn('CODE1234');

        $manager = $this->createMock(ReferralManager::class);
        $manager->expects(self::never())->method('resolveReferrerCustomerId');

        $this->makeObserver($config, $customerSession, $manager)->execute($this->makeEventObserver([]));
    }

    public function testExecuteDoesNothingWhenCodeUnknown(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isReferralEnabled')->willReturn(true);

        $customerSession = $this->createStub(CustomerSession::class);
        $customerSession->method('getData')->willReturn('CODE1234');

        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getId')->willReturn(2);

        $manager = $this->createMock(ReferralManager::class);
        $manager->method('resolveReferrerCustomerId')->willReturn(null);
        $manager->expects(self::never())->method('recordSignup');

        $this->makeObserver($config, $customerSession, $manager)
            ->execute($this->makeEventObserver(['customer' => $customer]));
    }

    public function testExecuteRecordsSignupWhenCodeResolves(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isReferralEnabled')->willReturn(true);

        $customerSession = $this->createStub(CustomerSession::class);
        $customerSession->method('getData')->willReturn('CODE1234');

        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getId')->willReturn(2);

        $manager = $this->createMock(ReferralManager::class);
        $manager->method('resolveReferrerCustomerId')->willReturn(1);
        $manager->expects(self::once())->method('recordSignup')->with(1, 2);

        $this->makeObserver($config, $customerSession, $manager)
            ->execute($this->makeEventObserver(['customer' => $customer]));
    }
}
