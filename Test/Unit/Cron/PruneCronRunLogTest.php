<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Cron;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Ordo\Automation\Cron\PruneCronRunLog;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

class PruneCronRunLogTest extends TestCase
{
    use MakesCronRunLoggerTrait;

    public function testExecuteDeletesOldRowsAndLogsSummary(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::once())->method('delete')
            ->with('ordo_cron_run_log', self::isArray())
            ->willReturn(7);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info');

        (new PruneCronRunLog($resourceConnection, $this->makeCronRunLogger($logger)))->execute();
    }
}
