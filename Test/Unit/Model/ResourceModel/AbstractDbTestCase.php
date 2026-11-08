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
        return $this->makeDbContextWithConnection()[0];
    }

    /**
     * Same as makeDbContext(), but also hands back the connection itself - for a test that needs
     * to set expectations on a real query the resource model issues (e.g. an update()/insert()
     * call), not just verify _construct()'s own table/field wiring. A mock, not a stub - this
     * project's PHPUnit version doesn't support ->method()->with()/->expects() chained directly
     * off a bare createStub() return value, only off createMock()'s.
     *
     * @return array{0: Context, 1: AdapterInterface&\PHPUnit\Framework\MockObject\MockObject}
     */
    protected function makeDbContextWithConnection(): array
    {
        $connection = $this->createMock(AdapterInterface::class);

        $resources = $this->createStub(ResourceConnection::class);
        $resources->method('getConnection')->willReturn($connection);
        $resources->method('getTableName')->willReturnCallback(fn (string $table) => $table);

        $context = $this->createStub(Context::class);
        $context->method('getResources')->willReturn($resources);
        $context->method('getTransactionManager')
            ->willReturn($this->createStub(TransactionManagerInterface::class));
        $context->method('getObjectRelationProcessor')
            ->willReturn($this->createStub(ObjectRelationProcessor::class));

        return [$context, $connection];
    }
}
