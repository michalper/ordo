<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Setup\Patch\Data;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Sales\Model\Order;
use Ordo\Automation\Setup\Patch\Data\AddPendingApprovalOrderStatus;
use PHPUnit\Framework\TestCase;

class AddPendingApprovalOrderStatusTest extends TestCase
{
    public function testApplyInsertsStatusAndState(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::exactly(2))->method('insertOnDuplicate');

        $moduleDataSetup = $this->createMock(ModuleDataSetupInterface::class);
        $moduleDataSetup->method('getConnection')->willReturn($connection);
        $moduleDataSetup->method('getTable')->willReturnCallback(fn (string $t) => $t);

        $patch = new AddPendingApprovalOrderStatus($moduleDataSetup);
        $patch->apply();
    }

    public function testGetDependenciesAndAliasesAreEmpty(): void
    {
        self::assertSame([], AddPendingApprovalOrderStatus::getDependencies());

        $moduleDataSetup = $this->createMock(ModuleDataSetupInterface::class);
        $patch = new AddPendingApprovalOrderStatus($moduleDataSetup);

        self::assertSame([], $patch->getAliases());
    }
}
