<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Setup\Patch\Data;

use Magento\Customer\Model\Attribute;
use Magento\Customer\Setup\CustomerSetup;
use Magento\Customer\Setup\CustomerSetupFactory;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Ordo\Automation\Setup\Patch\Data\AddCustomerCreditLimitAttribute;
use Ordo\Automation\Setup\Patch\Data\AddCustomerSpendLimitAttributes;
use PHPUnit\Framework\TestCase;

class AddCustomerSpendLimitAttributesTest extends TestCase
{
    public function testApplyAddsBothAttributes(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $moduleDataSetup = $this->createStub(ModuleDataSetupInterface::class);
        $moduleDataSetup->method('getConnection')->willReturn($connection);

        $attribute = $this->createMock(Attribute::class);
        $attribute->expects(self::exactly(2))->method('save');

        $eavConfig = $this->createStub(EavConfig::class);
        $eavConfig->method('getAttribute')->willReturn($attribute);

        $customerSetup = $this->createMock(CustomerSetup::class);
        $customerSetup->expects(self::exactly(2))->method('addAttribute');
        $customerSetup->method('getEavConfig')->willReturn($eavConfig);

        $customerSetupFactory = $this->createStub(CustomerSetupFactory::class);
        $customerSetupFactory->method('create')->willReturn($customerSetup);

        $patch = new AddCustomerSpendLimitAttributes($moduleDataSetup, $customerSetupFactory);
        $patch->apply();
    }

    public function testGetDependenciesReferencesCreditLimitPatch(): void
    {
        self::assertSame(
            [AddCustomerCreditLimitAttribute::class],
            AddCustomerSpendLimitAttributes::getDependencies()
        );
    }

    public function testGetAliasesIsEmpty(): void
    {
        $moduleDataSetup = $this->createStub(ModuleDataSetupInterface::class);
        $customerSetupFactory = $this->createStub(CustomerSetupFactory::class);
        $patch = new AddCustomerSpendLimitAttributes($moduleDataSetup, $customerSetupFactory);

        self::assertSame([], $patch->getAliases());
    }
}
