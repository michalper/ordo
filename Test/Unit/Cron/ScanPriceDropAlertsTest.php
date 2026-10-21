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
use Ordo\Automation\Cron\ScanPriceDropAlerts;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\CampaignDispatcher;
use Ordo\Automation\Model\PriceWatch\GuestPriceWatchNotifier;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ScanPriceDropAlertsTest extends TestCase
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
    ): ScanPriceDropAlerts {
        return new ScanPriceDropAlerts(
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
    public function testExecuteDispatchesPriceDropForKnownCustomerOnRealDecrease(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isPriceWatchEnabled')->willReturn(true);
        $config->method('getPriceWatchScanBatchSize')->willReturn(200);

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('quoteInto')->willReturnCallback(
            fn (string $text, $value) => str_replace('?', "'{$value}'", $text)
        );
        $connection->method('fetchAll')->willReturn([
            ['entity_id' => 1, 'customer_id' => 5, 'product_id' => 10, 'last_known_price' => '100.0000'],
        ]);
        // The claim is conditional and stamps the rotating scan cursor alongside the snapshot.
        $connection->expects(self::once())->method('update')->with(
            self::anything(),
            self::callback(
                static fn (array $data): bool => $data['last_known_price'] === 80.0
                    && isset($data['notified_at'], $data['last_scanned_at'])
            ),
            self::stringContains('notified_at IS NULL')
        )->willReturn(1);

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $product = $this->createStub(Product::class);
        $product->method('getFinalPrice')->willReturn(80.0);
        $productRepository = $this->createStub(ProductRepositoryInterface::class);
        $productRepository->method('getById')->willReturn($product);

        $dispatcher = $this->createMock(CampaignDispatcher::class);
        $dispatcher->expects(self::once())->method('dispatch')->with(
            CampaignTriggerInterface::TRIGGER_PRICE_DROP,
            ['customer_id' => 5, 'product_id' => 10, 'old_price' => 100.0, 'new_price' => 80.0]
        );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(self::stringContains('1 price drop triggers'));

        $this->makeCron($config, $resourceConnection, $productRepository, $dispatcher, $logger)->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteSkipsDispatchAndOnlyUpdatesPriceForGuest(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isPriceWatchEnabled')->willReturn(true);
        $config->method('getPriceWatchScanBatchSize')->willReturn(200);

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchAll')->willReturn([
            ['entity_id' => 1, 'customer_id' => null, 'product_id' => 10, 'last_known_price' => '100.0000'],
        ]);
        $connection->expects(self::once())->method('update')->with(
            self::anything(),
            self::callback(
                static fn (array $data): bool => $data['last_known_price'] === 80.0
                    && isset($data['last_scanned_at'])
                    && !array_key_exists('notified_at', $data)
            ),
            self::anything()
        );

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $product = $this->createStub(Product::class);
        $product->method('getFinalPrice')->willReturn(80.0);
        $productRepository = $this->createStub(ProductRepositoryInterface::class);
        $productRepository->method('getById')->willReturn($product);

        $dispatcher = $this->createMock(CampaignDispatcher::class);
        $dispatcher->expects(self::never())->method('dispatch');

        $this->makeCron($config, $resourceConnection, $productRepository, $dispatcher)->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteNotifiesGuestWithCapturedEmailDirectlyOnRealDecrease(): void
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
                'last_known_price' => '100.0000',
            ],
        ]);
        $connection->expects(self::once())->method('update')->willReturn(1);

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $product = $this->createStub(Product::class);
        $product->method('getFinalPrice')->willReturn(80.0);
        $productRepository = $this->createStub(ProductRepositoryInterface::class);
        $productRepository->method('getById')->willReturn($product);

        $dispatcher = $this->createMock(CampaignDispatcher::class);
        $dispatcher->expects(self::never())->method('dispatch');

        $guestNotifier = $this->createMock(GuestPriceWatchNotifier::class);
        $guestNotifier->expects(self::once())->method('notify')->with(
            'guest@example.com',
            $product,
            CampaignTriggerInterface::TRIGGER_PRICE_DROP,
            ['old_price' => 100.0, 'new_price' => 80.0]
        );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(self::stringContains('1 price drop triggers'));

        $this->makeCron($config, $resourceConnection, $productRepository, $dispatcher, $logger, $guestNotifier)
            ->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteRollsBackClaimWhenGuestNotifyThrows(): void
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
                'last_known_price' => '100.0000',
            ],
        ]);
        // Claim (1) then rollback (1) - never the "no-drop/guest" update path.
        $connection->expects(self::exactly(2))->method('update')->willReturn(1);

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $product = $this->createStub(Product::class);
        $product->method('getFinalPrice')->willReturn(80.0);
        $productRepository = $this->createStub(ProductRepositoryInterface::class);
        $productRepository->method('getById')->willReturn($product);

        $guestNotifier = $this->createMock(GuestPriceWatchNotifier::class);
        $guestNotifier->method('notify')->willThrowException(new \RuntimeException('send failed'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        $this->makeCron(
            $config,
            $resourceConnection,
            $productRepository,
            $this->createStub(CampaignDispatcher::class),
            $logger,
            $guestNotifier
        )->execute();
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteDoesNotDispatchWhenPriceHasNotDropped(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isPriceWatchEnabled')->willReturn(true);
        $config->method('getPriceWatchScanBatchSize')->willReturn(200);

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchAll')->willReturn([
            ['entity_id' => 1, 'customer_id' => 5, 'product_id' => 10, 'last_known_price' => '100.0000'],
        ]);

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $product = $this->createStub(Product::class);
        $product->method('getFinalPrice')->willReturn(120.0);
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
            ['entity_id' => 1, 'customer_id' => 5, 'product_id' => 10, 'last_known_price' => '100.0000'],
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
            ['entity_id' => 1, 'customer_id' => 5, 'product_id' => 10, 'last_known_price' => '100.0000'],
        ]);
        // Claim (1) then rollback (1) - never the "no-drop/guest" update path.
        $connection->expects(self::exactly(2))->method('update')->willReturn(1);

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $product = $this->createStub(Product::class);
        $product->method('getFinalPrice')->willReturn(80.0);
        $productRepository = $this->createStub(ProductRepositoryInterface::class);
        $productRepository->method('getById')->willReturn($product);

        $dispatcher = $this->createMock(CampaignDispatcher::class);
        $dispatcher->method('dispatch')->willThrowException(new \RuntimeException('dispatch failed'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        $this->makeCron($config, $resourceConnection, $productRepository, $dispatcher, $logger)->execute();
    }

    /**
     * Regression: the claim UPDATE is conditional (... AND notified_at IS NULL) - a concurrent
     * overlapping run that already claimed this row reports 0 affected rows here, and this run
     * must not dispatch a second trigger for it.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteDoesNotDispatchWhenAnotherProcessAlreadyClaimedTheRow(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isPriceWatchEnabled')->willReturn(true);
        $config->method('getPriceWatchScanBatchSize')->willReturn(200);

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchAll')->willReturn([
            ['entity_id' => 1, 'customer_id' => 5, 'product_id' => 10, 'last_known_price' => '100.0000'],
        ]);
        // Only the failed claim attempt - never a rollback (nothing was claimed by this run).
        $connection->expects(self::once())->method('update')->willReturn(0);

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $product = $this->createStub(Product::class);
        $product->method('getFinalPrice')->willReturn(80.0);
        $productRepository = $this->createStub(ProductRepositoryInterface::class);
        $productRepository->method('getById')->willReturn($product);

        $dispatcher = $this->createMock(CampaignDispatcher::class);
        $dispatcher->expects(self::never())->method('dispatch');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(self::stringContains('0 price drop triggers'));

        $this->makeCron($config, $resourceConnection, $productRepository, $dispatcher, $logger)->execute();
    }

    /**
     * Regression: a fixed 'entity_id ASC' order rescanned the same first batch every tick and
     * never reached watches beyond it - the scan must rotate on last_scanned_at (NULLs first).
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteOrdersScanByRotatingCursor(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isPriceWatchEnabled')->willReturn(true);
        $config->method('getPriceWatchScanBatchSize')->willReturn(200);

        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->expects(self::once())->method('order')
            ->with(['last_scanned_at ASC', 'entity_id ASC'])->willReturnSelf();
        $select->method('limit')->willReturnSelf();

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchAll')->willReturn([]);

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $this->makeCron($config, $resourceConnection)->execute();
    }

    /**
     * Regression: an unchanged row used to have its identical snapshot rewritten on every tick -
     * now only the cursor is stamped, and the snapshot column is left out of the UPDATE.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteStampsCursorWithoutRewritingUnchangedSnapshot(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isPriceWatchEnabled')->willReturn(true);
        $config->method('getPriceWatchScanBatchSize')->willReturn(200);

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->makeSelect());
        $connection->method('fetchAll')->willReturn([
            ['entity_id' => 1, 'customer_id' => 5, 'product_id' => 10, 'last_known_price' => '100.0000'],
        ]);
        $connection->expects(self::once())->method('update')
            ->with(self::anything(), self::callback(
                static fn (array $data): bool => array_keys($data) === ['last_scanned_at']
            ), self::anything());

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        // Same price as the snapshot ('100.0000' as the raw DB value) - no change to record.
        $product = $this->createStub(Product::class);
        $product->method('getFinalPrice')->willReturn(100.0);
        $productRepository = $this->createStub(ProductRepositoryInterface::class);
        $productRepository->method('getById')->willReturn($product);

        $dispatcher = $this->createMock(CampaignDispatcher::class);
        $dispatcher->expects(self::never())->method('dispatch');

        $this->makeCron($config, $resourceConnection, $productRepository, $dispatcher)->execute();
    }
}
