<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Cron;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Ordo\Automation\Cron\PruneSegmentBulkActionClaims;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

class PruneSegmentBulkActionClaimsTest extends TestCase
{
    use MakesCronRunLoggerTrait;

    public function testExecuteDeletesOldRowsAndLogsSummary(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::once())->method('delete')
            ->with('ordo_segment_bulk_action_claim', self::isArray())
            ->willReturn(42);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info');

        (new PruneSegmentBulkActionClaims($resourceConnection, $this->makeCronRunLogger($logger)))->execute();
    }
}
