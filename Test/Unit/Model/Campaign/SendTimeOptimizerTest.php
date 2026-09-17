<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Campaign;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Ordo\Automation\Model\Campaign\CustomerTimezoneResolver;
use Ordo\Automation\Model\Campaign\SendTimeOptimizer;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class SendTimeOptimizerTest extends TestCase
{
    private ResourceConnection&\PHPUnit\Framework\MockObject\MockObject $resourceConnection;
    private CustomerTimezoneResolver&\PHPUnit\Framework\MockObject\MockObject $customerTimezoneResolver;
    private AdapterInterface&\PHPUnit\Framework\MockObject\MockObject $connection;
    private SendTimeOptimizer $optimizer;

    protected function setUp(): void
    {
        $this->resourceConnection = $this->createMock(ResourceConnection::class);
        $this->customerTimezoneResolver = $this->createMock(CustomerTimezoneResolver::class);
        $this->connection = $this->createMock(AdapterInterface::class);

        $this->resourceConnection->method('getConnection')->willReturn($this->connection);
        $this->resourceConnection->method('getTableName')->willReturnArgument(0);

        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('join')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $this->connection->method('select')->willReturn($select);

        $this->optimizer = new SendTimeOptimizer($this->resourceConnection, $this->customerTimezoneResolver);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testReturnsNullWhenThereAreFewerThanTheMinimumSampleSize(): void
    {
        $this->connection->method('fetchCol')->willReturn([
            '2026-01-15 18:00:00',
            '2026-01-16 18:05:00',
        ]);

        self::assertNull($this->optimizer->getBestHour(42));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testReturnsNullWithNoEventsAtAll(): void
    {
        $this->connection->method('fetchCol')->willReturn([]);

        self::assertNull($this->optimizer->getBestHour(42));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testReturnsTheHourWithTheMostEventsInTheCustomersTimezone(): void
    {
        // All in UTC: 18:00, 18:15, 18:45 (3x hour 18) and one at 09:00 (hour 9) - 18 should win.
        $this->connection->method('fetchCol')->willReturn([
            '2026-01-10 18:00:00',
            '2026-01-11 18:15:00',
            '2026-01-12 18:45:00',
            '2026-01-13 09:00:00',
        ]);
        $this->customerTimezoneResolver->method('resolve')->willReturn(new \DateTimeZone('UTC'));

        self::assertSame(18, $this->optimizer->getBestHour(42));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testConvertsEventTimestampsToTheCustomersLocalTimezoneBeforeBucketing(): void
    {
        // 23:00, 23:10, 23:20 UTC = 00:00, 00:10, 00:20 in Europe/Warsaw (UTC+1, January) - the
        // best hour should be 0 (local), not 23 (UTC), proving the conversion actually happens.
        $this->connection->method('fetchCol')->willReturn([
            '2026-01-10 23:00:00',
            '2026-01-11 23:10:00',
            '2026-01-12 23:20:00',
        ]);
        $this->customerTimezoneResolver->method('resolve')->willReturn(new \DateTimeZone('Europe/Warsaw'));

        self::assertSame(0, $this->optimizer->getBestHour(42));
    }
}
