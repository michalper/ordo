<?php
declare(strict_types=1);

namespace Ordo\Automation\Helper;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class Config
{
    private const XML_PATH_REORDER_ENABLED = 'ordo_automation/reorder/enabled';
    private const XML_PATH_REORDER_MIN_ORDERS = 'ordo_automation/reorder/min_orders';
    private const XML_PATH_REORDER_LEAD_DAYS = 'ordo_automation/reorder/lead_days';

    private const XML_PATH_CART_ENABLED = 'ordo_automation/abandoned_cart/enabled';
    private const XML_PATH_CART_DELAY_MINUTES = 'ordo_automation/abandoned_cart/delay_minutes';
    private const XML_PATH_CART_MIN_SUBTOTAL = 'ordo_automation/abandoned_cart/min_subtotal';
    private const XML_PATH_CART_MAX_REMINDERS = 'ordo_automation/abandoned_cart/max_reminders';

    private const XML_PATH_OFFER_ENABLED = 'ordo_automation/offer/enabled';
    private const XML_PATH_OFFER_LEAD_DAYS = 'ordo_automation/offer/lead_days';
    private const XML_PATH_OFFER_MAX_SELF_EXTENSIONS = 'ordo_automation/offer/max_self_extensions';
    private const XML_PATH_OFFER_SELF_EXTENSION_DAYS = 'ordo_automation/offer/self_extension_days';

    private const XML_PATH_CREDIT_ENABLED = 'ordo_automation/credit_limit/enabled';
    private const XML_PATH_CREDIT_WARNING_THRESHOLD = 'ordo_automation/credit_limit/warning_threshold_percent';
    private const XML_PATH_CREDIT_COOLDOWN_DAYS = 'ordo_automation/credit_limit/cooldown_days';
    private const XML_PATH_CREDIT_BLOCK_CHECKOUT_ENABLED = 'ordo_automation/credit_limit/block_checkout_enabled';

    private const XML_PATH_LIFECYCLE_ENABLED = 'ordo_automation/lifecycle/enabled';
    private const XML_PATH_LIFECYCLE_WIN_BACK_INACTIVE_DAYS = 'ordo_automation/lifecycle/win_back_inactive_days';

    private const XML_PATH_APPROVAL_ENABLED = 'ordo_automation/order_approval/enabled';
    private const XML_PATH_APPROVAL_ESCALATION_DAYS = 'ordo_automation/order_approval/escalation_days';

    private const XML_PATH_SALES_REP_DIGEST_ENABLED = 'ordo_automation/sales_rep/digest_enabled';

    private const XML_PATH_TRACKING_ENABLED = 'ordo_automation/tracking/enabled';
    private const XML_PATH_TRACKING_RETENTION_DAYS = 'ordo_automation/tracking/retention_days';
    private const XML_PATH_TRACKING_VIEW_THRESHOLD = 'ordo_automation/tracking/view_threshold';
    private const XML_PATH_TRACKING_CLICK_THRESHOLD = 'ordo_automation/tracking/click_threshold';

    private const XML_PATH_POPUP_ENABLED = 'ordo_automation/tracking/popup_enabled';
    private const XML_PATH_POPUP_POLL_INTERVAL_SECONDS = 'ordo_automation/tracking/popup_poll_interval_seconds';
    private const XML_PATH_POPUP_FREQUENCY_CAP_HOURS = 'ordo_automation/tracking/popup_frequency_cap_hours';

    private const XML_PATH_FREE_GIFT_ENABLED = 'ordo_automation/free_gift/enabled';

    private const XML_PATH_LEAD_SCORING_ENABLED = 'ordo_automation/lead_scoring/enabled';
    private const XML_PATH_LEAD_SCORING_THRESHOLD = 'ordo_automation/lead_scoring/score_threshold';

    private const XML_PATH_SMS_ENABLED = 'ordo_automation/sms/enabled';
    private const XML_PATH_SMS_TWILIO_ACCOUNT_SID = 'ordo_automation/sms/twilio_account_sid';
    private const XML_PATH_SMS_TWILIO_AUTH_TOKEN = 'ordo_automation/sms/twilio_auth_token';
    private const XML_PATH_SMS_TWILIO_FROM_NUMBER = 'ordo_automation/sms/twilio_from_number';

    public function __construct(private readonly ScopeConfigInterface $scopeConfig)
    {
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
     * Decrypted automatically by ScopeConfigInterface::getValue() — the field's backend_model
     * (Magento\Config\Model\Config\Backend\Encrypted, see etc/adminhtml/system.xml) handles
     * decryption on read, no extra code needed here.
     */
    public function getTwilioAuthToken(?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_SMS_TWILIO_AUTH_TOKEN,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getTwilioFromNumber(?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_SMS_TWILIO_FROM_NUMBER,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }
}
