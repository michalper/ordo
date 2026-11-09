<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ResourceModel\CampaignComparison\Grid;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Data\Collection\Db\FetchStrategyInterface;
use Magento\Framework\Data\Collection\EntityFactoryInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Magento\Framework\ObjectManagerInterface;
use Ordo\Automation\Model\ResourceModel\CampaignComparison\Grid\Collection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Same technique as RevenueAnalytics\Grid\CollectionTest: each connection->select() call returns
 * a fresh mock in the exact order _initSelect() makes them, joinLeft calls on the main select are
 * recorded (not asserted inline, PHPUnit 10+ dropped withConsecutive).
 */
class CollectionTest extends TestCase
{
    protected function tearDown(): void
    {
        ObjectManager::setInstance($this->createStub(ObjectManagerInterface::class));
    }

    public function testInitSelectJoinsOutcomeRevenueAndDedupedClvStats(): void
    {
        $mainSelect = $this->createMock(Select::class);
        $mainSelect->method('from')->willReturnSelf();
        $joinCalls = [];
        $mainSelect->method('joinLeft')->willReturnCallback(
            function (...$args) use (&$joinCalls, $mainSelect) {
                $joinCalls[] = $args;
                return $mainSelect;
            }
        );
        $columnsArg = null;
        $mainSelect->expects(self::once())->method('columns')->willReturnCallback(
            function ($cols) use (&$columnsArg, $mainSelect) {
                $columnsArg = $cols;
                return $mainSelect;
            }
        );

        $outcomeStats = $this->createMock(Select::class);
        $outcomeStats->expects(self::once())->method('from')->with(
            'ordo_campaign_outcome_log',
            self::callback(
                fn (array $cols) => $cols[0] === 'campaign_id'
                    && (string) $cols['sent'] === 'COUNT(*)'
                    && (string) $cols['converted'] === 'SUM(CASE WHEN acted_at IS NOT NULL THEN 1 ELSE 0 END)'
            )
        )->willReturnSelf();
        $outcomeStats->expects(self::once())->method('group')->with('campaign_id')->willReturnSelf();
        $outcomeStats->method('assemble')->willReturn('OUTCOME_STATS_SQL');

        $revenueStats = $this->createMock(Select::class);
        $revenueStats->expects(self::once())->method('from')->with(
            'ordo_campaign_attribution',
            self::callback(
                fn (array $cols) => $cols[0] === 'campaign_id'
                    && (string) $cols['attributed_revenue'] === 'SUM(attributed_revenue)'
            )
        )->willReturnSelf();
        $revenueStats->expects(self::once())->method('group')->with('campaign_id')->willReturnSelf();
        $revenueStats->method('assemble')->willReturn('REVENUE_STATS_SQL');

        $convertedCustomers = $this->createMock(Select::class);
        $convertedCustomers->expects(self::once())->method('distinct')->willReturnSelf();
        $convertedCustomers->expects(self::once())->method('from')
            ->with('ordo_campaign_outcome_log', ['campaign_id', 'customer_id'])->willReturnSelf();
        $convertedCustomers->expects(self::once())->method('where')
            ->with('acted_at IS NOT NULL')->willReturnSelf();
        $convertedCustomers->method('assemble')->willReturn('CONVERTED_CUSTOMERS_SQL');

        $clvStats = $this->createMock(Select::class);
        $clvStats->expects(self::once())->method('from')->with(
            self::callback(
                fn (array $from) => (string) $from['cc'] === '(CONVERTED_CUSTOMERS_SQL)'
            ),
            ['campaign_id']
        )->willReturnSelf();
        $clvStats->expects(self::once())->method('joinInner')
            ->with(['clv' => 'ordo_customer_clv_score'], 'clv.customer_id = cc.customer_id', [])
            ->willReturnSelf();
        $clvStats->expects(self::once())->method('columns')->with(
            self::callback(fn (array $c) => (string) $c['avg_converted_clv'] === 'AVG(clv.clv_score)')
        )->willReturnSelf();
        $clvStats->expects(self::once())->method('group')->with('cc.campaign_id')->willReturnSelf();
        $clvStats->method('assemble')->willReturn('CLV_STATS_SQL');

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturnOnConsecutiveCalls(
            // SearchResult::__construct() builds the main select lazily via two separate
            // getSelect() accesses (setMainTable(true) for the initial FROM part, then the real
            // table name) before _initSelect() ever runs - confirmed directly by instrumenting
            // connection->select() with a call logger. Both must resolve to the SAME mock.
            $mainSelect,
            $mainSelect,
            $outcomeStats,
            $revenueStats,
            $convertedCustomers,
            $clvStats
        );

        $this->buildAndConstruct($connection);

        self::assertCount(3, $joinCalls);

        self::assertSame('(OUTCOME_STATS_SQL)', (string) $joinCalls[0][0]['outcome']);
        self::assertSame('outcome.campaign_id = main_table.entity_id', $joinCalls[0][1]);
        self::assertSame([], $joinCalls[0][2]);

        self::assertSame('(REVENUE_STATS_SQL)', (string) $joinCalls[1][0]['revenue']);
        self::assertSame('revenue.campaign_id = main_table.entity_id', $joinCalls[1][1]);
        self::assertSame([], $joinCalls[1][2]);

        // Proves the third join embeds clvStats (built on TOP of the deduped convertedCustomers
        // subselect), not a raw, un-deduped average - the exact double-counting bug the class's
        // own docblock says this dedup-before-join shape exists to avoid.
        self::assertSame('(CLV_STATS_SQL)', (string) $joinCalls[2][0]['clvstats']);
        self::assertSame('clvstats.campaign_id = main_table.entity_id', $joinCalls[2][1]);
        self::assertSame([], $joinCalls[2][2]);

        self::assertSame('COALESCE(outcome.sent, 0)', (string) $columnsArg['sent']);
        self::assertSame('COALESCE(outcome.converted, 0)', (string) $columnsArg['converted']);
        self::assertSame('COALESCE(revenue.attributed_revenue, 0)', (string) $columnsArg['revenue']);
        self::assertSame('clvstats.avg_converted_clv', $columnsArg['avg_converted_clv']);
        self::assertStringContainsString('outcome.converted / outcome.sent', (string) $columnsArg['conversion_rate']);
    }

    private function buildAndConstruct(AdapterInterface $connection): Collection
    {
        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(
            static fn ($table) => is_string($table) ? $table : 'ordo_campaign'
        );

        $resourceModel = $this->createStub(AbstractDb::class);
        $resourceModel->method('getConnection')->willReturn($connection);
        $resourceModel->method('getIdFieldName')->willReturn('entity_id');

        $objectManager = $this->createStub(ObjectManagerInterface::class);
        $objectManager->method('get')->willReturn($resourceConnection);
        $objectManager->method('create')->willReturn($resourceModel);
        ObjectManager::setInstance($objectManager);

        return new Collection(
            $this->createStub(EntityFactoryInterface::class),
            $this->createStub(LoggerInterface::class),
            $this->createStub(FetchStrategyInterface::class),
            $this->createStub(ManagerInterface::class),
            $resourceConnection
        );
    }
}
