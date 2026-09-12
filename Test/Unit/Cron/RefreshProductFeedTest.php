<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Cron;

use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Ordo\Automation\Api\ProductFeed\FeedGeneratorInterface;
use Ordo\Automation\Cron\RefreshProductFeed;
use Ordo\Automation\Model\ProductFeed\FeedGeneratorPool;
use Ordo\Automation\Model\ProductFeed\ProductFeedCacheWriter;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Ordo\Automation\Test\Unit\Cron\MakesCronRunLoggerTrait;

class RefreshProductFeedTest extends TestCase
{
    use MakesCronRunLoggerTrait;

    private FeedGeneratorInterface&\PHPUnit\Framework\MockObject\MockObject $generator;
    private ProductFeedCacheWriter&\PHPUnit\Framework\MockObject\MockObject $cacheWriter;
    private StoreManagerInterface $storeManager;
    private LoggerInterface&\PHPUnit\Framework\MockObject\MockObject $logger;
    private RefreshProductFeed $cron;

    protected function setUp(): void
    {
        $this->generator = $this->createMock(FeedGeneratorInterface::class);
        $this->generator->method('getFeedCode')->willReturn('google_merchant');
        $this->cacheWriter = $this->createMock(ProductFeedCacheWriter::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $storeOne = $this->createStub(StoreInterface::class);
        $storeOne->method('getId')->willReturn(1);
        $this->storeManager = $this->createStub(StoreManagerInterface::class);
        $this->storeManager->method('getStores')->willReturn([$storeOne]);

        $pool = new FeedGeneratorPool(['google_merchant' => $this->generator]);

        $this->cron = new RefreshProductFeed(
            $pool,
            $this->cacheWriter,
            $this->storeManager,
            $this->makeCronRunLogger($this->createStub(LoggerInterface::class)),
            $this->logger
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSkipsStoreWhenDisabled(): void
    {
        $this->generator->method('isEnabled')->willReturn(false);
        $this->generator->expects(self::never())->method('generate');

        $this->cron->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteGeneratesAndWritesSuccessPerStore(): void
    {
        $this->generator->method('isEnabled')->willReturn(true);
        $this->generator->method('generate')->willReturn(['content' => '<rss></rss>', 'productCount' => 5]);

        $this->cacheWriter->expects(self::once())->method('writeSuccess')
            ->with('google_merchant', 1, '<rss></rss>', 5);
        $this->cacheWriter->expects(self::never())->method('writeError');

        $this->cron->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteWritesErrorWhenGenerationThrows(): void
    {
        $this->generator->method('isEnabled')->willReturn(true);
        $this->generator->method('generate')->willThrowException(new \RuntimeException('db down'));

        $this->cacheWriter->expects(self::once())->method('writeError')->with('google_merchant', 1, 'db down');
        $this->logger->expects(self::once())->method('error');

        $this->cron->execute();
    }
}
