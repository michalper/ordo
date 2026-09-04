<?php
declare(strict_types=1);

namespace Ordo\Automation\Setup\Patch\Data;

/**
 * The "assigned rep" relationship, kept as three plain customer attributes rather than a
 * separate rep entity — a rep is just whoever's name/email/phone is on the customer record.
 * Simple enough that assigning/reassigning a customer is a single admin form edit, no extra
 * grid to maintain. SalesRepEmailContext reads these three attributes at email-render time.
 */
class AddSalesRepAttributes extends AbstractCustomerAttributePatch
{
    public const string ATTRIBUTE_REP_NAME = 'ordo_sales_rep_name';
    public const string ATTRIBUTE_REP_EMAIL = 'ordo_sales_rep_email';
    public const string ATTRIBUTE_REP_PHONE = 'ordo_sales_rep_phone';

    public static function getDependencies(): array
    {
        return [];
    }

    protected function getAttributes(): array
    {
        return [
            self::ATTRIBUTE_REP_NAME => [
                'type' => 'varchar',
                'label' => 'Assigned Sales Rep — Name',
                'position' => 230,
            ],
            self::ATTRIBUTE_REP_EMAIL => [
                'type' => 'varchar',
                'label' => 'Assigned Sales Rep — Email',
                'position' => 240,
            ],
            self::ATTRIBUTE_REP_PHONE => [
                'type' => 'varchar',
                'label' => 'Assigned Sales Rep — Phone',
                'position' => 250,
            ],
        ];
    }
}
