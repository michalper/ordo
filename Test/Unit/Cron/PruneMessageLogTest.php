<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Cron;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Ordo\Automation\Cron\PruneMessageLog;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Cron\CronRunLogger;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use Ordo\Automation\Test\Unit\Cron\MakesCronRunLoggerTrait;

class PruneMessageLogTest extends TestCase
{
    use MakesCronRunLoggerTrait;

    public function testExecuteDeletesOldRowsAndLogs(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('getMessageLogRetentionDays')->willReturn(180);

        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::once())->method('delete')
            ->with('ordo_message_log', self::isArray())
            ->willReturn(3);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info');

        (new PruneMessageLog($config, $resourceConnection, $this->makeCronRunLogger($logger)))->execute();
    }
}
