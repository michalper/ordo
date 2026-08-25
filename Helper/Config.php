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
}
