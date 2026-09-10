<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Helper;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Ordo\Automation\Helper\Config;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    private ScopeConfigInterface $scopeConfig;
    private Config $config;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createStub(ScopeConfigInterface::class);

        $encryptor = $this->createStub(EncryptorInterface::class);
        // Obscure-field getters route every value through Encryptor::decrypt() (see
        // Config::decryptedConfig()) — this stub is an identity function so plaintext test
        // fixtures ("secret-token" etc.) pass through unchanged, same as the real decrypt() would
        // do for a value it actually encrypted.
        $encryptor->method('decrypt')->willReturnArgument(0);

        $this->config = new Config($this->scopeConfig, $encryptor);
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
        self::assertTrue($this->config->isNotificationEnabled());
        self::assertTrue($this->config->isNpsSurveyEnabled());
        self::assertTrue($this->config->isShoppingFeedEnabled());
        self::assertTrue($this->config->isFreeGiftEnabled());
        self::assertTrue($this->config->isCreditLimitCheckoutBlockEnabled());
        self::assertTrue($this->config->isLeadScoringEnabled());
        self::assertTrue($this->config->isSmsEnabled());
        self::assertTrue($this->config->isWhatsAppEnabled());
        self::assertTrue($this->config->isPushEnabled());
        self::assertTrue($this->config->isFrequencyCapEnabled());
        self::assertTrue($this->config->isQuietHoursEnabled());
    }

    public function testPushGettersDelegateToScopeConfig(): void
    {
        $this->scopeConfig->method('getValue')->willReturnMap([
            ['ordo_automation/push/vapid_public_key', 'store', null, 'public-key-b64url'],
            ['ordo_automation/push/vapid_private_key', 'store', null, 'private-key-b64url'],
            ['ordo_automation/push/vapid_subject', 'store', null, 'mailto:ops@example.com'],
        ]);

        self::assertSame('public-key-b64url', $this->config->getVapidPublicKey());
        self::assertSame('private-key-b64url', $this->config->getVapidPrivateKey());
        self::assertSame('mailto:ops@example.com', $this->config->getVapidSubject());
    }

    public function testPushGettersReturnEmptyStringWhenUnset(): void
    {
        $this->scopeConfig->method('getValue')->willReturn(null);

        self::assertSame('', $this->config->getVapidPublicKey());
        self::assertSame('', $this->config->getVapidPrivateKey());
        self::assertSame('', $this->config->getVapidSubject());
    }

    public function testTwilioGettersDelegateToScopeConfig(): void
    {
        $this->scopeConfig->method('getValue')->willReturnMap([
            ['ordo_automation/sms/twilio_account_sid', 'store', null, 'AC123'],
            ['ordo_automation/sms/twilio_auth_token', 'store', null, 'secret-token'],
            ['ordo_automation/sms/twilio_api_key_sid', 'store', null, 'SK123'],
            ['ordo_automation/sms/twilio_api_key_secret', 'store', null, 'secret-key'],
            ['ordo_automation/sms/twilio_from_number', 'store', null, '+15550001111'],
        ]);

        self::assertSame('AC123', $this->config->getTwilioAccountSid());
        self::assertSame('secret-token', $this->config->getTwilioAuthToken());
        self::assertSame('SK123', $this->config->getTwilioApiKeySid());
        self::assertSame('secret-key', $this->config->getTwilioApiKeySecret());
        self::assertSame('+15550001111', $this->config->getTwilioFromNumber());
    }

    public function testTwilioGettersReturnEmptyStringWhenUnset(): void
    {
        $this->scopeConfig->method('getValue')->willReturn(null);

        self::assertSame('', $this->config->getTwilioAccountSid());
        self::assertSame('', $this->config->getTwilioAuthToken());
        self::assertSame('', $this->config->getTwilioApiKeySid());
        self::assertSame('', $this->config->getTwilioApiKeySecret());
        self::assertSame('', $this->config->getTwilioFromNumber());
    }

    public function testAdAudienceAndShoppingFeedGettersDelegateToScopeConfig(): void
    {
        $this->scopeConfig->method('getValue')->willReturnMap([
            ['ordo_automation/ad_audience_sync/google_ads_client_id', 'store', null, 'gid'],
            ['ordo_automation/ad_audience_sync/google_ads_client_secret', 'store', null, 'gsecret'],
            ['ordo_automation/ad_audience_sync/google_ads_refresh_token', 'store', null, 'grefresh'],
            ['ordo_automation/ad_audience_sync/google_ads_developer_token', 'store', null, 'gdev'],
            ['ordo_automation/ad_audience_sync/google_ads_login_customer_id', 'store', null, '1234567890'],
            ['ordo_automation/ad_audience_sync/meta_access_token', 'store', null, 'mtoken'],
            ['ordo_automation/ad_audience_sync/meta_ad_account_id', 'store', null, '9999'],
            ['ordo_automation/shopping_feed/title', 'store', null, 'My Feed'],
            ['ordo_automation/shopping_feed/description', 'store', null, 'My Feed Description'],
        ]);

        self::assertSame('gid', $this->config->getGoogleAdsClientId());
        self::assertSame('gsecret', $this->config->getGoogleAdsClientSecret());
        self::assertSame('grefresh', $this->config->getGoogleAdsRefreshToken());
        self::assertSame('gdev', $this->config->getGoogleAdsDeveloperToken());
        self::assertSame('1234567890', $this->config->getGoogleAdsLoginCustomerId());
        self::assertSame('mtoken', $this->config->getMetaAccessToken());
        self::assertSame('9999', $this->config->getMetaAdAccountId());
        self::assertSame('My Feed', $this->config->getShoppingFeedTitle());
        self::assertSame('My Feed Description', $this->config->getShoppingFeedDescription());
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
        self::assertSame(20, $this->config->getNotificationPollIntervalSeconds());
        self::assertSame(25, $this->config->getNpsSurveyPollIntervalSeconds());
        self::assertSame(24, $this->config->getPopupFrequencyCapHours());
        self::assertSame(100, $this->config->getScoreThreshold());
        self::assertSame(100, $this->config->getLoyaltySilverThreshold());
        self::assertSame(500, $this->config->getLoyaltyGoldThreshold());
        self::assertSame(5, $this->config->getFrequencyCapMaxMessages());
        self::assertSame(24, $this->config->getFrequencyCapWindowHours());
        self::assertSame(21, $this->config->getQuietHoursStartHour());
        self::assertSame(8, $this->config->getQuietHoursEndHour());
    }

    public function testQuietHoursHourGettersClampOutOfRangeConfigValues(): void
    {
        $this->scopeConfig->method('getValue')->willReturnMap([
            ['ordo_automation/quiet_hours/start_hour', 'store', null, '99'],
            ['ordo_automation/quiet_hours/end_hour', 'store', null, '-5'],
        ]);

        self::assertSame(23, $this->config->getQuietHoursStartHour());
        self::assertSame(0, $this->config->getQuietHoursEndHour());
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

    public function testObscureFieldGettersDecryptTheirConfiguredValue(): void
    {
        $this->scopeConfig->method('getValue')->willReturnMap([
            ['ordo_automation/email/sendgrid_webhook_verification_key', 'store', null, 'sg-verify-secret'],
            ['ordo_automation/whatsapp/access_token', 'store', null, 'wa-access-secret'],
            ['ordo_automation/whatsapp/app_secret', 'store', null, 'wa-app-secret'],
            ['ordo_automation/whatsapp/webhook_verify_token', 'store', null, 'wa-verify-secret'],
        ]);

        self::assertSame('sg-verify-secret', $this->config->getSendGridWebhookVerificationKey());
        self::assertSame('wa-access-secret', $this->config->getWhatsAppAccessToken());
        self::assertSame('wa-app-secret', $this->config->getWhatsAppAppSecret());
        self::assertSame('wa-verify-secret', $this->config->getWhatsAppWebhookVerifyToken());
    }

    public function testWhatsAppPhoneNumberIdAndBusinessAccountIdAreNotEncrypted(): void
    {
        // Unlike the other WhatsApp getters above, these two read scopeConfig directly (not
        // through decryptedConfig()) - real Meta identifiers, not secrets, so there's nothing to
        // decrypt.
        $this->scopeConfig->method('getValue')->willReturnMap([
            ['ordo_automation/whatsapp/phone_number_id', 'store', null, '123456789012345'],
            ['ordo_automation/whatsapp/business_account_id', 'store', null, '987654321098765'],
        ]);

        self::assertSame('123456789012345', $this->config->getWhatsAppPhoneNumberId());
        self::assertSame('987654321098765', $this->config->getWhatsAppBusinessAccountId());
    }
}
