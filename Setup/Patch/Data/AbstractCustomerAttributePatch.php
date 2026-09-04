<?php
declare(strict_types=1);

namespace Ordo\Automation\Setup\Patch\Data;

use Magento\Customer\Model\Customer;
use Magento\Customer\Setup\CustomerSetupFactory;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Shared "add one or more customer EAV attributes" shape every Add*Attribute(s) patch in this
 * module follows — extracted after SonarCloud flagged the near-identical
 * AddCustomerCreditLimitAttribute/AddCustomerSmsPhoneAttribute pair as duplicated code.
 * getAttributes() is the only thing a concrete patch needs to supply; addAttribute()'s
 * boilerplate (start/end setup, per-attribute save, used_in_forms/scope) lives here once.
 */
abstract class AbstractCustomerAttributePatch implements DataPatchInterface
{
    public function __construct(
        protected readonly ModuleDataSetupInterface $moduleDataSetup,
        protected readonly CustomerSetupFactory $customerSetupFactory
    ) {
    }

    /**
     * One entry per attribute to create, keyed by attribute code. Only 'type', 'label', and
     * 'position' are required — everything else in Magento's addAttribute() config array
     * defaults the same way every existing patch already configured it: text input, optional,
     * visible, user-defined, "General" group, not a system attribute. Set 'is_visible_in_grid'
     * to add the attribute to the admin customer grid (only AddCustomerCreditLimitAttribute
     * does today).
     *
     * @return array<string, array{
     *     type: string,
     *     label: string,
     *     position: int,
     *     input?: string,
     *     required?: bool,
     *     default?: string,
     *     is_visible_in_grid?: bool
     * }>
     */
    abstract protected function getAttributes(): array;

    public function getAliases(): array
    {
        return [];
    }

    public function apply(): self
    {
        $this->moduleDataSetup->getConnection()->startSetup();

        $customerSetup = $this->customerSetupFactory->create(['setup' => $this->moduleDataSetup]);

        foreach ($this->getAttributes() as $code => $config) {
            $isVisibleInGrid = $config['is_visible_in_grid'] ?? false;
            unset($config['is_visible_in_grid']);

            $customerSetup->addAttribute(
                Customer::ENTITY,
                $code,
                $config + [
                    'input' => 'text',
                    'required' => false,
                    'visible' => true,
                    'user_defined' => true,
                    'group' => 'General',
                    'system' => false,
                ]
            );

            $attribute = $customerSetup->getEavConfig()->getAttribute(Customer::ENTITY, $code);
            $attribute->setData('used_in_forms', ['adminhtml_customer']);
            if ($isVisibleInGrid) {
                $attribute->setData('is_visible_in_grid', true);
            }
            $attribute->setData('scope', ScopedAttributeInterface::SCOPE_STORE);
            $attribute->save();
        }

        $this->moduleDataSetup->getConnection()->endSetup();

        return $this;
    }
}
