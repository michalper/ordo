<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Gdpr;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Ordo\Automation\Model\Gdpr\CustomerDataEraser;
use Ordo\Automation\Model\Gdpr\CustomerDataTableProvider;
use PHPUnit\Framework\TestCase;

class CustomerDataEraserTest extends TestCase
{
    public function testEraseDeletesFromEveryTableFilteredByCustomerId(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::exactly(8))->method('delete')
            ->with(self::callback('is_string'), ['customer_id = ?' => 42])
            ->willReturn(1);

        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $result = (new CustomerDataEraser($resourceConnection, new CustomerDataTableProvider()))->erase(42);

        self::assertArrayHasKey('ordo_customer_consent', $result);
        self::assertArrayHasKey('ordo_customer_tag', $result);
        self::assertArrayHasKey('ordo_customer_score', $result);
        self::assertArrayHasKey('ordo_customer_demographic_score', $result);
        self::assertArrayHasKey('ordo_notification', $result);
        self::assertArrayHasKey('ordo_survey_prompt', $result);
        self::assertArrayHasKey('ordo_pending_popup', $result);
        self::assertArrayHasKey('ordo_message_log', $result);
        self::assertSame(1, $result['ordo_customer_tag']);
    }
}
