<?php
declare(strict_types=1);

namespace Ordo\Automation\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Store\Model\ScopeInterface;

class Config extends AbstractHelper
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

    public function __construct(Context $context)
    {
        parent::__construct($context);
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
        return (int) $this->scopeConfig->getValue(
            self::XML_PATH_REORDER_MIN_ORDERS,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ) ?: 3;
    }

    public function getReorderLeadDays(?int $storeId = null): int
    {
        return (int) $this->scopeConfig->getValue(
            self::XML_PATH_REORDER_LEAD_DAYS,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ) ?: 2;
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
        return (int) $this->scopeConfig->getValue(
            self::XML_PATH_CART_DELAY_MINUTES,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ) ?: 120;
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
        return (int) $this->scopeConfig->getValue(
            self::XML_PATH_CART_MAX_REMINDERS,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ) ?: 1;
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
        return (int) $this->scopeConfig->getValue(
            self::XML_PATH_OFFER_LEAD_DAYS,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ) ?: 2;
    }

    public function getOfferMaxSelfExtensions(?int $storeId = null): int
    {
        return (int) $this->scopeConfig->getValue(
            self::XML_PATH_OFFER_MAX_SELF_EXTENSIONS,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ) ?: 1;
    }

    public function getOfferSelfExtensionDays(?int $storeId = null): int
    {
        return (int) $this->scopeConfig->getValue(
            self::XML_PATH_OFFER_SELF_EXTENSION_DAYS,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ) ?: 7;
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
        return (int) $this->scopeConfig->getValue(
            self::XML_PATH_CREDIT_WARNING_THRESHOLD,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ) ?: 80;
    }

    public function getCreditLimitAlertCooldownDays(?int $storeId = null): int
    {
        return (int) $this->scopeConfig->getValue(
            self::XML_PATH_CREDIT_COOLDOWN_DAYS,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ) ?: 7;
    }
}
