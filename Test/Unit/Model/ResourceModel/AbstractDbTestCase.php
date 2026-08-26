<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Model\ResourceModel\Db\Context;
use Magento\Framework\Model\ResourceModel\Db\ObjectRelationProcessor;
use Magento\Framework\Model\ResourceModel\Db\TransactionManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Shared setup for the "does _construct() call _init() with the right table/field" style
 * tests every plain AbstractDb resource model in this module needs — real DB access isn't
 * required to verify that wiring, so this mocks just enough of Context to let the real
 * AbstractDb constructor run.
 */
abstract class AbstractDbTestCase extends TestCase
{
    protected function makeDbContext(): Context
    {
        $connection = $this->createMock(AdapterInterface::class);

        $resources = $this->createMock(ResourceConnection::class);
        $resources->method('getConnection')->willReturn($connection);
        $resources->method('getTableName')->willReturnCallback(fn (string $table) => $table);

        $context = $this->createMock(Context::class);
        $context->method('getResources')->willReturn($resources);
        $context->method('getTransactionManager')
            ->willReturn($this->createMock(TransactionManagerInterface::class));
        $context->method('getObjectRelationProcessor')
            ->willReturn($this->createMock(ObjectRelationProcessor::class));

        return $context;
    }
}
