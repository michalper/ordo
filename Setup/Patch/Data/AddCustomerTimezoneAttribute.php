<?php
declare(strict_types=1);

namespace Ordo\Automation\Setup\Patch\Data;

use Ordo\Automation\Setup\Patch\AbstractCustomerAttributePatch;

/**
 * Adds a dedicated "timezone" customer attribute (an IANA zone identifier, e.g. "Europe/Warsaw")
 * for campaign quiet hours (Model\Campaign\CustomerTimezoneResolver) — closes the "full
 * per-customer timezone" scope decision for the ROADMAP.md "No time-zone-aware quiet hours" gap.
 * Deliberately NOT store/website-scoped `general/locale/timezone`: that's one value per store
 * view, not per customer, and doesn't help when customers in the same store span multiple real
 * time zones. Nothing populates this automatically (no geo-IP/browser detection) — an admin (or,
 * once a storefront form is added in a later phase, the customer themselves) sets it explicitly;
 * CustomerTimezoneResolver falls back to the store's configured timezone whenever it's unset.
 */
class AddCustomerTimezoneAttribute extends AbstractCustomerAttributePatch
{
    public const string ATTRIBUTE_CODE = 'ordo_timezone';

    public static function getDependencies(): array
    {
        return [];
    }

    protected function getAttributes(): array
    {
        return [
            self::ATTRIBUTE_CODE => [
                'type' => 'varchar',
                'label' => 'Timezone (IANA, e.g. Europe/Warsaw) — used for campaign quiet hours',
                'position' => 270,
            ],
        ];
    }
}
