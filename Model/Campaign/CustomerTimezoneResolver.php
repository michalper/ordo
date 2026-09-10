<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Campaign;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Store\Model\ScopeInterface;
use Ordo\Automation\Setup\Patch\Data\AddCustomerTimezoneAttribute;

/**
 * Resolves the timezone campaign quiet hours (QuietHoursGate) should evaluate a customer's local
 * time against - the customer's own ordo_timezone attribute (AddCustomerTimezoneAttribute) when
 * set, otherwise falling back to this store's configured general/locale/timezone. Nothing in this
 * module or core Magento auto-detects a customer's real timezone (no geo-IP/browser reporting) -
 * an admin (or, on a future storefront form) sets the attribute explicitly; unset is the expected
 * default for most customers, not an error condition.
 *
 * Fail-soft by design: a missing/deleted customer, or a malformed/unrecognized zone string in the
 * attribute, must never crash or block a send - both fall back to the store's timezone exactly
 * the same as "attribute unset" does, same posture as the rest of the dispatcher's per-campaign
 * try/catch.
 */
class CustomerTimezoneResolver
{
    public function __construct(
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly TimezoneInterface $timezone
    ) {
    }

    public function resolve(int $customerId, ?int $storeId): \DateTimeZone
    {
        $zoneName = $this->customerTimezoneAttribute($customerId);

        if ($zoneName !== null) {
            try {
                return new \DateTimeZone($zoneName);
            } catch (\Exception) {
                // Malformed attribute value - fall through to the store default below.
            }
        }

        return new \DateTimeZone(
            $this->timezone->getConfigTimezone(ScopeInterface::SCOPE_STORE, $storeId !== null ? (string) $storeId : null)
        );
    }

    private function customerTimezoneAttribute(int $customerId): ?string
    {
        try {
            $customer = $this->customerRepository->getById($customerId);
        } catch (\Throwable) {
            return null;
        }

        $attribute = $customer->getCustomAttribute(AddCustomerTimezoneAttribute::ATTRIBUTE_CODE);
        $value = $attribute !== null ? trim((string) $attribute->getValue()) : '';

        return $value !== '' ? $value : null;
    }
}
