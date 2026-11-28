<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Customer360;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Ordo\Automation\Model\Customer360\CustomerTouchpointReader;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class CustomerTouchpointReaderTest extends TestCase
{
    #[AllowMockObjectsWithoutExpectations]
    public function testGetTimelineMergesAndSortsAllThreeSourcesMostRecentFirst(): void
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('joinLeft')->willReturnSelf();
        $select->method('joinInner')->willReturnSelf();
        $select->method('columns')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('order')->willReturnSelf();
        $select->method('limit')->willReturnSelf();

        $sentRows = [[
            'type' => 'sent',
            'occurred_at' => '2026-11-20 09:00:00',
            'channel' => 'email',
            'campaign_name' => 'Win-back',
            'order_id' => null,
        ]];
        $eventRows = [[
            'type' => 'opened',
            'occurred_at' => '2026-11-22 10:00:00',
            'channel' => 'email',
            'campaign_name' => 'Win-back',
            'order_id' => null,
        ]];
        $convertedRows = [[
            'type' => 'converted',
            'occurred_at' => '2026-11-21 08:00:00',
            'channel' => null,
            'campaign_name' => 'Win-back',
            'order_id' => 555,
        ]];

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchAll')->willReturnOnConsecutiveCalls($sentRows, $eventRows, $convertedRows);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $table) => $table);

        $reader = new CustomerTouchpointReader($resourceConnection);
        $timeline = $reader->getTimeline(42);

        self::assertCount(3, $timeline);
        self::assertSame(['opened', 'converted', 'sent'], array_map(fn ($t) => $t->type, $timeline));
        self::assertSame(555, $timeline[1]->orderId);
        self::assertNull($timeline[0]->orderId);
        self::assertSame('Win-back', $timeline[0]->campaignName);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetTimelineReturnsEmptyArrayWhenNothingFound(): void
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('joinLeft')->willReturnSelf();
        $select->method('joinInner')->willReturnSelf();
        $select->method('columns')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('order')->willReturnSelf();
        $select->method('limit')->willReturnSelf();

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchAll')->willReturn([]);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $table) => $table);

        $reader = new CustomerTouchpointReader($resourceConnection);

        self::assertSame([], $reader->getTimeline(42));
    }
}
