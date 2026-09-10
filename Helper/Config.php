<?php
declare(strict_types=1);

namespace Ordo\Automation\Helper;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;

class Config
{
    private const string XML_PATH_REORDER_ENABLED = 'ordo_automation/reorder/enabled';
    private const string XML_PATH_REORDER_MIN_ORDERS = 'ordo_automation/reorder/min_orders';
    private const string XML_PATH_REORDER_LEAD_DAYS = 'ordo_automation/reorder/lead_days';

    private const string XML_PATH_CART_ENABLED = 'ordo_automation/abandoned_cart/enabled';
    private const string XML_PATH_CART_DELAY_MINUTES = 'ordo_automation/abandoned_cart/delay_minutes';
    private const string XML_PATH_CART_MIN_SUBTOTAL = 'ordo_automation/abandoned_cart/min_subtotal';
    private const string XML_PATH_CART_MAX_REMINDERS = 'ordo_automation/abandoned_cart/max_reminders';

    private const string XML_PATH_OFFER_ENABLED = 'ordo_automation/offer/enabled';
    private const string XML_PATH_OFFER_LEAD_DAYS = 'ordo_automation/offer/lead_days';
    private const string XML_PATH_OFFER_MAX_SELF_EXTENSIONS = 'ordo_automation/offer/max_self_extensions';
    private const string XML_PATH_OFFER_SELF_EXTENSION_DAYS = 'ordo_automation/offer/self_extension_days';

    private const string XML_PATH_CREDIT_ENABLED = 'ordo_automation/credit_limit/enabled';
    private const string XML_PATH_CREDIT_WARNING_THRESHOLD = 'ordo_automation/credit_limit/warning_threshold_percent';
    private const string XML_PATH_CREDIT_COOLDOWN_DAYS = 'ordo_automation/credit_limit/cooldown_days';
    private const string XML_PATH_CREDIT_BLOCK_CHECKOUT_ENABLED = 'ordo_automation/credit_limit/block_checkout_enabled';

    private const string XML_PATH_LIFECYCLE_ENABLED = 'ordo_automation/lifecycle/enabled';
    private const string XML_PATH_LIFECYCLE_WIN_BACK_INACTIVE_DAYS = 'ordo_automation/lifecycle/win_back_inactive_days';

    private const string XML_PATH_APPROVAL_ENABLED = 'ordo_automation/order_approval/enabled';
    private const string XML_PATH_APPROVAL_ESCALATION_DAYS = 'ordo_automation/order_approval/escalation_days';

    private const string XML_PATH_SALES_REP_DIGEST_ENABLED = 'ordo_automation/sales_rep/digest_enabled';

    private const string XML_PATH_TRACKING_ENABLED = 'ordo_automation/tracking/enabled';
    private const string XML_PATH_TRACKING_RETENTION_DAYS = 'ordo_automation/tracking/retention_days';
    private const string XML_PATH_TRACKING_VIEW_THRESHOLD = 'ordo_automation/tracking/view_threshold';
    private const string XML_PATH_TRACKING_CLICK_THRESHOLD = 'ordo_automation/tracking/click_threshold';

    private const string XML_PATH_POPUP_ENABLED = 'ordo_automation/tracking/popup_enabled';
    private const string XML_PATH_POPUP_POLL_INTERVAL_SECONDS = 'ordo_automation/tracking/popup_poll_interval_seconds';
    private const string XML_PATH_POPUP_FREQUENCY_CAP_HOURS = 'ordo_automation/tracking/popup_frequency_cap_hours';
    private const string XML_PATH_NOTIFICATION_ENABLED = 'ordo_automation/tracking/notification_enabled';
    private const string XML_PATH_NOTIFICATION_POLL_INTERVAL_SECONDS
        = 'ordo_automation/tracking/notification_poll_interval_seconds';
    private const string XML_PATH_NPS_SURVEY_ENABLED = 'ordo_automation/tracking/nps_survey_enabled';
    private const string XML_PATH_NPS_SURVEY_POLL_INTERVAL_SECONDS
        = 'ordo_automation/tracking/nps_survey_poll_interval_seconds';

    private const string XML_PATH_FREE_GIFT_ENABLED = 'ordo_automation/free_gift/enabled';

