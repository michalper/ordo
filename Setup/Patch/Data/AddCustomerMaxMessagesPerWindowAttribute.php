<?php
declare(strict_types=1);

namespace Ordo\Automation\Setup\Patch\Data;

use Ordo\Automation\Setup\Patch\AbstractCustomerAttributePatch;

/**
 * Adds a customer-level "how often may we contact you" self-service preference -
 * Model\Campaign\FrequencyCapManager::hasCapacity() reads it alongside the admin-configured cap
 * and takes whichever is tighter. Deliberately a plain customer attribute, not a new column on
 * ordo_customer_consent (the table the admin brainstorm note that prompted this originally
 * suggested) - that table is one row per (customer_id, channel), but FrequencyCapManager's own
 * cap is channel-agnostic by design (see that class's own docblock), so a per-channel column
 * would have no single well-defined value to read. A plain customer attribute (same pattern
 * AddCustomerSmsPhoneAttribute/AddSalesRepAttributes already use) has exactly one value per
 * customer, matching the cap's own scope.
 */
class AddCustomerMaxMessagesPerWindowAttribute extends AbstractCustomerAttributePatch
{
    public const string ATTRIBUTE_CODE = 'ordo_max_messages_per_window';

    public static function getDependencies(): array
    {
        return [];
    }

    protected function getAttributes(): array
    {
        return [
            self::ATTRIBUTE_CODE => [
                'type' => 'int',
                'label' => 'Max Marketing Messages Per Window (self-service cap)',
                'position' => 280,
            ],
        ];
    }
}
