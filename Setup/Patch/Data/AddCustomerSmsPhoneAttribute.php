<?php
declare(strict_types=1);

namespace Ordo\Automation\Setup\Patch\Data;

/**
 * Adds a dedicated "SMS phone" customer attribute for the send_sms campaign action — deliberately
 * NOT the core customer address telephone (a customer can have several addresses, or none, and
 * the address telephone isn't guaranteed to be an SMS-capable mobile number). Kept as simple as
 * the credit-limit/sales-rep attributes: a single text field, editable in the admin customer form.
 */
class AddCustomerSmsPhoneAttribute extends AbstractCustomerAttributePatch
{
    public const string ATTRIBUTE_CODE = 'ordo_sms_phone';

    public static function getDependencies(): array
    {
        return [];
    }

    protected function getAttributes(): array
    {
        return [
            self::ATTRIBUTE_CODE => [
                'type' => 'varchar',
                'label' => 'SMS Phone',
                'position' => 260,
            ],
        ];
    }
}
