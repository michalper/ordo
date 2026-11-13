<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Observer;

use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer as EventObserver;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Ordo\Automation\Api\Data\CampaignTriggerInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Queue\CampaignDispatchPublisher;
use Ordo\Automation\Model\ReferralManager;
use Ordo\Automation\Observer\RedeemReferralCode;
use PHPUnit\Framework\TestCase;

class RedeemReferralCodeTest extends TestCase
{
    private function makeObserver(
        Config $config,
        CustomerSession $customerSession,
        ReferralManager $manager,
        ?CampaignDispatchPublisher $campaignDispatchPublisher = null,
        ?RemoteAddress $remoteAddress = null
    ): RedeemReferralCode {
        return new RedeemReferralCode(
            $config,
            $customerSession,
            $manager,
            $campaignDispatchPublisher ?? $this->createStub(CampaignDispatchPublisher::class),
            $remoteAddress ?? $this->createStub(RemoteAddress::class)
        );
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

    public function testExecuteRecordsSignupAndPublishesReferralSignedUpWhenCodeResolves(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isReferralEnabled')->willReturn(true);

        $customerSession = $this->createStub(CustomerSession::class);
        $customerSession->method('getData')->willReturn('CODE1234');

        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getId')->willReturn(2);

        $manager = $this->createMock(ReferralManager::class);
        $manager->method('resolveReferrerCustomerId')->willReturn(1);
        $manager->expects(self::once())->method('recordSignup')->with(1, 2, '203.0.113.5')->willReturn(true);

        $remoteAddress = $this->createStub(RemoteAddress::class);
        $remoteAddress->method('getRemoteAddress')->willReturn('203.0.113.5');

        $publisher = $this->createMock(CampaignDispatchPublisher::class);
        $publisher->expects(self::once())->method('publish')->with(
            CampaignTriggerInterface::TRIGGER_REFERRAL_SIGNED_UP,
            ['customer_id' => 2, 'referrer_customer_id' => 1]
        );

        $this->makeObserver($config, $customerSession, $manager, $publisher, $remoteAddress)
            ->execute($this->makeEventObserver(['customer' => $customer]));
    }

    /**
     * RemoteAddress::getRemoteAddress() returns false, not null, when it can't resolve one -
     * recordSignup() must still get a real null (its own fail-open check is `=== null`), not the
     * literal boolean false.
     */
    public function testExecutePassesNullSignupIpWhenRemoteAddressCannotResolveOne(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isReferralEnabled')->willReturn(true);

        $customerSession = $this->createStub(CustomerSession::class);
        $customerSession->method('getData')->willReturn('CODE1234');

        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getId')->willReturn(2);

        $manager = $this->createMock(ReferralManager::class);
        $manager->method('resolveReferrerCustomerId')->willReturn(1);
        $manager->expects(self::once())->method('recordSignup')->with(1, 2, null)->willReturn(true);

        $remoteAddress = $this->createStub(RemoteAddress::class);
        $remoteAddress->method('getRemoteAddress')->willReturn(false);

        $this->makeObserver($config, $customerSession, $manager, null, $remoteAddress)
            ->execute($this->makeEventObserver(['customer' => $customer]));
    }

    /**
     * Regression-shaped test: Model\ReferralManager::recordSignup() itself no-ops (returns false)
     * for a self-referral or a customer who already has a referral row - this observer must not
     * publish referral_signed_up for either case just because resolveReferrerCustomerId()
     * resolved a real referrer.
     */
    public function testExecuteDoesNotPublishWhenRecordSignupNoOps(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isReferralEnabled')->willReturn(true);

        $customerSession = $this->createStub(CustomerSession::class);
        $customerSession->method('getData')->willReturn('CODE1234');

        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getId')->willReturn(2);

        $manager = $this->createStub(ReferralManager::class);
        $manager->method('resolveReferrerCustomerId')->willReturn(1);
        $manager->method('recordSignup')->willReturn(false);

        $publisher = $this->createMock(CampaignDispatchPublisher::class);
        $publisher->expects(self::never())->method('publish');

        $this->makeObserver($config, $customerSession, $manager, $publisher)
            ->execute($this->makeEventObserver(['customer' => $customer]));
    }
}