    private const string XML_PATH_LEAD_SCORING_ENABLED = 'ordo_automation/lead_scoring/enabled';
    private const string XML_PATH_LEAD_SCORING_THRESHOLD = 'ordo_automation/lead_scoring/score_threshold';
    private const string XML_PATH_LOYALTY_SILVER_THRESHOLD = 'ordo_automation/lead_scoring/loyalty_silver_threshold';
    private const string XML_PATH_LOYALTY_GOLD_THRESHOLD = 'ordo_automation/lead_scoring/loyalty_gold_threshold';

    private const string XML_PATH_SMS_ENABLED = 'ordo_automation/sms/enabled';
    private const string XML_PATH_SMS_TWILIO_ACCOUNT_SID = 'ordo_automation/sms/twilio_account_sid';
    private const string XML_PATH_SMS_TWILIO_AUTH_TOKEN = 'ordo_automation/sms/twilio_auth_token';
    private const string XML_PATH_SMS_TWILIO_API_KEY_SID = 'ordo_automation/sms/twilio_api_key_sid';
    private const string XML_PATH_SMS_TWILIO_API_KEY_SECRET = 'ordo_automation/sms/twilio_api_key_secret';
    private const string XML_PATH_SMS_TWILIO_FROM_NUMBER = 'ordo_automation/sms/twilio_from_number';

    private const string XML_PATH_GOOGLE_ADS_CLIENT_ID = 'ordo_automation/ad_audience_sync/google_ads_client_id';
    private const string XML_PATH_GOOGLE_ADS_CLIENT_SECRET
        = 'ordo_automation/ad_audience_sync/google_ads_client_secret';
    private const string XML_PATH_GOOGLE_ADS_REFRESH_TOKEN
        = 'ordo_automation/ad_audience_sync/google_ads_refresh_token';
    private const string XML_PATH_GOOGLE_ADS_DEVELOPER_TOKEN
        = 'ordo_automation/ad_audience_sync/google_ads_developer_token';
    private const string XML_PATH_GOOGLE_ADS_LOGIN_CUSTOMER_ID
        = 'ordo_automation/ad_audience_sync/google_ads_login_customer_id';
    private const string XML_PATH_META_ACCESS_TOKEN = 'ordo_automation/ad_audience_sync/meta_access_token';
    private const string XML_PATH_META_AD_ACCOUNT_ID = 'ordo_automation/ad_audience_sync/meta_ad_account_id';

    private const string XML_PATH_SHOPPING_FEED_ENABLED = 'ordo_automation/shopping_feed/enabled';
    private const string XML_PATH_SHOPPING_FEED_TITLE = 'ordo_automation/shopping_feed/title';
    private const string XML_PATH_SHOPPING_FEED_DESCRIPTION = 'ordo_automation/shopping_feed/description';

    private const string XML_PATH_EMAIL_SENDGRID_WEBHOOK_VERIFICATION_KEY
        = 'ordo_automation/email/sendgrid_webhook_verification_key';

    private const string XML_PATH_WHATSAPP_ENABLED = 'ordo_automation/whatsapp/enabled';
    private const string XML_PATH_WHATSAPP_ACCESS_TOKEN = 'ordo_automation/whatsapp/access_token';
    private const string XML_PATH_WHATSAPP_PHONE_NUMBER_ID = 'ordo_automation/whatsapp/phone_number_id';
    private const string XML_PATH_WHATSAPP_BUSINESS_ACCOUNT_ID = 'ordo_automation/whatsapp/business_account_id';
    private const string XML_PATH_WHATSAPP_APP_SECRET = 'ordo_automation/whatsapp/app_secret';
    private const string XML_PATH_WHATSAPP_WEBHOOK_VERIFY_TOKEN = 'ordo_automation/whatsapp/webhook_verify_token';

    private const string XML_PATH_PUSH_ENABLED = 'ordo_automation/push/enabled';
    private const string XML_PATH_PUSH_VAPID_PUBLIC_KEY = 'ordo_automation/push/vapid_public_key';
    private const string XML_PATH_PUSH_VAPID_PRIVATE_KEY = 'ordo_automation/push/vapid_private_key';
    private const string XML_PATH_PUSH_VAPID_SUBJECT = 'ordo_automation/push/vapid_subject';

