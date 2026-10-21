<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Cron;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Exception\NoSuchEntityException;
use Ordo\Automation\Api\Data\CampaignTriggerInterface;
use Ordo\Automation\Cron\ScanBackInStockAlerts;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\CampaignDispatcher;
use Ordo\Automation\Model\PriceWatch\GuestPriceWatchNotifier;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ScanBackInStockAlertsTest extends TestCase
{
    use MakesCronRunLoggerTrait;

    private function makeSelect(): Select
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('order')->willReturnSelf();
        $select->method('limit')->willReturnSelf();

        return $select;
    }

    private function makeCron(
        Config $config,
        ResourceConnection $resourceConnection,
        ?ProductRepositoryInterface $productRepository = null,
        ?CampaignDispatcher $dispatcher = null,
        ?LoggerInterface $logger = null,
        ?GuestPriceWatchNotifier $guestNotifier = null
    ): ScanBackInStockAlerts {
        return new ScanBackInStockAlerts(
            $config,
            $resourceConnection,
            $productRepository ?? $this->createStub(ProductRepositoryInterface::class),
            $dispatcher ?? $this->createStub(CampaignDispatcher::class),
            $guestNotifier ?? $this->createStub(GuestPriceWatchNotifier::class),
            $this->makeCronRunLogger($logger ?? $this->createStub(LoggerInterface::class))
        );
    }

    public function testExecuteSkipsWhenDisabled(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isPriceWatchEnabled')->willReturn(false);

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->expects(self::never())->method('getConnection');

        $this->makeCron($config, $resourceConnection)->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteDispatchesBackInStockForKnownCustomerOnRealRestock(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isPriceWatchEnabled')->willReturn(true);
        $config->method('getPriceWatchScanBatchSize')->willReturn(200);

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchAll')->willReturn([
            ['entity_id' => 1, 'customer_id' => 5, 'product_id' => 10, 'last_known_in_stock' => 0],
        ]);
        $connection->expects(self::once())->method('update')->willReturn(1);

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $product = $this->createStub(Product::class);
        $product->method('isSalable')->willReturn(true);
        $productRepository = $this->createStub(ProductRepositoryInterface::class);
        $productRepository->method('getById')->willReturn($product);

        $dispatcher = $this->createMock(CampaignDispatcher::class);
        $dispatcher->expects(self::once())->method('dispatch')->with(
            CampaignTriggerInterface::TRIGGER_BACK_IN_STOCK,
            ['customer_id' => 5, 'product_id' => 10, 'old_in_stock' => false, 'new_in_stock' => true]
        );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(self::stringContains('1 back in stock triggers'));

        $this->makeCron($config, $resourceConnection, $productRepository, $dispatcher, $logger)->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSkipsDispatchAndOnlyUpdatesStockForGuest(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isPriceWatchEnabled')->willReturn(true);
        $config->method('getPriceWatchScanBatchSize')->willReturn(200);

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchAll')->willReturn([
            ['entity_id' => 1, 'customer_id' => null, 'product_id' => 10, 'last_known_in_stock' => 0],
        ]);
        $connection->expects(self::once())->method('update')->with(
            self::anything(),
            self::callback(
                static fn (array $data): bool => $data['last_known_in_stock'] === true
                    && isset($data['last_scanned_at'])
                    && !array_key_exists('notified_at', $data)
            ),
            self::anything()
        );

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $product = $this->createStub(Product::class);
        $product->method('isSalable')->willReturn(true);
        $productRepository = $this->createStub(ProductRepositoryInterface::class);
        $productRepository->method('getById')->willReturn($product);

        $dispatcher = $this->createMock(CampaignDispatcher::class);
        $dispatcher->expects(self::never())->method('dispatch');

        $this->makeCron($config, $resourceConnection, $productRepository, $dispatcher)->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteNotifiesGuestWithCapturedEmailDirectlyOnRealRestock(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isPriceWatchEnabled')->willReturn(true);
        $config->method('getPriceWatchScanBatchSize')->willReturn(200);

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchAll')->willReturn([
            [
                'entity_id' => 1,
                'customer_id' => null,
                'guest_email' => 'guest@example.com',
                'product_id' => 10,
                'last_known_in_stock' => 0,
            ],
        ]);
        $connection->expects(self::once())->method('update')->willReturn(1);

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $product = $this->createStub(Product::class);
        $product->method('isSalable')->willReturn(true);
        $productRepository = $this->createStub(ProductRepositoryInterface::class);
        $productRepository->method('getById')->willReturn($product);

        $dispatcher = $this->createMock(CampaignDispatcher::class);
        $dispatcher->expects(self::never())->method('dispatch');

        $guestNotifier = $this->createMock(GuestPriceWatchNotifier::class);
        $guestNotifier->expects(self::once())->method('notify')->with(
            'guest@example.com',
            $product,
            CampaignTriggerInterface::TRIGGER_BACK_IN_STOCK,
            ['old_in_stock' => false, 'new_in_stock' => true]
        );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(self::stringContains('1 back in stock triggers'));

        $this->makeCron($config, $resourceConnection, $productRepository, $dispatcher, $logger, $guestNotifier)
            ->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteDoesNotDispatchWhenAlreadyInStock(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isPriceWatchEnabled')->willReturn(true);
        $config->method('getPriceWatchScanBatchSize')->willReturn(200);

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchAll')->willReturn([
            ['entity_id' => 1, 'customer_id' => 5, 'product_id' => 10, 'last_known_in_stock' => 1],
        ]);
        // Unchanged snapshot (raw '1' vs salable true) - only the scan cursor is stamped.
        $connection->expects(self::once())->method('update')
            ->with(self::anything(), self::callback(
                static fn (array $data): bool => array_keys($data) === ['last_scanned_at']
            ), self::anything());

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $product = $this->createStub(Product::class);
        $product->method('isSalable')->willReturn(true);
        $productRepository = $this->createStub(ProductRepositoryInterface::class);
        $productRepository->method('getById')->willReturn($product);

        $dispatcher = $this->createMock(CampaignDispatcher::class);
        $dispatcher->expects(self::never())->method('dispatch');

        $this->makeCron($config, $resourceConnection, $productRepository, $dispatcher)->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSkipsRowWhenProductNoLongerExists(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isPriceWatchEnabled')->willReturn(true);
        $config->method('getPriceWatchScanBatchSize')->willReturn(200);

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchAll')->willReturn([
            ['entity_id' => 1, 'customer_id' => 5, 'product_id' => 10, 'last_known_in_stock' => 0],
        ]);
        // Still stamps the cursor - an orphaned row left unstamped would block the rotation.
        $connection->expects(self::once())->method('update')
            ->with(self::anything(), self::callback(
                static fn (array $data): bool => array_keys($data) === ['last_scanned_at']
            ), self::anything());

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $productRepository = $this->createStub(ProductRepositoryInterface::class);
        $productRepository->method('getById')->willThrowException(new NoSuchEntityException(__('gone')));

        $dispatcher = $this->createMock(CampaignDispatcher::class);
        $dispatcher->expects(self::never())->method('dispatch');

        $this->makeCron($config, $resourceConnection, $productRepository, $dispatcher)->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRollsBackClaimWhenDispatchThrows(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isPriceWatchEnabled')->willReturn(true);
        $config->method('getPriceWatchScanBatchSize')->willReturn(200);

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchAll')->willReturn([
            ['entity_id' => 1, 'customer_id' => 5, 'product_id' => 10, 'last_known_in_stock' => 0],
        ]);
        $connection->expects(self::exactly(2))->method('update')->willReturn(1);

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $product = $this->createStub(Product::class);
        $product->method('isSalable')->willReturn(true);
        $productRepository = $this->createStub(ProductRepositoryInterface::class);
        $productRepository->method('getById')->willReturn($product);

        $dispatcher = $this->createMock(CampaignDispatcher::class);
        $dispatcher->method('dispatch')->willThrowException(new \RuntimeException('dispatch failed'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        $this->makeCron($config, $resourceConnection, $productRepository, $dispatcher, $logger)->execute();
    }
}
