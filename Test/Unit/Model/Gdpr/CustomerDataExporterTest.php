<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Gdpr;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Ordo\Automation\Model\Gdpr\CustomerDataExporter;
use Ordo\Automation\Model\Gdpr\CustomerDataTableProvider;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class CustomerDataExporterTest extends TestCase
{
    #[AllowMockObjectsWithoutExpectations]
    public function testExportFetchesEveryTableFilteredByCustomerId(): void
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->expects(self::exactly(8))->method('fetchAll')->with($select)->willReturn([['row' => 1]]);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $result = (new CustomerDataExporter($resourceConnection, new CustomerDataTableProvider()))->export(42);

        self::assertSame(42, $result['customer_id']);
        self::assertArrayHasKey('consent', $result);
        self::assertArrayHasKey('tags', $result);
        self::assertArrayHasKey('score', $result);
        self::assertArrayHasKey('demographic_score', $result);
        self::assertArrayHasKey('notifications', $result);
        self::assertArrayHasKey('survey_responses', $result);
        self::assertArrayHasKey('pending_popups', $result);
        self::assertArrayHasKey('message_log', $result);
        self::assertSame([['row' => 1]], $result['tags']);
    }
}
