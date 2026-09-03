<?php
declare(strict_types=1);

namespace Ordo\Automation\Setup\Patch\Data;

use Magento\Customer\Setup\CustomerSetupFactory;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Adds a dedicated "SMS phone" customer attribute for the send_sms campaign action — deliberately
 * NOT the core customer address telephone (a customer can have several addresses, or none, and
 * the address telephone isn't guaranteed to be an SMS-capable mobile number). Kept as simple as
 * the credit-limit/sales-rep attributes: a single text field, editable in the admin customer form.
 */
class AddCustomerSmsPhoneAttribute implements DataPatchInterface
{
    public const ATTRIBUTE_CODE = 'ordo_sms_phone';

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
                'type' => 'varchar',
                'label' => 'SMS Phone',
                'input' => 'text',
                'required' => false,
                'visible' => true,
                'user_defined' => true,
                'group' => 'General',
                'position' => 260,
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
