<?php
declare(strict_types=1);

namespace Ordo\Automation\Setup\Patch\Data;

/**
 * Deliberately minimal stand-in for a company/sub-account hierarchy: rather than modeling
 * companies, admins and buyers as separate entities, every customer gets an optional spend
 * limit and an optional approval-admin email. If both are set, their orders above the limit
 * get held for approval by whoever that email belongs to. No B2B Commerce entity required.
 */
class AddCustomerSpendLimitAttributes extends AbstractCustomerAttributePatch
{
    public const string ATTRIBUTE_SPEND_LIMIT = 'ordo_order_spend_limit';
    public const string ATTRIBUTE_APPROVAL_ADMIN_EMAIL = 'ordo_approval_admin_email';

    public static function getDependencies(): array
    {
        return [AddCustomerCreditLimitAttribute::class];
    }

    protected function getAttributes(): array
    {
        return [
            self::ATTRIBUTE_SPEND_LIMIT => [
                'type' => 'decimal',
                'label' => 'Order Spend Limit (requires approval above this amount)',
                'default' => '0.0000',
                'position' => 210,
            ],
            self::ATTRIBUTE_APPROVAL_ADMIN_EMAIL => [
                'type' => 'varchar',
                'label' => 'Order Approval Admin Email',
                'position' => 220,
            ],
        ];
    }
}
