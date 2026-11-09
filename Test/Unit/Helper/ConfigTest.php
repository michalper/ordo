<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Helper;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Config\Source\AbandonedCartFallbackChannel;
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
        self::assertTrue($this->config->isBrowseAbandonmentEnabled());
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
        self::assertTrue($this->config->isWebhookEnabled());
        self::assertTrue($this->config->isFreeGiftEnabled());
        self::assertTrue($this->config->isCreditLimitCheckoutBlockEnabled());
        self::assertTrue($this->config->isLeadScoringEnabled());
        self::assertTrue($this->config->isLeadRoutingEnabled());
        self::assertTrue($this->config->isSmsEnabled());
        self::assertTrue($this->config->isWhatsAppEnabled());
        self::assertTrue($this->config->isPushEnabled());
        self::assertTrue($this->config->isFrequencyCapEnabled());
        self::assertTrue($this->config->isAbandonedCartFallbackEnabled());
        self::assertTrue($this->config->isAbTestAutoWinnerEnabled());
        self::assertTrue($this->config->isQuietHoursEnabled());
        self::assertTrue($this->config->isMetaCatalogFeedEnabled());
        self::assertTrue($this->config->isReviewRequestEnabled());
        self::assertTrue($this->config->isEmailTemplateVersioningEnabled());
        self::assertTrue($this->config->isReferralEnabled());
        self::assertTrue($this->config->isLoyaltyPointsEnabled());
        self::assertTrue($this->config->isPriceWatchEnabled());
        self::assertTrue($this->config->isAiContentEnabled());
        self::assertTrue($this->config->isAiAgentEnabled());
        self::assertTrue($this->config->isGtmBridgeEnabled());
        self::assertTrue($this->config->isExitIntentEnabled());
        self::assertTrue($this->config->isSocialProofEnabled());
        self::assertTrue($this->config->isLowStockWidgetEnabled());
        self::assertTrue($this->config->isEmailCaptureEnabled());
        self::assertTrue($this->config->isCustomerAnniversaryTriggerEnabled());
        self::assertTrue($this->config->isGa4Enabled());
        self::assertTrue($this->config->isMetaCapiEnabled());
    }

    public function testGetSocialProofMinimumViewsDelegatesToScopeConfig(): void
    {
        $this->scopeConfig->method('getValue')->willReturnMap([
            ['ordo_tracking/tracking/social_proof_minimum_views', 'store', null, '5'],
        ]);

        self::assertSame(5, $this->config->getSocialProofMinimumViews());
    }

    public function testGetSocialProofMinimumViewsDefaultsToThree(): void
    {
        self::assertSame(3, $this->config->getSocialProofMinimumViews());
    }

    public function testGetSocialProofMessageTemplateDelegatesToScopeConfig(): void
    {
        $this->scopeConfig->method('getValue')->willReturnMap([
            ['ordo_tracking/tracking/social_proof_message_template', 'store', null, '{count} viewed today'],
        ]);

        self::assertSame('{count} viewed today', $this->config->getSocialProofMessageTemplate());
    }

    public function testGetSocialProofMessageTemplateDefaultsToAStandardMessage(): void
    {
        self::assertSame('{count} people viewed this today', $this->config->getSocialProofMessageTemplate());
    }

    public function testGetLowStockThresholdDelegatesToScopeConfig(): void
    {
        $this->scopeConfig->method('getValue')->willReturnMap([
            ['ordo_tracking/tracking/low_stock_threshold', 'store', null, '2'],
        ]);

        self::assertSame(2, $this->config->getLowStockThreshold());
    }

    public function testGetLowStockThresholdDefaultsToFive(): void
    {
        self::assertSame(5, $this->config->getLowStockThreshold());
    }

    public function testGetLowStockMessageTemplateDelegatesToScopeConfig(): void
    {
        $this->scopeConfig->method('getValue')->willReturnMap([
            ['ordo_tracking/tracking/low_stock_message_template', 'store', null, '{qty} left!'],
        ]);

        self::assertSame('{qty} left!', $this->config->getLowStockMessageTemplate());
    }

    public function testGetLowStockMessageTemplateDefaultsToAStandardMessage(): void
    {
        self::assertSame('Only {qty} left in stock', $this->config->getLowStockMessageTemplate());
    }

    public function testGetEmailCaptureDelaySecondsDelegatesToScopeConfig(): void
    {
        $this->scopeConfig->method('getValue')->willReturnMap([
            ['ordo_tracking/tracking/email_capture_delay_seconds', 'store', null, '15'],
        ]);

        self::assertSame(15, $this->config->getEmailCaptureDelaySeconds());
    }

    public function testGetEmailCaptureDelaySecondsDefaultsToEight(): void
    {
        self::assertSame(8, $this->config->getEmailCaptureDelaySeconds());
    }

    public function testGetEmailCaptureHeadlineDelegatesToScopeConfig(): void
    {
        $this->scopeConfig->method('getValue')->willReturnMap([
            ['ordo_tracking/tracking/email_capture_headline', 'store', null, 'Join us!'],
        ]);

        self::assertSame('Join us!', $this->config->getEmailCaptureHeadline());
    }

    public function testGetEmailCaptureHeadlineDefaultsToAStandardMessage(): void
    {
        self::assertSame('Get 10% off your first order', $this->config->getEmailCaptureHeadline());
    }

    public function testGetEmailCaptureBodyDelegatesToScopeConfig(): void
    {
        $this->scopeConfig->method('getValue')->willReturnMap([
            ['ordo_tracking/tracking/email_capture_body', 'store', null, 'Custom body'],
        ]);

        self::assertSame('Custom body', $this->config->getEmailCaptureBody());
    }

    public function testGetEmailCaptureBodyDefaultsToAStandardMessage(): void
    {
        self::assertSame(
            'Sign up for our newsletter and get 10% off your first order.',
            $this->config->getEmailCaptureBody()
        );
    }

    public function testGetEmailCaptureCtaLabelDelegatesToScopeConfig(): void
    {
        $this->scopeConfig->method('getValue')->willReturnMap([
            ['ordo_tracking/tracking/email_capture_cta_label', 'store', null, 'Join'],
        ]);

        self::assertSame('Join', $this->config->getEmailCaptureCtaLabel());
    }

    public function testGetEmailCaptureCtaLabelDefaultsToSubscribe(): void
    {
        self::assertSame('Subscribe', $this->config->getEmailCaptureCtaLabel());
    }

    public function testPushGettersDelegateToScopeConfig(): void
    {
        $this->scopeConfig->method('getValue')->willReturnMap([
            ['ordo_channels/push/vapid_public_key', 'store', null, 'public-key-b64url'],
            ['ordo_channels/push/vapid_private_key', 'store', null, 'private-key-b64url'],
            ['ordo_channels/push/vapid_subject', 'store', null, 'mailto:ops@example.com'],
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
            ['ordo_channels/sms/twilio_account_sid', 'store', null, 'AC123'],
            ['ordo_channels/sms/twilio_auth_token', 'store', null, 'secret-token'],
            ['ordo_channels/sms/twilio_api_key_sid', 'store', null, 'SK123'],
            ['ordo_channels/sms/twilio_api_key_secret', 'store', null, 'secret-key'],
            ['ordo_channels/sms/twilio_from_number', 'store', null, '+15550001111'],
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
            ['ordo_integrations/ad_audience_sync/data_manager_client_id', 'store', null, 'gid'],
            ['ordo_integrations/ad_audience_sync/data_manager_client_secret', 'store', null, 'gsecret'],
            ['ordo_integrations/ad_audience_sync/data_manager_refresh_token', 'store', null, 'grefresh'],
            ['ordo_integrations/ad_audience_sync/google_ads_account_id', 'store', null, '1234567890'],
            ['ordo_integrations/ad_audience_sync/meta_access_token', 'store', null, 'mtoken'],
            ['ordo_integrations/ad_audience_sync/meta_ad_account_id', 'store', null, '9999'],
            ['ordo_integrations/ad_audience_sync/tiktok_access_token', 'store', null, 'tiktok-token'],
            ['ordo_integrations/ad_audience_sync/tiktok_advertiser_id', 'store', null, '555'],
            ['ordo_integrations/conversion_tracking/ga4_measurement_id', 'store', null, 'G-ABC123'],
            ['ordo_integrations/conversion_tracking/ga4_api_secret', 'store', null, 'ga4secret'],
            ['ordo_integrations/conversion_tracking/meta_capi_pixel_id', 'store', null, '1234567890'],
            ['ordo_integrations/conversion_tracking/meta_capi_access_token', 'store', null, 'capitoken'],
            ['ordo_integrations/shopping_feed/title', 'store', null, 'My Feed'],
            ['ordo_integrations/shopping_feed/description', 'store', null, 'My Feed Description'],
            ['ordo_integrations/meta_catalog_feed/default_brand', 'store', null, 'Acme'],
        ]);

        self::assertSame('gid', $this->config->getDataManagerClientId());
        self::assertSame('gsecret', $this->config->getDataManagerClientSecret());
        self::assertSame('grefresh', $this->config->getDataManagerRefreshToken());
        self::assertSame('1234567890', $this->config->getGoogleAdsAccountId());
        self::assertSame('mtoken', $this->config->getMetaAccessToken());
        self::assertSame('9999', $this->config->getMetaAdAccountId());
        self::assertSame('tiktok-token', $this->config->getTiktokAccessToken());
        self::assertSame('555', $this->config->getTiktokAdvertiserId());
        self::assertSame('G-ABC123', $this->config->getGa4MeasurementId());
        self::assertSame('ga4secret', $this->config->getGa4ApiSecret());
        self::assertSame('1234567890', $this->config->getMetaCapiPixelId());
        self::assertSame('capitoken', $this->config->getMetaCapiAccessToken());
        self::assertSame('My Feed', $this->config->getShoppingFeedTitle());
        self::assertSame('My Feed Description', $this->config->getShoppingFeedDescription());
        self::assertSame('Acme', $this->config->getMetaCatalogFeedDefaultBrand());
    }

    public function testAiAgentGettersDelegateToScopeConfig(): void
    {
        $this->scopeConfig->method('getValue')->willReturnMap([
            ['ordo_integrations/ai_agent/name', 'store', null, 'My Store'],
            ['ordo_integrations/ai_agent/description', 'store', null, 'A great store'],
            ['ordo_integrations/ai_agent/contact_email', 'store', null, 'shop@example.com'],
        ]);

        self::assertSame('My Store', $this->config->getAiAgentName());
        self::assertSame('A great store', $this->config->getAiAgentDescription());
        self::assertSame('shop@example.com', $this->config->getAiAgentContactEmail());
    }

    public function testAiAgentGettersReturnEmptyStringWhenUnset(): void
    {
        $this->scopeConfig->method('getValue')->willReturn(null);

        self::assertSame('', $this->config->getAiAgentName());
        self::assertSame('', $this->config->getAiAgentDescription());
        self::assertSame('', $this->config->getAiAgentContactEmail());
    }

    public function testIntGettersUseDefaultWhenUnset(): void
    {
        $this->scopeConfig->method('getValue')->willReturn(null);

        self::assertSame(3, $this->config->getReorderMinOrders());
        self::assertSame(2, $this->config->getReorderLeadDays());
        self::assertSame(120, $this->config->getAbandonedCartDelayMinutes());
        self::assertSame(1, $this->config->getAbandonedCartMaxReminders());
        self::assertSame(60, $this->config->getBrowseAbandonmentDelayMinutes());
        self::assertSame(1, $this->config->getBrowseAbandonmentMaxReminders());
        self::assertSame(2, $this->config->getOfferLeadDays());
        self::assertSame(1, $this->config->getOfferMaxSelfExtensions());
        self::assertSame(7, $this->config->getOfferSelfExtensionDays());
        self::assertSame(80, $this->config->getCreditLimitWarningThreshold());
        self::assertSame(14, $this->config->getReviewRequestDelayDays());
        self::assertSame(7, $this->config->getCreditLimitAlertCooldownDays());
        self::assertSame(90, $this->config->getWinBackInactiveDays());
        self::assertSame(3, $this->config->getClvProjectionYears());
        self::assertSame(1, $this->config->getClvMinTenureMonths());
        self::assertSame(2, $this->config->getOrderApprovalEscalationDays());
        self::assertSame(3, $this->config->getOrderApprovalEscalationMaxRemindersPerTier());
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
        self::assertSame(14, $this->config->getAttributionWindowDays());
        self::assertSame(21, $this->config->getQuietHoursStartHour());
        self::assertSame(8, $this->config->getQuietHoursEndHour());
        self::assertSame(1, $this->config->getTwilioMaxRequestsPerSecond());
        self::assertSame(5, $this->config->getWhatsAppMaxRequestsPerSecond());
        self::assertSame(20, $this->config->getPushMaxRequestsPerSecond());
        self::assertSame(5, $this->config->getWebhookMaxRequestsPerSecond());
        self::assertSame(200, $this->config->getPriceWatchScanBatchSize());
        self::assertSame(2, $this->config->getAiMaxRequestsPerSecond());
        self::assertSame(24, $this->config->getAbandonedCartFallbackDelayHours());
        self::assertSame(0, $this->config->getAbandonedCartFallbackWhatsAppTemplateId());
        self::assertSame(100, $this->config->getAbTestMinSampleSize());
        self::assertSame(60, $this->config->getAiAgentRateLimitPerMinute());
        self::assertSame(5, $this->config->getAiAgentEstimatedDeliveryDays());
    }

    public function testOutboundRateLimitGettersReturnAnExplicitZeroRatherThanFallingBackToTheDefault(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('0');

        self::assertSame(0, $this->config->getTwilioMaxRequestsPerSecond());
        self::assertSame(0, $this->config->getWhatsAppMaxRequestsPerSecond());
        self::assertSame(0, $this->config->getPushMaxRequestsPerSecond());
        self::assertSame(0, $this->config->getWebhookMaxRequestsPerSecond());
        self::assertSame(0, $this->config->getAiMaxRequestsPerSecond());
    }

    public function testWebhookOutboundUrlReturnsTheConfiguredValue(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('https://erp.example.test/ordo-webhook');

        self::assertSame('https://erp.example.test/ordo-webhook', $this->config->getWebhookOutboundUrl());
    }

    public function testWebhookOutboundUrlIsEmptyWhenUnconfigured(): void
    {
        $this->scopeConfig->method('getValue')->willReturn(null);

        self::assertSame('', $this->config->getWebhookOutboundUrl());
    }

    public function testGetOllamaBaseUrlReturnsTheConfiguredValue(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('http://ollama.local:11434');

        self::assertSame('http://ollama.local:11434', $this->config->getOllamaBaseUrl());
    }

    public function testGetOllamaBaseUrlIsEmptyWhenUnconfigured(): void
    {
        $this->scopeConfig->method('getValue')->willReturn(null);

        self::assertSame('', $this->config->getOllamaBaseUrl());
    }

    public function testGetOllamaModelReturnsTheConfiguredValue(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('llama3.1');

        self::assertSame('llama3.1', $this->config->getOllamaModel());
    }

    public function testGetOllamaModelFallsBackToLlama32WhenUnconfigured(): void
    {
        $this->scopeConfig->method('getValue')->willReturn(null);

        self::assertSame('llama3.2', $this->config->getOllamaModel());
    }

    public function testGetAbandonedCartFallbackChannelReturnsTheConfiguredValue(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('whatsapp');

        self::assertSame('whatsapp', $this->config->getAbandonedCartFallbackChannel());
    }

    public function testGetAbandonedCartFallbackChannelFallsBackToSmsWhenUnconfigured(): void
    {
        $this->scopeConfig->method('getValue')->willReturn(null);

        self::assertSame(AbandonedCartFallbackChannel::SMS, $this->config->getAbandonedCartFallbackChannel());
    }

    public function testWebhookSecretsDecryptTheirConfiguredValue(): void
    {
        $this->scopeConfig->method('getValue')->willReturnMap([
            ['ordo_channels/webhook/outbound_secret', 'store', null, 'webhook-outbound-secret'],
            ['ordo_channels/webhook/inbound_secret', 'store', null, 'webhook-inbound-secret'],
        ]);

        self::assertSame('webhook-outbound-secret', $this->config->getWebhookOutboundSecret());
        self::assertSame('webhook-inbound-secret', $this->config->getWebhookInboundSecret());
    }

    public function testOrderApprovalEscalationChainEmailsSplitsAndTrimsLines(): void
    {
        $this->scopeConfig->method('getValue')->willReturn("first@example.com\n second@example.com \r\n\nthird@example.com");

        self::assertSame(
            ['first@example.com', 'second@example.com', 'third@example.com'],
            $this->config->getOrderApprovalEscalationChainEmails()
        );
    }

    public function testOrderApprovalEscalationChainEmailsIsEmptyWhenUnconfigured(): void
    {
        $this->scopeConfig->method('getValue')->willReturn(null);

        self::assertSame([], $this->config->getOrderApprovalEscalationChainEmails());
    }

    public function testQuietHoursHourGettersClampOutOfRangeConfigValues(): void
    {
        $this->scopeConfig->method('getValue')->willReturnMap([
            ['ordo_channels/quiet_hours/start_hour', 'store', null, '99'],
            ['ordo_channels/quiet_hours/end_hour', 'store', null, '-5'],
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

    public function testGetReorderAtRiskDriftRatioUsesDefaultWhenUnset(): void
    {
        $this->scopeConfig->method('getValue')->willReturn(null);

        self::assertSame(0.75, $this->config->getReorderAtRiskDriftRatio());
    }

    public function testGetReorderAtRiskDriftRatioUsesConfiguredValue(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('0.9');

        self::assertSame(0.9, $this->config->getReorderAtRiskDriftRatio());
    }

    public function testObscureFieldGettersDecryptTheirConfiguredValue(): void
    {
        $this->scopeConfig->method('getValue')->willReturnMap([
            ['ordo_channels/email/sendgrid_webhook_verification_key', 'store', null, 'sg-verify-secret'],
            ['ordo_channels/whatsapp/access_token', 'store', null, 'wa-access-secret'],
            ['ordo_channels/whatsapp/app_secret', 'store', null, 'wa-app-secret'],
            ['ordo_channels/whatsapp/webhook_verify_token', 'store', null, 'wa-verify-secret'],
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
            ['ordo_channels/whatsapp/phone_number_id', 'store', null, '123456789012345'],
            ['ordo_channels/whatsapp/business_account_id', 'store', null, '987654321098765'],
        ]);

        self::assertSame('123456789012345', $this->config->getWhatsAppPhoneNumberId());
        self::assertSame('987654321098765', $this->config->getWhatsAppBusinessAccountId());
    }

    public function testGetLoyaltyPointsPerDollarDelegatesToScopeConfig(): void
    {
        $this->scopeConfig->method('getValue')->willReturnMap([
            ['ordo_automation/loyalty_points/points_per_dollar', 'store', null, '3'],
        ]);

        self::assertSame(3, $this->config->getLoyaltyPointsPerDollar());
    }

    public function testGetLoyaltyPointsPerDollarDefaultsToOne(): void
    {
        self::assertSame(1, $this->config->getLoyaltyPointsPerDollar());
    }

    public function testGetLoyaltyPointsRedemptionCostDelegatesToScopeConfig(): void
    {
        $this->scopeConfig->method('getValue')->willReturnMap([
            ['ordo_automation/loyalty_points/redemption_cost', 'store', null, '250'],
        ]);

        self::assertSame(250, $this->config->getLoyaltyPointsRedemptionCost());
    }

    public function testGetLoyaltyPointsRedemptionCostDefaultsToFiveHundred(): void
    {
        self::assertSame(500, $this->config->getLoyaltyPointsRedemptionCost());
    }

    public function testGetLoyaltyPointsRedemptionRuleIdDelegatesToScopeConfig(): void
    {
        $this->scopeConfig->method('getValue')->willReturnMap([
            ['ordo_automation/loyalty_points/redemption_rule_id', 'store', null, '9'],
        ]);

        self::assertSame(9, $this->config->getLoyaltyPointsRedemptionRuleId());
    }

    public function testGetLoyaltyPointsRedemptionRuleIdDefaultsToZero(): void
    {
        self::assertSame(0, $this->config->getLoyaltyPointsRedemptionRuleId());
    }

    public function testGetLoyaltyPointsExpiryDaysDelegatesToScopeConfig(): void
    {
        $this->scopeConfig->method('getValue')->willReturnMap([
            ['ordo_automation/loyalty_points/expiry_days', 'store', null, '180'],
        ]);

        self::assertSame(180, $this->config->getLoyaltyPointsExpiryDays());
    }

    public function testGetLoyaltyPointsExpiryDaysDefaultsToZero(): void
    {
        self::assertSame(0, $this->config->getLoyaltyPointsExpiryDays());
    }
}
