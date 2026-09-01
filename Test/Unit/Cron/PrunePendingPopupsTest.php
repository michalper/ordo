<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Cron;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Ordo\Automation\Cron\PrunePendingPopups;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

class PrunePendingPopupsTest extends TestCase
{
    public function testExecuteDeletesDeliveredAndExpiredRowsAndLogs(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::exactly(2))->method('delete')
            ->with('ordo_pending_popup', self::isType('array'))
            ->willReturnOnConsecutiveCalls(3, 2);

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info');

        (new PrunePendingPopups($resourceConnection, $logger))->execute();
    }
}
