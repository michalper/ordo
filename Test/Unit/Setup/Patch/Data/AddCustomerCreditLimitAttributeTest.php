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
use PHPUnit\Framework\TestCase;

class AddCustomerCreditLimitAttributeTest extends TestCase
{
    public function testApplyAddsAttribute(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $moduleDataSetup = $this->createMock(ModuleDataSetupInterface::class);
        $moduleDataSetup->method('getConnection')->willReturn($connection);

        $attribute = $this->createMock(Attribute::class);
        $attribute->expects(self::once())->method('save');

        $eavConfig = $this->createMock(EavConfig::class);
        $eavConfig->method('getAttribute')->willReturn($attribute);

        $customerSetup = $this->createMock(CustomerSetup::class);
        $customerSetup->expects(self::once())->method('addAttribute');
        $customerSetup->method('getEavConfig')->willReturn($eavConfig);

        $customerSetupFactory = $this->createMock(CustomerSetupFactory::class);
        $customerSetupFactory->method('create')->willReturn($customerSetup);

        $patch = new AddCustomerCreditLimitAttribute($moduleDataSetup, $customerSetupFactory);
        $patch->apply();
    }

    public function testGetDependenciesAndAliasesAreEmpty(): void
    {
        self::assertSame([], AddCustomerCreditLimitAttribute::getDependencies());

        $moduleDataSetup = $this->createMock(ModuleDataSetupInterface::class);
        $customerSetupFactory = $this->createMock(CustomerSetupFactory::class);
        $patch = new AddCustomerCreditLimitAttribute($moduleDataSetup, $customerSetupFactory);

        self::assertSame([], $patch->getAliases());
    }
}
