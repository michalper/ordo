<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ResourceModel\Campaign;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Ordo\Automation\Model\ResourceModel\Campaign\ScheduledTriggerState;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class ScheduledTriggerStateTest extends TestCase
{
    #[AllowMockObjectsWithoutExpectations]
    public function testGetStateReturnsNullWhenNoRowExists(): void
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchRow')->willReturn(false);

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturn('ordo_campaign_scheduled_trigger_state');

        $state = new ScheduledTriggerState($resourceConnection);

        self::assertNull($state->getState(5, 'scheduled_at'));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetStateReturnsDecodedRowWhenOneExists(): void
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchRow')->willReturn([
            'config_hash' => 'abc123',
            'last_fired_at' => '2026-01-01 00:00:00',
        ]);

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturn('ordo_campaign_scheduled_trigger_state');

        $state = new ScheduledTriggerState($resourceConnection);

        self::assertSame(
            ['config_hash' => 'abc123', 'last_fired_at' => '2026-01-01 00:00:00'],
            $state->getState(5, 'scheduled_at')
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetStateTreatsNullLastFiredAtAsNeverFired(): void
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchRow')->willReturn(['config_hash' => 'abc123', 'last_fired_at' => null]);

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturn('ordo_campaign_scheduled_trigger_state');

        $state = new ScheduledTriggerState($resourceConnection);

        self::assertSame(
            ['config_hash' => 'abc123', 'last_fired_at' => null],
            $state->getState(5, 'scheduled_at')
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testMarkFiredUpsertsAllFourColumns(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::once())->method('insertOnDuplicate')->with(
            'ordo_campaign_scheduled_trigger_state',
            [
                'campaign_id' => 5,
                'trigger_event' => 'scheduled_at',
                'config_hash' => 'abc123',
                'last_fired_at' => '2026-01-01 00:00:00',
            ],
            ['config_hash', 'last_fired_at']
        );

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturn('ordo_campaign_scheduled_trigger_state');

        (new ScheduledTriggerState($resourceConnection))
            ->markFired(5, 'scheduled_at', 'abc123', '2026-01-01 00:00:00');
    }
}
