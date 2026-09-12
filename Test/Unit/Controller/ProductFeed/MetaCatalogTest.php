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
use Ordo\Automation\Controller\ProductFeed\MetaCatalog;
use Ordo\Automation\Model\ProductFeed\MetaCatalogFeedGenerator;
use Ordo\Automation\Test\Unit\Controller\AbstractFrontendActionTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

class MetaCatalogTest extends AbstractFrontendActionTestCase
{
    private RawFactory $resultRawFactory;
    private ResourceConnection $resourceConnection;
    private AdapterInterface $connection;
    private MetaCatalogFeedGenerator&\PHPUnit\Framework\MockObject\MockObject $generator;
    private StoreManagerInterface $storeManager;
    private Raw $rawResult;

    protected function setUp(): void
    {
        $this->resultRawFactory = $this->createStub(RawFactory::class);
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->resourceConnection = $this->createStub(ResourceConnection::class);
        $this->resourceConnection->method('getConnection')->willReturn($this->connection);
        $this->resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $this->generator = $this->createMock(MetaCatalogFeedGenerator::class);
        $this->generator->method('getFeedCode')->willReturn('meta_catalog');
        $this->generator->method('getContentType')->willReturn('text/csv; charset=UTF-8');

        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $this->storeManager = $this->createStub(StoreManagerInterface::class);
        $this->storeManager->method('getStore')->willReturn($store);

        $this->rawResult = $this->createMock(Raw::class);
        $this->rawResult->method('setHeader')->willReturnSelf();
        $this->resultRawFactory->method('create')->willReturn($this->rawResult);
    }

    private function makeController(): MetaCatalog
    {
        return new MetaCatalog(
            $this->makeContext(),
            $this->resultRawFactory,
            $this->resourceConnection,
            $this->storeManager,
            $this->generator
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturns404WhenFeedDisabled(): void
    {
        $this->generator->method('isEnabled')->willReturn(false);

        $this->connection->expects(self::never())->method('select');
        $this->rawResult->expects(self::once())->method('setHttpResponseCode')->with(404);
        $this->rawResult->expects(self::never())->method('setContents');

        self::assertSame($this->rawResult, $this->makeController()->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteReturns404WhenNoCachedFeedExists(): void
    {
        $this->generator->method('isEnabled')->willReturn(true);
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $this->connection->method('select')->willReturn($select);
        $this->connection->method('fetchOne')->willReturn(false);

        $this->rawResult->expects(self::once())->method('setHttpResponseCode')->with(404);

        self::assertSame($this->rawResult, $this->makeController()->execute());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExecuteServesCachedCsvWhenFeedEnabledAndCached(): void
    {
        $this->generator->method('isEnabled')->willReturn(true);
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $this->connection->method('select')->willReturn($select);
        $this->connection->method('fetchOne')->willReturn("id,title\r\nSKU1,Product One\r\n");

        $this->rawResult->expects(self::never())->method('setHttpResponseCode');
        $this->rawResult->expects(self::once())->method('setContents')->with("id,title\r\nSKU1,Product One\r\n");

        self::assertSame($this->rawResult, $this->makeController()->execute());
    }
}
