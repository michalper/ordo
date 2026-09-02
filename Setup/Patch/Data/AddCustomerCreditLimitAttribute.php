<?php
declare(strict_types=1);

namespace Ordo\Automation\Setup\Patch\Data;

use Magento\Customer\Setup\CustomerSetupFactory;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Adds the "credit limit" customer attribute Magento Open Source doesn't have natively
 * (it's an Adobe Commerce B2B "Company" feature there). Kept intentionally simple: a single
 * decimal on the customer, editable in the admin customer form — no company/hierarchy model.
 */
class AddCustomerCreditLimitAttribute implements DataPatchInterface
{
    public const ATTRIBUTE_CODE = 'ordo_credit_limit';

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

        $customerSetup->addAttribute(
            \Magento\Customer\Model\Customer::ENTITY,
            self::ATTRIBUTE_CODE,
            [
                'type' => 'decimal',
                'label' => 'Credit Limit',
                'input' => 'text',
                'required' => false,
                'default' => '0.0000',
                'visible' => true,
                'user_defined' => true,
                'group' => 'General',
                'position' => 200,
                'system' => false,
            ]
        );

        $attribute = $customerSetup->getEavConfig()->getAttribute(
            \Magento\Customer\Model\Customer::ENTITY,
            self::ATTRIBUTE_CODE
        );
        $attribute->setData('used_in_forms', ['adminhtml_customer']);
        $attribute->setData('is_visible_in_grid', true);
        $attribute->setData('scope', ScopedAttributeInterface::SCOPE_STORE);
        $attribute->save();

        $this->moduleDataSetup->getConnection()->endSetup();

        return $this;
    }
}