    private const string XML_PATH_FREQUENCY_CAP_ENABLED = 'ordo_automation/frequency_cap/enabled';
    private const string XML_PATH_FREQUENCY_CAP_MAX_MESSAGES = 'ordo_automation/frequency_cap/max_messages';
    private const string XML_PATH_FREQUENCY_CAP_WINDOW_HOURS = 'ordo_automation/frequency_cap/window_hours';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor
    ) {
    }

    /**
     * Every `type="obscure"` field in system.xml is encrypted at rest (via
     * Magento\Config\Model\Config\Backend\Encrypted::beforeSave()) — but that backend model only
     * decrypts when its own Value object is loaded (i.e. the admin config edit form), NOT when
     * read at runtime through ScopeConfigInterface::getValue(), which returns the raw encrypted
     * string. Every obscure-field getter in this class routes through here instead of a bare
     * getValue() call, or it silently hands callers ciphertext instead of the real secret — found
     * the hard way: this shipped for months with every webhook signature check and every
     * third-party API call (Twilio, Google Ads, Meta, SendGrid) actually authenticating with
     * ciphertext, never the real credential.
     */
    private function decryptedConfig(string $path, ?int $storeId): string
    {
        $value = (string) $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);

        return $value === '' ? '' : $this->encryptor->decrypt($value);
    }

    /**
     * Every int-valued setting in this class goes through here instead of `?: $default` —
     * `?:` treats a deliberately-set `0` the same as "not configured" and silently falls back
     * to the default, which is wrong for any setting where 0 is a meaningful value (e.g.
     * "retention_days = 0" meaning "keep nothing"). Found the hard way: see
     * VERIFICATION.md #19.
     */
    private function intConfig(string $path, int $default, ?int $storeId): int
    {
        $value = $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);

        return $value !== null && $value !== '' ? (int) $value : $default;
    }

    public function isReorderReminderEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_REORDER_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getReorderMinOrders(?int $storeId = null): int
    {
        return $this->intConfig(self::XML_PATH_REORDER_MIN_ORDERS, 3, $storeId);
    }

    public function getReorderLeadDays(?int $storeId = null): int
    {
        return $this->intConfig(self::XML_PATH_REORDER_LEAD_DAYS, 2, $storeId);
    }

    public function isAbandonedCartEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_CART_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getAbandonedCartDelayMinutes(?int $storeId = null): int
    {
        return $this->intConfig(self::XML_PATH_CART_DELAY_MINUTES, 120, $storeId);
    }

    public function getAbandonedCartMinSubtotal(?int $storeId = null): float
    {
        return (float) $this->scopeConfig->getValue(
            self::XML_PATH_CART_MIN_SUBTOTAL,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getAbandonedCartMaxReminders(?int $storeId = null): int
    {
        return $this->intConfig(self::XML_PATH_CART_MAX_REMINDERS, 1, $storeId);
    }

    public function isOfferReminderEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_OFFER_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getOfferLeadDays(?int $storeId = null): int
    {
        return $this->intConfig(self::XML_PATH_OFFER_LEAD_DAYS, 2, $storeId);
    }

    public function getOfferMaxSelfExtensions(?int $storeId = null): int
    {
        return $this->intConfig(self::XML_PATH_OFFER_MAX_SELF_EXTENSIONS, 1, $storeId);
    }

    public function getOfferSelfExtensionDays(?int $storeId = null): int
    {
        return $this->intConfig(self::XML_PATH_OFFER_SELF_EXTENSION_DAYS, 7, $storeId);
    }

    public function isCreditLimitAlertEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_CREDIT_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getCreditLimitWarningThreshold(?int $storeId = null): int
    {
        return $this->intConfig(self::XML_PATH_CREDIT_WARNING_THRESHOLD, 80, $storeId);
    }

    public function getCreditLimitAlertCooldownDays(?int $storeId = null): int
    {
        return $this->intConfig(self::XML_PATH_CREDIT_COOLDOWN_DAYS, 7, $storeId);
    }

    public function isCreditLimitCheckoutBlockEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_CREDIT_BLOCK_CHECKOUT_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function isLifecycleEmailsEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_LIFECYCLE_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getWinBackInactiveDays(?int $storeId = null): int
    {
        return $this->intConfig(self::XML_PATH_LIFECYCLE_WIN_BACK_INACTIVE_DAYS, 90, $storeId);
    }

    public function isOrderApprovalEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_APPROVAL_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getOrderApprovalEscalationDays(?int $storeId = null): int
    {
        return $this->intConfig(self::XML_PATH_APPROVAL_ESCALATION_DAYS, 2, $storeId);
    }

    public function isSalesRepDigestEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_SALES_REP_DIGEST_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function isTrackingEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_TRACKING_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getTrackingRetentionDays(?int $storeId = null): int
    {
        return $this->intConfig(self::XML_PATH_TRACKING_RETENTION_DAYS, 7, $storeId);
    }

    public function getTrackingViewThreshold(?int $storeId = null): int
    {
        return $this->intConfig(self::XML_PATH_TRACKING_VIEW_THRESHOLD, 3, $storeId);
    }

    /**
     * A click is a higher-intent signal than a view — the default of 1 means a single click
     * on a tracked element is enough to tag the visitor/customer, unlike getTrackingViewThreshold()'s
     * default of 3 for page/product/category views.
     */
    public function getTrackingClickThreshold(?int $storeId = null): int
    {
        return $this->intConfig(self::XML_PATH_TRACKING_CLICK_THRESHOLD, 1, $storeId);
    }

    public function isPopupEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_POPUP_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getPopupPollIntervalSeconds(?int $storeId = null): int
    {
        return $this->intConfig(self::XML_PATH_POPUP_POLL_INTERVAL_SECONDS, 15, $storeId);
    }

    /**
     * Minimum gap, in hours, between two popups delivered to the same visitor/customer.
     * 0 disables capping (every campaign popup action always queues a new popup).
     */
    public function getPopupFrequencyCapHours(?int $storeId = null): int
    {
        return $this->intConfig(self::XML_PATH_POPUP_FREQUENCY_CAP_HOURS, 24, $storeId);
    }

    public function isNotificationEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_NOTIFICATION_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getNotificationPollIntervalSeconds(?int $storeId = null): int
    {
        return $this->intConfig(self::XML_PATH_NOTIFICATION_POLL_INTERVAL_SECONDS, 20, $storeId);
    }

    public function isNpsSurveyEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_NPS_SURVEY_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getNpsSurveyPollIntervalSeconds(?int $storeId = null): int
    {
        return $this->intConfig(self::XML_PATH_NPS_SURVEY_POLL_INTERVAL_SECONDS, 25, $storeId);
    }

    public function isFreeGiftEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_FREE_GIFT_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function isLeadScoringEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_LEAD_SCORING_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getScoreThreshold(?int $storeId = null): int
    {
        return $this->intConfig(self::XML_PATH_LEAD_SCORING_THRESHOLD, 100, $storeId);
    }

    /**
     * Bronze is implicit (any score below this) - only Silver/Gold have their own threshold,
     * same "just the ones that need a value" shape as the rest of this class.
     */
    public function getLoyaltySilverThreshold(?int $storeId = null): int
    {
        return $this->intConfig(self::XML_PATH_LOYALTY_SILVER_THRESHOLD, 100, $storeId);
    }

    public function getLoyaltyGoldThreshold(?int $storeId = null): int
    {
        return $this->intConfig(self::XML_PATH_LOYALTY_GOLD_THRESHOLD, 500, $storeId);
    }

    public function isSmsEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_SMS_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getTwilioAccountSid(?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_SMS_TWILIO_ACCOUNT_SID,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Only used to verify the X-Twilio-Signature on the inbound status-callback webhook
     * (Controller\Sms\StatusCallback) — Twilio always signs webhooks with the Account Auth
     * Token, never with an API Key secret, so this can't be retired even though outbound sends
     * no longer use it. See getTwilioApiKeySid()/getTwilioApiKeySecret() for those.
     */
    public function getTwilioAuthToken(?int $storeId = null): string
    {
        return $this->decryptedConfig(self::XML_PATH_SMS_TWILIO_AUTH_TOKEN, $storeId);
    }

    /**
     * A Restricted API Key SID scoped to Programmable Messaging, per Twilio's own recommendation
     * to authenticate routine API calls without granting full-account access. Used (together with
     * getTwilioApiKeySecret() and the Account SID) to authenticate outbound SMS sends in
     * TwilioSmsSender — not to be confused with getTwilioAccountSid().
     */
    public function getTwilioApiKeySid(?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_SMS_TWILIO_API_KEY_SID,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getTwilioApiKeySecret(?int $storeId = null): string
    {
        return $this->decryptedConfig(self::XML_PATH_SMS_TWILIO_API_KEY_SECRET, $storeId);
    }

    public function getTwilioFromNumber(?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_SMS_TWILIO_FROM_NUMBER,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getGoogleAdsClientId(?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_GOOGLE_ADS_CLIENT_ID,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getGoogleAdsClientSecret(?int $storeId = null): string
    {
        return $this->decryptedConfig(self::XML_PATH_GOOGLE_ADS_CLIENT_SECRET, $storeId);
    }

    public function getGoogleAdsRefreshToken(?int $storeId = null): string
    {
        return $this->decryptedConfig(self::XML_PATH_GOOGLE_ADS_REFRESH_TOKEN, $storeId);
    }

    public function getGoogleAdsDeveloperToken(?int $storeId = null): string
    {
        return $this->decryptedConfig(self::XML_PATH_GOOGLE_ADS_DEVELOPER_TOKEN, $storeId);
    }

    public function getGoogleAdsLoginCustomerId(?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_GOOGLE_ADS_LOGIN_CUSTOMER_ID,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getMetaAccessToken(?int $storeId = null): string
    {
        return $this->decryptedConfig(self::XML_PATH_META_ACCESS_TOKEN, $storeId);
    }

    public function getMetaAdAccountId(?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_META_AD_ACCOUNT_ID,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function isShoppingFeedEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_SHOPPING_FEED_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getShoppingFeedTitle(?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_SHOPPING_FEED_TITLE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getShoppingFeedDescription(?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_SHOPPING_FEED_DESCRIPTION,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * SendGrid's own base64-encoded ECDSA (prime256v1) public verification key from the account's
     * Event Webhook settings page — Model\Email\SendGridSignatureValidator wraps this in PEM
     * armor before handing it to openssl_verify().
     */
    public function getSendGridWebhookVerificationKey(?int $storeId = null): string
    {
        return $this->decryptedConfig(self::XML_PATH_EMAIL_SENDGRID_WEBHOOK_VERIFICATION_KEY, $storeId);
    }

    public function isWhatsAppEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_WHATSAPP_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getWhatsAppAccessToken(?int $storeId = null): string
    {
        return $this->decryptedConfig(self::XML_PATH_WHATSAPP_ACCESS_TOKEN, $storeId);
    }

    public function getWhatsAppPhoneNumberId(?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_WHATSAPP_PHONE_NUMBER_ID,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getWhatsAppBusinessAccountId(?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_WHATSAPP_BUSINESS_ACCOUNT_ID,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Meta's own per-app secret, used only to verify Controller\WhatsApp\Webhook's incoming
     * X-Hub-Signature-256 header.
     */
    public function getWhatsAppAppSecret(?int $storeId = null): string
    {
        return $this->decryptedConfig(self::XML_PATH_WHATSAPP_APP_SECRET, $storeId);
    }

    /**
     * The arbitrary string this store's admin chose when registering the webhook URL in Meta's
     * App Dashboard — Controller\WhatsApp\Webhook's GET handshake echoes back hub_challenge only
     * if the request's hub_verify_token matches this exactly.
     */
    public function getWhatsAppWebhookVerifyToken(?int $storeId = null): string
    {
        return $this->decryptedConfig(self::XML_PATH_WHATSAPP_WEBHOOK_VERIFY_TOKEN, $storeId);
    }

    public function isPushEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_PUSH_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Base64url-encoded, uncompressed P-256 public key — sent to the browser verbatim as the
     * `applicationServerKey` for `pushManager.subscribe()`. Not a secret (see
     * getVapidPrivateKey() for that), so this is a plain text field, not obscure/encrypted.
     */
    public function getVapidPublicKey(?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_PUSH_VAPID_PUBLIC_KEY,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Base64url-encoded P-256 private key (PKCS#8/SEC1 raw scalar) — Model\Push\VapidTokenBuilder
     * signs every outbound push request's VAPID JWT with this. Never sent to the browser.
     */
    public function getVapidPrivateKey(?int $storeId = null): string
    {
        return $this->decryptedConfig(self::XML_PATH_PUSH_VAPID_PRIVATE_KEY, $storeId);
    }

    /**
     * A `mailto:` address or `https://` URL identifying this site's operator, per the VAPID spec
     * (RFC 8292) — carried in every VAPID JWT's `sub` claim so a push service can contact the
     * sender if its traffic needs throttling/investigating.
     */
    public function getVapidSubject(?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_PUSH_VAPID_SUBJECT,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Opt-in (default off) - see Model\Campaign\FrequencyCapManager's own docblock for why this
     * shouldn't suddenly start suppressing sends for an install that never asked for it.
     */
    public function isFrequencyCapEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_FREQUENCY_CAP_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getFrequencyCapMaxMessages(?int $storeId = null): int
    {
        return $this->intConfig(self::XML_PATH_FREQUENCY_CAP_MAX_MESSAGES, 5, $storeId);
    }

    public function getFrequencyCapWindowHours(?int $storeId = null): int
    {
        return $this->intConfig(self::XML_PATH_FREQUENCY_CAP_WINDOW_HOURS, 24, $storeId);
    }
}
