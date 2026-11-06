<?php

declare(strict_types=1);

namespace Ordo\Automation\Model\Sms;

use Magento\Customer\Api\Data\CustomerInterface;
use Ordo\Automation\Setup\Patch\Data\AddCustomerSmsPhoneAttribute;

/**
 * Shared by Model\Campaign\Action\SendSms and SendWhatsApp — one phone number attribute
 * (ordo_sms_phone) serves both channels, since a customer only has one mobile number regardless
 * of which channel eventually messages it, and both actions need the identical E.164 sanity
 * check before spending a provider API call.
 */
class PhoneNumberResolver
{
    /**
     * E.164: a leading "+", then 8-15 digits total, first digit non-zero (ITU-T E.164 caps the
     * whole number, country code included, at 15 digits). Deliberately permissive beyond that —
     * this is a fail-fast sanity check before spending a provider API call, not a full
     * numbering-plan validator, so it doesn't try to validate per-country length/prefix rules.
     */
    public const string E164_PATTERN = '/^\+[1-9]\d{7,14}$/';

    public function resolve(CustomerInterface $customer): ?string
    {
        $phoneAttribute = $customer->getCustomAttribute(AddCustomerSmsPhoneAttribute::ATTRIBUTE_CODE);
        $phone = $phoneAttribute !== null ? trim((string) $phoneAttribute->getValue()) : '';

        return $phone !== '' ? $phone : null;
    }

    public function isValidE164(string $phone): bool
    {
        return (bool) preg_match(self::E164_PATTERN, $phone);
    }
}
