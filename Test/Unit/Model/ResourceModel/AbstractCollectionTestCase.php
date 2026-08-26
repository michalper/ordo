<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ResourceModel;

use Magento\Framework\Data\Collection\Db\FetchStrategyInterface;
use Magento\Framework\Data\Collection\EntityFactoryInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Shared setup for the grid/plain collection classes in this module. Passing a mock resource
 * model as the constructor's optional 6th argument avoids AbstractCollection's fallback of
 * instantiating the real resource class through the global ObjectManager, which a unit test
 * shouldn't need to bootstrap.
 */
abstract class AbstractCollectionTestCase extends TestCase
{
    protected function makeConnection(): AdapterInterface
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('order')->willReturnSelf();
        $select->method('__toString')->willReturn('SELECT 1');

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);

        return $connection;
    }

    protected function makeResource(string $mainTable = 'ordo_dummy'): AbstractDb
    {
        $connection = $this->makeConnection();

        $resource = $this->createMock(AbstractDb::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getMainTable')->willReturn($mainTable);
        $resource->method('getIdFieldName')->willReturn('entity_id');

        return $resource;
    }

    /**
     * @return array{0: EntityFactoryInterface, 1: LoggerInterface, 2: FetchStrategyInterface, 3: ManagerInterface}
     */
    protected function makeCollectionDeps(): array
    {
        return [
            $this->createMock(EntityFactoryInterface::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(FetchStrategyInterface::class),
            $this->createMock(ManagerInterface::class),
        ];
    }
}
