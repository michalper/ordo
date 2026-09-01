<?php
declare(strict_types=1);

namespace Ordo\Automation\Setup\Patch\Data;

use Magento\Customer\Setup\CustomerSetupFactory;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * The "assigned rep" relationship, kept as three plain customer attributes rather than a
 * separate rep entity — a rep is just whoever's name/email/phone is on the customer record.
 * Simple enough that assigning/reassigning a customer is a single admin form edit, no extra
 * grid to maintain. SalesRepEmailContext reads these three attributes at email-render time.
 */
class AddSalesRepAttributes implements DataPatchInterface
{
    public const ATTRIBUTE_REP_NAME = 'ordo_sales_rep_name';
    public const ATTRIBUTE_REP_EMAIL = 'ordo_sales_rep_email';
    public const ATTRIBUTE_REP_PHONE = 'ordo_sales_rep_phone';

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly CustomerSetupFactory $customerSetupFactory
    ) {
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }

    public function apply(): self
    {
        $this->moduleDataSetup->getConnection()->startSetup();

        $customerSetup = $this->customerSetupFactory->create(['setup' => $this->moduleDataSetup]);

        $attributes = [
            self::ATTRIBUTE_REP_NAME => ['label' => 'Assigned Sales Rep — Name', 'position' => 230],
            self::ATTRIBUTE_REP_EMAIL => ['label' => 'Assigned Sales Rep — Email', 'position' => 240],
            self::ATTRIBUTE_REP_PHONE => ['label' => 'Assigned Sales Rep — Phone', 'position' => 250],
        ];

        foreach ($attributes as $code => $config) {
            $customerSetup->addAttribute(
                \Magento\Customer\Model\Customer::ENTITY,
                $code,
                [
                    'type' => 'varchar',
                    'label' => $config['label'],
                    'input' => 'text',
                    'required' => false,
                    'visible' => true,
                    'user_defined' => true,
                    'group' => 'General',
                    'position' => $config['position'],
                    'system' => false,
                ]
            );

            $attribute = $customerSetup->getEavConfig()->getAttribute(\Magento\Customer\Model\Customer::ENTITY, $code);
            $attribute->setData('used_in_forms', ['adminhtml_customer']);
            $attribute->setData('scope', ScopedAttributeInterface::SCOPE_STORE);
            $attribute->save();
        }

        $this->moduleDataSetup->getConnection()->endSetup();

        return $this;
    }
}
