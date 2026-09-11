<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Cron;

use Ordo\Automation\Cron\RefreshProductFeed;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Cron\CronRunLogger;
use Ordo\Automation\Model\ProductFeed\GoogleMerchantFeedGenerator;
use Ordo\Automation\Model\ProductFeed\ProductFeedCacheWriter;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Ordo\Automation\Test\Unit\Cron\MakesCronRunLoggerTrait;

class RefreshProductFeedTest extends TestCase
{
    use MakesCronRunLoggerTrait;

    private GoogleMerchantFeedGenerator&\PHPUnit\Framework\MockObject\MockObject $generator;
    private ProductFeedCacheWriter&\PHPUnit\Framework\MockObject\MockObject $cacheWriter;
    private Config $config;
    private LoggerInterface&\PHPUnit\Framework\MockObject\MockObject $logger;
    private RefreshProductFeed $cron;

    protected function setUp(): void
    {
        $this->generator = $this->createMock(GoogleMerchantFeedGenerator::class);
        $this->cacheWriter = $this->createMock(ProductFeedCacheWriter::class);
        $this->config = $this->createStub(Config::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->cron = new RefreshProductFeed(
            $this->generator,
            $this->cacheWriter,
            $this->config,
            $this->makeCronRunLogger($this->createStub(LoggerInterface::class)),
            $this->logger
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSkipsWhenDisabled(): void
    {
        $this->config->method('isShoppingFeedEnabled')->willReturn(false);
        $this->generator->expects(self::never())->method('generate');

        $this->cron->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteGeneratesAndWritesSuccess(): void
    {
        $this->config->method('isShoppingFeedEnabled')->willReturn(true);
        $this->generator->method('generate')->willReturn(['xml' => '<rss></rss>', 'productCount' => 5]);

        $this->cacheWriter->expects(self::once())->method('writeSuccess')->with('<rss></rss>', 5);
        $this->cacheWriter->expects(self::never())->method('writeError');

        $this->cron->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteWritesErrorWhenGenerationThrows(): void
    {
        $this->config->method('isShoppingFeedEnabled')->willReturn(true);
        $this->generator->method('generate')->willThrowException(new \RuntimeException('db down'));

        $this->cacheWriter->expects(self::once())->method('writeError')->with('db down');
        $this->logger->expects(self::once())->method('error');

        $this->cron->execute();
    }
}
