<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Setup\Patch\Data;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Ordo\Automation\Setup\Patch\Data\MigrateSystemConfigSectionSplit;
use PHPUnit\Framework\TestCase;

class MigrateSystemConfigSectionSplitTest extends TestCase
{
    public function testApplyDoesNothingWhenTableMissing(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('isTableExists')->willReturn(false);
        $connection->expects(self::never())->method('update');

        $moduleDataSetup = $this->createStub(ModuleDataSetupInterface::class);
        $moduleDataSetup->method('getConnection')->willReturn($connection);
        $moduleDataSetup->method('getTable')->willReturnCallback(fn (string $t) => $t);

        $patch = new MigrateSystemConfigSectionSplit($moduleDataSetup);
        self::assertSame($patch, $patch->apply());
    }

    public function testApplyUpdatesEveryMovedGroupsPathPrefix(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('isTableExists')->willReturn(true);

        $updateCalls = [];
        $connection->expects(self::exactly(22))->method('update')->willReturnCallback(
            function (string $table, array $bind, array $where) use (&$updateCalls) {
                $updateCalls[] = ['table' => $table, 'bind' => $bind, 'where' => $where];
                return 1;
            }
        );

        $moduleDataSetup = $this->createStub(ModuleDataSetupInterface::class);
        $moduleDataSetup->method('getConnection')->willReturn($connection);
        $moduleDataSetup->method('getTable')->willReturnCallback(fn (string $t) => $t);

        $patch = new MigrateSystemConfigSectionSplit($moduleDataSetup);
        $patch->apply();

        // 22 groups moved out of ordo_automation into the 5 new sections.
        self::assertCount(22, $updateCalls);

        $smsCall = array_values(array_filter(
            $updateCalls,
            static fn (array $call) => $call['where']['path LIKE ?'] === 'ordo_automation/sms/%'
        ))[0] ?? null;
        self::assertNotNull($smsCall);
        self::assertSame('core_config_data', $smsCall['table']);
        self::assertStringContainsString("'ordo_channels/sms/'", (string) $smsCall['bind']['path']);
    }

    public function testGetDependenciesAndAliasesAreEmpty(): void
    {
        self::assertSame([], MigrateSystemConfigSectionSplit::getDependencies());

        $moduleDataSetup = $this->createStub(ModuleDataSetupInterface::class);
        $patch = new MigrateSystemConfigSectionSplit($moduleDataSetup);

        self::assertSame([], $patch->getAliases());
    }
}
