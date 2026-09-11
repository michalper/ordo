<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller\ProductFeed;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Ordo\Automation\Controller\ProductFeed\Index;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Test\Unit\Controller\AbstractFrontendActionTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class IndexTest extends AbstractFrontendActionTestCase
{
    private RawFactory $resultRawFactory;
    private ResourceConnection $resourceConnection;
    private AdapterInterface $connection;
    private Config $config;
    private StoreManagerInterface $storeManager;
    private Raw $rawResult;

    protected function setUp(): void
    {
        $this->resultRawFactory = $this->createStub(RawFactory::class);
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->resourceConnection = $this->createStub(ResourceConnection::class);
        $this->resourceConnection->method('getConnection')->willReturn($this->connection);
        $this->resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);
        $this->config = $this->createStub(Config::class);

        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $this->storeManager = $this->createStub(StoreManagerInterface::class);
        $this->storeManager->method('getStore')->willReturn($store);

        $this->rawResult = $this->createMock(Raw::class);
        $this->rawResult->method('setHeader')->willReturnSelf();
        $this->resultRawFactory->method('create')->willReturn($this->rawResult);
    }

    private function makeController(): Index
    {
        return new Index(
            $this->makeContext(),
            $this->resultRawFactory,
            $this->resourceConnection,
            $this->config,
            $this->storeManager
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturns404WhenFeedDisabled(): void
    {
        $this->config->method('isShoppingFeedEnabled')->willReturn(false);

        $this->connection->expects(self::never())->method('select');
        $this->rawResult->expects(self::once())->method('setHttpResponseCode')->with(404);
        $this->rawResult->expects(self::never())->method('setContents');

        self::assertSame($this->rawResult, $this->makeController()->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturns404WhenNoCachedFeedExists(): void
    {
        $this->config->method('isShoppingFeedEnabled')->willReturn(true);
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $this->connection->method('select')->willReturn($select);
        $this->connection->method('fetchOne')->willReturn(false);

        $this->rawResult->expects(self::once())->method('setHttpResponseCode')->with(404);

        self::assertSame($this->rawResult, $this->makeController()->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteServesCachedXmlWhenFeedEnabledAndCached(): void
    {
        $this->config->method('isShoppingFeedEnabled')->willReturn(true);
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $this->connection->method('select')->willReturn($select);
        $this->connection->method('fetchOne')->willReturn('<rss><channel/></rss>');

        $this->rawResult->expects(self::never())->method('setHttpResponseCode');
        $this->rawResult->expects(self::once())->method('setContents')->with('<rss><channel/></rss>');

        self::assertSame($this->rawResult, $this->makeController()->execute());
    }
}
