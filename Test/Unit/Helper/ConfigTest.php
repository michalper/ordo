<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Helper;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Ordo\Automation\Helper\Config;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    private ScopeConfigInterface $scopeConfig;
    private Config $config;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createStub(ScopeConfigInterface::class);

        $this->config = new Config($this->scopeConfig);
    }

    public function testFlagGettersDelegateToScopeConfig(): void
    {
        $this->scopeConfig->method('isSetFlag')->willReturn(true);

        self::assertTrue($this->config->isReorderReminderEnabled());
        self::assertTrue($this->config->isAbandonedCartEnabled());
        self::assertTrue($this->config->isOfferReminderEnabled());
        self::assertTrue($this->config->isCreditLimitAlertEnabled());
        self::assertTrue($this->config->isLifecycleEmailsEnabled());
        self::assertTrue($this->config->isOrderApprovalEnabled());
        self::assertTrue($this->config->isSalesRepDigestEnabled());
        self::assertTrue($this->config->isTrackingEnabled());
        self::assertTrue($this->config->isPopupEnabled());
        self::assertTrue($this->config->isFreeGiftEnabled());
        self::assertTrue($this->config->isCreditLimitCheckoutBlockEnabled());
        self::assertTrue($this->config->isLeadScoringEnabled());
        self::assertTrue($this->config->isSmsEnabled());
    }

    public function testTwilioGettersDelegateToScopeConfig(): void
    {
        $this->scopeConfig->method('getValue')->willReturnMap([
            ['ordo_automation/sms/twilio_account_sid', 'store', null, 'AC123'],
            ['ordo_automation/sms/twilio_auth_token', 'store', null, 'secret-token'],
            ['ordo_automation/sms/twilio_from_number', 'store', null, '+15550001111'],
        ]);

        self::assertSame('AC123', $this->config->getTwilioAccountSid());
        self::assertSame('secret-token', $this->config->getTwilioAuthToken());
        self::assertSame('+15550001111', $this->config->getTwilioFromNumber());
    }

    public function testTwilioGettersReturnEmptyStringWhenUnset(): void
    {
        $this->scopeConfig->method('getValue')->willReturn(null);

        self::assertSame('', $this->config->getTwilioAccountSid());
        self::assertSame('', $this->config->getTwilioAuthToken());
        self::assertSame('', $this->config->getTwilioFromNumber());
    }

    public function testIntGettersUseDefaultWhenUnset(): void
    {
        $this->scopeConfig->method('getValue')->willReturn(null);

        self::assertSame(3, $this->config->getReorderMinOrders());
        self::assertSame(2, $this->config->getReorderLeadDays());
        self::assertSame(120, $this->config->getAbandonedCartDelayMinutes());
        self::assertSame(1, $this->config->getAbandonedCartMaxReminders());
        self::assertSame(2, $this->config->getOfferLeadDays());
        self::assertSame(1, $this->config->getOfferMaxSelfExtensions());
        self::assertSame(7, $this->config->getOfferSelfExtensionDays());
        self::assertSame(80, $this->config->getCreditLimitWarningThreshold());
        self::assertSame(7, $this->config->getCreditLimitAlertCooldownDays());
        self::assertSame(90, $this->config->getWinBackInactiveDays());
        self::assertSame(2, $this->config->getOrderApprovalEscalationDays());
        self::assertSame(7, $this->config->getTrackingRetentionDays());
        self::assertSame(3, $this->config->getTrackingViewThreshold());
        self::assertSame(1, $this->config->getTrackingClickThreshold());
        self::assertSame(15, $this->config->getPopupPollIntervalSeconds());
        self::assertSame(24, $this->config->getPopupFrequencyCapHours());
        self::assertSame(100, $this->config->getScoreThreshold());
    }

    public function testIntGetterHonorsExplicitZero(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('0');

        self::assertSame(0, $this->config->getTrackingRetentionDays());
    }

    public function testIntGetterUsesConfiguredValue(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('42');

        self::assertSame(42, $this->config->getReorderMinOrders());
    }

    public function testAbandonedCartMinSubtotalIsFloat(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('99.90');

        self::assertSame(99.9, $this->config->getAbandonedCartMinSubtotal());
    }
}
