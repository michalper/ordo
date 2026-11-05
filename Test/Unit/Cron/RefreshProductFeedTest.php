<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Cron;

use Ordo\Automation\Cron\RefreshProductFeed;
use Ordo\Automation\Model\ProductFeed\ProductFeedRefresher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Ordo\Automation\Test\Unit\Cron\MakesCronRunLoggerTrait;

class RefreshProductFeedTest extends TestCase
{
    use MakesCronRunLoggerTrait;

    public function testExecuteDelegatesToRefresherAndLogsItsSummary(): void
    {
        $refresher = $this->createMock(ProductFeedRefresher::class);
        $refresher->expects(self::once())->method('refreshAll')->willReturn([
            'generated' => 2,
            'skipped' => 1,
            'failed' => 0,
            'totalProducts' => 5,
            'errors' => [],
        ]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(
            self::stringContains('refreshed 2 product feed(s), 1 skipped (disabled), 0 failed')
        );

        $cron = new RefreshProductFeed($refresher, $this->makeCronRunLogger($logger));
        $cron->execute();
    }
}
