<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Setup\Patch\Data;

use Magento\Customer\Model\Attribute;
use Magento\Customer\Setup\CustomerSetup;
use Magento\Customer\Setup\CustomerSetupFactory;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Ordo\Automation\Setup\Patch\Data\AddSalesRepAttributes;
use PHPUnit\Framework\TestCase;

class AddSalesRepAttributesTest extends TestCase
{
    public function testApplyAddsThreeAttributes(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $moduleDataSetup = $this->createMock(ModuleDataSetupInterface::class);
        $moduleDataSetup->method('getConnection')->willReturn($connection);

        $attribute = $this->createMock(Attribute::class);
        $attribute->expects(self::exactly(3))->method('save');

        $eavConfig = $this->createMock(EavConfig::class);
        $eavConfig->method('getAttribute')->willReturn($attribute);

        $customerSetup = $this->createMock(CustomerSetup::class);
        $customerSetup->expects(self::exactly(3))->method('addAttribute');
        $customerSetup->method('getEavConfig')->willReturn($eavConfig);

        $customerSetupFactory = $this->createMock(CustomerSetupFactory::class);
        $customerSetupFactory->method('create')->willReturn($customerSetup);

        $patch = new AddSalesRepAttributes($moduleDataSetup, $customerSetupFactory);
        $patch->apply();
    }

    public function testGetDependenciesAndAliasesAreEmpty(): void
    {
        self::assertSame([], AddSalesRepAttributes::getDependencies());

        $moduleDataSetup = $this->createMock(ModuleDataSetupInterface::class);
        $customerSetupFactory = $this->createMock(CustomerSetupFactory::class);
        $patch = new AddSalesRepAttributes($moduleDataSetup, $customerSetupFactory);

        self::assertSame([], $patch->getAliases());
    }
}
