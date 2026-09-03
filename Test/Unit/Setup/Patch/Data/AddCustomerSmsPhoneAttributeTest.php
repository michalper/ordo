<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Setup\Patch\Data;

use Magento\Customer\Model\Attribute;
use Magento\Customer\Setup\CustomerSetup;
use Magento\Customer\Setup\CustomerSetupFactory;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Ordo\Automation\Setup\Patch\Data\AddCustomerSmsPhoneAttribute;
use PHPUnit\Framework\TestCase;

class AddCustomerSmsPhoneAttributeTest extends TestCase
{
    public function testApplyAddsAttribute(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $moduleDataSetup = $this->createStub(ModuleDataSetupInterface::class);
        $moduleDataSetup->method('getConnection')->willReturn($connection);

        $attribute = $this->createMock(Attribute::class);
        $attribute->expects(self::once())->method('save');

        $eavConfig = $this->createStub(EavConfig::class);
        $eavConfig->method('getAttribute')->willReturn($attribute);

        $customerSetup = $this->createMock(CustomerSetup::class);
        $customerSetup->expects(self::once())->method('addAttribute');
        $customerSetup->method('getEavConfig')->willReturn($eavConfig);

        $customerSetupFactory = $this->createStub(CustomerSetupFactory::class);
        $customerSetupFactory->method('create')->willReturn($customerSetup);

        $patch = new AddCustomerSmsPhoneAttribute($moduleDataSetup, $customerSetupFactory);
        $patch->apply();
    }

    public function testGetDependenciesAndAliasesAreEmpty(): void
    {
        self::assertSame([], AddCustomerSmsPhoneAttribute::getDependencies());

        $moduleDataSetup = $this->createStub(ModuleDataSetupInterface::class);
        $customerSetupFactory = $this->createStub(CustomerSetupFactory::class);
        $patch = new AddCustomerSmsPhoneAttribute($moduleDataSetup, $customerSetupFactory);

        self::assertSame([], $patch->getAliases());
    }
}
