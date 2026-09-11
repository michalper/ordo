<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Cron;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Ordo\Automation\Cron\PruneNotifications;
use Ordo\Automation\Model\Cron\CronRunLogger;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use Ordo\Automation\Test\Unit\Cron\MakesCronRunLoggerTrait;

class PruneNotificationsTest extends TestCase
{
    use MakesCronRunLoggerTrait;

    public function testExecuteDeletesReadAndExpiredRowsAndLogs(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::exactly(2))->method('delete')
            ->with('ordo_notification', self::isArray())
            ->willReturnOnConsecutiveCalls(3, 2);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info');

        (new PruneNotifications($resourceConnection, $this->makeCronRunLogger($logger)))->execute();
    }
}
