<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Ordo\Automation\Model\TriggerOutcomeLogger;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class TriggerOutcomeLoggerTest extends TestCase
{
    private function makeSelect(): Select
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('group')->willReturnSelf();

        return $select;
    }

    public function testLogSentInsertsRow(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::once())->method('insert')->with(
            'ordo_trigger_outcome_log',
            self::callback(function (array $data): bool {
                return $data['trigger_type'] === TriggerOutcomeLogger::TRIGGER_WIN_BACK
                    && $data['customer_id'] === 42
                    && isset($data['sent_at']);
            })
        );

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $logger = new TriggerOutcomeLogger($resourceConnection);
        $logger->logSent(TriggerOutcomeLogger::TRIGGER_WIN_BACK, 42);
    }

    public function testMarkActedUpdatesMostRecentUnactedRow(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::once())->method('update')->with(
            'ordo_trigger_outcome_log',
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

        $logger = new TriggerOutcomeLogger($resourceConnection);
        $logger->markActed(42, 99);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetStatsComputesResponseRateAndFillsMissingTriggers(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchAll')->willReturn([
            ['trigger_type' => TriggerOutcomeLogger::TRIGGER_WIN_BACK, 'sent' => '4', 'responded' => '1'],
        ]);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $logger = new TriggerOutcomeLogger($resourceConnection);
        $stats = $logger->getStats();

        self::assertSame(
            ['sent' => 4, 'responded' => 1, 'response_rate' => 25.0],
            $stats[TriggerOutcomeLogger::TRIGGER_WIN_BACK]
        );
        self::assertSame(
            ['sent' => 0, 'responded' => 0, 'response_rate' => 0.0],
            $stats[TriggerOutcomeLogger::TRIGGER_REORDER_REMINDER]
        );
        self::assertCount(5, $stats);
    }
}
