<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Ordo\Automation\Model\CampaignOutcomeLogger;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class CampaignOutcomeLoggerTest extends TestCase
{
    private function makeSelect(): Select
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('joinLeft')->willReturnSelf();
        $select->method('group')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        return $select;
    }

    public function testLogSentInsertsRow(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::once())->method('insert')->with(
            'ordo_campaign_outcome_log',
            self::callback(function (array $data): bool {
                return $data['campaign_id'] === 5
                    && $data['variant'] === 'b'
                    && $data['customer_id'] === 42
                    && isset($data['sent_at']);
            })
        );

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $logger = new CampaignOutcomeLogger($resourceConnection);
        $logger->logSent(5, 'b', 42);
    }

    public function testMarkActedUpdatesMostRecentUnactedRow(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::once())->method('update')->with(
            'ordo_campaign_outcome_log',
            self::callback(fn (array $data): bool => $data['order_id'] === 99 && isset($data['acted_at'])),
            self::callback(function (array $where): bool {
                return $where['customer_id = ?'] === 42
                    && in_array('acted_at IS NULL', $where, true)
                    && array_key_exists('sent_at >= ?', $where);
            })
        );

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $logger = new CampaignOutcomeLogger($resourceConnection);
        $logger->markActed(42, 99);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetStatsComputesConversionRatePerVariant(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchAll')->willReturn([
            [
                'campaign_id' => '5',
                'variant' => 'a',
                'sent' => '4',
                'converted' => '1',
                'revenue' => '149.990000',
            ],
            [
                'campaign_id' => '5',
                'variant' => 'b',
                'sent' => '0',
                'converted' => '0',
                'revenue' => null,
            ],
        ]);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $logger = new CampaignOutcomeLogger($resourceConnection);
        $stats = $logger->getStats();

        self::assertSame(
            [
                'campaign_id' => 5,
                'variant' => 'a',
                'sent' => 4,
                'converted' => 1,
                'conversion_rate' => 25.0,
                'revenue' => 149.99,
            ],
            $stats[0]
        );
        self::assertSame(
            [
                'campaign_id' => 5,
                'variant' => 'b',
                'sent' => 0,
                'converted' => 0,
                'conversion_rate' => 0.0,
                'revenue' => 0.0,
            ],
            $stats[1]
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetStatsWithCampaignIdFiltersTheQuery(): void
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('joinLeft')->willReturnSelf();
        $select->method('group')->willReturnSelf();
        $select->expects(self::once())->method('where')->with('o.campaign_id = ?', 5)->willReturnSelf();

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchAll')->willReturn([]);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $logger = new CampaignOutcomeLogger($resourceConnection);
        self::assertSame([], $logger->getStats(5));
    }
}
