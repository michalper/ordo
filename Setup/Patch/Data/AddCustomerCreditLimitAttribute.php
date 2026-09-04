<?php
declare(strict_types=1);

namespace Ordo\Automation\Setup\Patch\Data;

/**
 * Adds the "credit limit" customer attribute Magento Open Source doesn't have natively
 * (it's an Adobe Commerce B2B "Company" feature there). Kept intentionally simple: a single
 * decimal on the customer, editable in the admin customer form — no company/hierarchy model.
 */
class AddCustomerCreditLimitAttribute extends AbstractCustomerAttributePatch
{
    public const string ATTRIBUTE_CODE = 'ordo_credit_limit';

    public static function getDependencies(): array
    {
        return [];
    }

    protected function getAttributes(): array
    {
        return [
            self::ATTRIBUTE_CODE => [
                'type' => 'decimal',
                'label' => 'Credit Limit',
                'default' => '0.0000',
                'position' => 200,
                'is_visible_in_grid' => true,
            ],
        ];
    }
}
