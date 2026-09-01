<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Setup\Patch\Data;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Ordo\Automation\Setup\Patch\Data\MigrateCampaignTriggerToChildTable;
use PHPUnit\Framework\TestCase;

class MigrateCampaignTriggerToChildTableTest extends TestCase
{
    private function makeSelect(): Select
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        return $select;
    }

    public function testApplyDoesNothingWhenCampaignTableMissing(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('isTableExists')->willReturn(false);
        $connection->expects(self::never())->method('fetchAll');

        $moduleDataSetup = $this->createStub(ModuleDataSetupInterface::class);
        $moduleDataSetup->method('getConnection')->willReturn($connection);
        $moduleDataSetup->method('getTable')->willReturnCallback(fn (string $t) => $t);

        $patch = new MigrateCampaignTriggerToChildTable($moduleDataSetup);
        self::assertSame($patch, $patch->apply());
    }

    public function testApplyDoesNothingWhenTriggerEventColumnMissing(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('isTableExists')->willReturn(true);
        $connection->method('tableColumnExists')->willReturn(false);
        $connection->expects(self::never())->method('fetchAll');

        $moduleDataSetup = $this->createStub(ModuleDataSetupInterface::class);
        $moduleDataSetup->method('getConnection')->willReturn($connection);
        $moduleDataSetup->method('getTable')->willReturnCallback(fn (string $t) => $t);

        $patch = new MigrateCampaignTriggerToChildTable($moduleDataSetup);
        $patch->apply();
    }

    public function testApplyInsertsMissingRowsAndSkipsExistingOnes(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('isTableExists')->willReturn(true);
        $connection->method('tableColumnExists')->willReturn(true);
        $connection->method('select')->willReturn($this->makeSelect());

        $connection->method('fetchAll')->willReturn([
            ['entity_id' => 1, 'trigger_event' => 'order_placed'],
            ['entity_id' => 2, 'trigger_event' => 'customer_registered'],
        ]);
        // First row's existence check returns truthy (already migrated), second returns falsy.
        $connection->method('fetchOne')->willReturnOnConsecutiveCalls(5, false);

        $connection->expects(self::once())->method('insert')->with(
            'ordo_campaign_trigger',
            ['campaign_id' => 2, 'trigger_event' => 'customer_registered']
        );

        $moduleDataSetup = $this->createStub(ModuleDataSetupInterface::class);
        $moduleDataSetup->method('getConnection')->willReturn($connection);
        $moduleDataSetup->method('getTable')->willReturnCallback(fn (string $t) => $t);

        $patch = new MigrateCampaignTriggerToChildTable($moduleDataSetup);
        $patch->apply();
    }

    public function testGetDependenciesAndAliasesAreEmpty(): void
    {
        self::assertSame([], MigrateCampaignTriggerToChildTable::getDependencies());

        $moduleDataSetup = $this->createStub(ModuleDataSetupInterface::class);
        $patch = new MigrateCampaignTriggerToChildTable($moduleDataSetup);

        self::assertSame([], $patch->getAliases());
    }
}
