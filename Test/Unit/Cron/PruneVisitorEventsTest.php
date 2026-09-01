<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Cron;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Ordo\Automation\Cron\PruneVisitorEvents;
use Ordo\Automation\Helper\Config;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

class PruneVisitorEventsTest extends TestCase
{
    public function testExecuteDeletesOldRowsAndLogs(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('getTrackingRetentionDays')->willReturn(7);

        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::once())->method('delete')
            ->with('ordo_visitor_event', self::isArray())
            ->willReturn(5);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info');

        (new PruneVisitorEvents($config, $resourceConnection, $logger))->execute();
    }
}
