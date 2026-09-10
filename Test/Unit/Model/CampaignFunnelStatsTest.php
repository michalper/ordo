<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Ordo\Automation\Model\CampaignFunnelStats;
use Ordo\Automation\Model\CampaignOutcomeLogger;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class CampaignFunnelStatsTest extends TestCase
{
    private function makeSelect(): Select
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('joinInner')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('group')->willReturnSelf();
        $select->method('columns')->willReturnSelf();

        return $select;
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetForCampaignComposesSendAndOutcomeStatsPerVariant(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchAll')->willReturnOnConsecutiveCalls(
            // First call: send/delivered stats grouped by variant.
            [
                ['variant' => 'a', 'sent' => '10', 'delivered' => '9'],
                ['variant' => 'b', 'sent' => '5', 'delivered' => '4'],
            ],
            // Second call: opened/clicked distinct counts grouped by variant+event_type.
            [
                ['variant' => 'a', 'event_type' => 'opened', 'count' => '6'],
                ['variant' => 'a', 'event_type' => 'clicked', 'count' => '2'],
                ['variant' => 'b', 'event_type' => 'opened', 'count' => '3'],
            ]
        );

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $campaignOutcomeLogger = $this->createStub(CampaignOutcomeLogger::class);
        $campaignOutcomeLogger->method('getStats')->willReturn([
            ['campaign_id' => 5, 'variant' => 'a', 'sent' => 10, 'converted' => 2, 'conversion_rate' => 20.0, 'revenue' => 300.0],
            ['campaign_id' => 5, 'variant' => 'b', 'sent' => 5, 'converted' => 0, 'conversion_rate' => 0.0, 'revenue' => 0.0],
        ]);

        $stats = new CampaignFunnelStats($resourceConnection, $campaignOutcomeLogger);
        $rows = $stats->getForCampaign(5);

        self::assertCount(2, $rows);
        self::assertSame(
            [
                'variant' => 'a',
                'sent' => 10,
                'delivered' => 9,
                'opened' => 6,
                'clicked' => 2,
                'converted' => 2,
                'conversion_rate' => 20.0,
                'revenue' => 300.0,
            ],
            $rows[0]
        );
        self::assertSame(
            [
                'variant' => 'b',
                'sent' => 5,
                'delivered' => 4,
                'opened' => 3,
                'clicked' => 0,
                'converted' => 0,
                'conversion_rate' => 0.0,
                'revenue' => 0.0,
            ],
            $rows[1]
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetForCampaignWithNoSplitUsesNullVariant(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchAll')->willReturnOnConsecutiveCalls(
            [['variant' => null, 'sent' => '3', 'delivered' => '3']],
            []
        );

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $campaignOutcomeLogger = $this->createStub(CampaignOutcomeLogger::class);
        $campaignOutcomeLogger->method('getStats')->willReturn([
            ['campaign_id' => 5, 'variant' => null, 'sent' => 3, 'converted' => 1, 'conversion_rate' => 33.3, 'revenue' => 50.0],
        ]);

        $stats = new CampaignFunnelStats($resourceConnection, $campaignOutcomeLogger);
        $rows = $stats->getForCampaign(5);

        self::assertCount(1, $rows);
        self::assertNull($rows[0]['variant']);
        self::assertSame(3, $rows[0]['sent']);
        self::assertSame(1, $rows[0]['converted']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetForCampaignWithNoDataReturnsEmptyArray(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchAll')->willReturn([]);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $campaignOutcomeLogger = $this->createStub(CampaignOutcomeLogger::class);
        $campaignOutcomeLogger->method('getStats')->willReturn([]);

        $stats = new CampaignFunnelStats($resourceConnection, $campaignOutcomeLogger);

        self::assertSame([], $stats->getForCampaign(5));
    }
}
