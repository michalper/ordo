<?php
declare(strict_types=1);

namespace Ordo\Automation\Setup\Patch\Data;

use Magento\Customer\Setup\CustomerSetupFactory;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Deliberately minimal stand-in for a company/sub-account hierarchy: rather than modeling
 * companies, admins and buyers as separate entities, every customer gets an optional spend
 * limit and an optional approval-admin email. If both are set, their orders above the limit
 * get held for approval by whoever that email belongs to. No B2B Commerce entity required.
 */
class AddCustomerSpendLimitAttributes implements DataPatchInterface
{
    public const ATTRIBUTE_SPEND_LIMIT = 'ordo_order_spend_limit';
    public const ATTRIBUTE_APPROVAL_ADMIN_EMAIL = 'ordo_approval_admin_email';

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly CustomerSetupFactory $customerSetupFactory
    ) {
    }

    public static function getDependencies(): array
    {
        return [\Ordo\Automation\Setup\Patch\Data\AddCustomerCreditLimitAttribute::class];
    }

    public function getAliases(): array
    {
        return [];
    }

    public function apply(): self
    {
        $this->moduleDataSetup->getConnection()->startSetup();

        $customerSetup = $this->customerSetupFactory->create(['setup' => $this->moduleDataSetup]);

        $customerSetup->addAttribute(
            \Magento\Customer\Model\Customer::ENTITY,
            self::ATTRIBUTE_SPEND_LIMIT,
            [
                'type' => 'decimal',
                'label' => 'Order Spend Limit (requires approval above this amount)',
                'input' => 'text',
                'required' => false,
                'default' => '0.0000',
                'visible' => true,
                'user_defined' => true,
                'group' => 'General',
                'position' => 210,
                'system' => false,
            ]
        );
        $spendLimitAttribute = $customerSetup->getEavConfig()->getAttribute(
            \Magento\Customer\Model\Customer::ENTITY,
            self::ATTRIBUTE_SPEND_LIMIT
        );
        $spendLimitAttribute->setData('used_in_forms', ['adminhtml_customer']);
        $spendLimitAttribute->setData('scope', ScopedAttributeInterface::SCOPE_STORE);
        $spendLimitAttribute->save();

        $customerSetup->addAttribute(
            \Magento\Customer\Model\Customer::ENTITY,
            self::ATTRIBUTE_APPROVAL_ADMIN_EMAIL,
            [
                'type' => 'varchar',
                'label' => 'Order Approval Admin Email',
                'input' => 'text',
                'required' => false,
                'visible' => true,
                'user_defined' => true,
                'group' => 'General',
                'position' => 220,
                'system' => false,
            ]
        );
        $adminEmailAttribute = $customerSetup->getEavConfig()->getAttribute(
            \Magento\Customer\Model\Customer::ENTITY,
            self::ATTRIBUTE_APPROVAL_ADMIN_EMAIL
        );
        $adminEmailAttribute->setData('used_in_forms', ['adminhtml_customer']);
        $adminEmailAttribute->setData('scope', ScopedAttributeInterface::SCOPE_STORE);
        $adminEmailAttribute->save();

        $this->moduleDataSetup->getConnection()->endSetup();

        return $this;
    }
}
