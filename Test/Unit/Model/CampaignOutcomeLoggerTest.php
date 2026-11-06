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
        $select->method('order')->willReturnSelf();
        $select->method('limit')->willReturnSelf();

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
        $connection->expects(self::never())->method('fetchRow');
        $connection->expects(self::never())->method('update');

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $logger = new CampaignOutcomeLogger($resourceConnection);
        $logger->logSent(5, 'b', 42);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testLogSentWithTriggerOrderIdMarksAnAlreadyExistingLaterOrderAsConverted(): void
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('order')->willReturnSelf();
        $select->method('limit')->willReturnSelf();
        $seenWhereConditions = [];
        $select->expects(self::exactly(2))->method('where')->willReturnCallback(
            function (string $condition, mixed $value) use ($select, &$seenWhereConditions) {
                $seenWhereConditions[$condition] = $value;
                return $select;
            }
        );

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->expects(self::once())->method('insert');
        $connection->expects(self::once())->method('fetchRow')->willReturn(['entity_id' => '555']);
        $connection->expects(self::once())->method('update')->with(
            'ordo_campaign_outcome_log',
            self::callback(fn (array $data): bool => $data['order_id'] === 555 && isset($data['acted_at'])),
            self::callback(fn (array $where): bool => $where['customer_id = ?'] === 42)
        );

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $logger = new CampaignOutcomeLogger($resourceConnection);
        // triggerOrderId=100 is the order whose own dispatch produced this send - a DIFFERENT,
        // already-existing, LATER order #555 for the same customer is what gets credited.
        $logger->logSent(5, null, 42, 100);

        self::assertSame(42, $seenWhereConditions['customer_id = ?'] ?? null);
        self::assertSame(100, $seenWhereConditions['entity_id > ?'] ?? null);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testLogSentWithTriggerOrderIdDoesNothingWhenNoLaterOrderExistsYet(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchRow')->willReturn(false);
        $connection->expects(self::never())->method('update');

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $logger = new CampaignOutcomeLogger($resourceConnection);
        $logger->logSent(5, null, 42, 100);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testLogSentWithTriggerOrderIdDoesNotCreditAnOlderOrder(): void
    {
        // The exact bug this method must never reintroduce: a plain "entity_id != $triggerOrderId"
        // exclusion would match the customer's OWN FIRST, already-attributed order when this same
        // campaign re-dispatches for their SECOND order - entity_id > $triggerOrderId is what
        // actually enforces "later order only".
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('order')->willReturnSelf();
        $select->method('limit')->willReturnSelf();
        $seenWhereConditions = [];
        $select->expects(self::exactly(2))->method('where')->willReturnCallback(
            function (string $condition, mixed $value) use ($select, &$seenWhereConditions) {
                $seenWhereConditions[$condition] = $value;
                return $select;
            }
        );

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        // No order with entity_id > 200 exists yet (order #100 is older, order #200 is itself) -
        // fetchRow correctly returns nothing, so no update happens.
        $connection->method('fetchRow')->willReturn(false);
        $connection->expects(self::never())->method('update');

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $logger = new CampaignOutcomeLogger($resourceConnection);
        $logger->logSent(5, null, 42, 200);

        self::assertSame(42, $seenWhereConditions['customer_id = ?'] ?? null);
        self::assertSame(200, $seenWhereConditions['entity_id > ?'] ?? null);
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

    #[AllowMockObjectsWithoutExpectations]
    public function testGetStatsForWindowComputesConversionRateKeyedByCampaignId(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchAll')->willReturn([
            [
                'campaign_id' => '5',
                'sent' => '20',
                'converted' => '4',
                'revenue' => '199.990000',
            ],
            [
                'campaign_id' => '7',
                'sent' => '0',
                'converted' => '0',
                'revenue' => null,
            ],
        ]);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $logger = new CampaignOutcomeLogger($resourceConnection);
        $stats = $logger->getStatsForWindow(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-08'));

        self::assertSame(
            [
                'campaign_id' => 5,
                'sent' => 20,
                'converted' => 4,
                'conversion_rate' => 20.0,
                'revenue' => 199.99,
            ],
            $stats[5]
        );
        self::assertSame(
            [
                'campaign_id' => 7,
                'sent' => 0,
                'converted' => 0,
                'conversion_rate' => 0.0,
                'revenue' => 0.0,
            ],
            $stats[7]
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetStatsForWindowBoundsBySentAt(): void
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('joinLeft')->willReturnSelf();
        $select->method('group')->willReturnSelf();
        $seenWhereConditions = [];
        $select->expects(self::exactly(2))->method('where')->willReturnCallback(
            function (string $condition, mixed $value) use ($select, &$seenWhereConditions) {
                $seenWhereConditions[$condition] = $value;
                return $select;
            }
        );

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchAll')->willReturn([]);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $logger = new CampaignOutcomeLogger($resourceConnection);
        $logger->getStatsForWindow(new \DateTimeImmutable('2026-01-01 00:00:00'), new \DateTimeImmutable('2026-01-08 00:00:00'));

        self::assertSame('2026-01-01 00:00:00', $seenWhereConditions['o.sent_at >= ?'] ?? null);
        self::assertSame('2026-01-08 00:00:00', $seenWhereConditions['o.sent_at < ?'] ?? null);
    }
}
