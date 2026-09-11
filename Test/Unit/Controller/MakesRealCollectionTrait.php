<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Controller;

use Magento\Framework\Data\Collection\Db\FetchStrategyInterface;
use Magento\Framework\Data\Collection\EntityFactoryInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Psr\Log\LoggerInterface;

/**
 * Builds a real (in-memory, no DB) AbstractCollection subclass so a mass-action controller test
 * can populate it with stub entity items via AbstractCollection::addItem() and hand it to a
 * mocked Ui\Component\MassAction\Filter::getCollection(), instead of mocking the collection class
 * itself (its own _initSelect()/getConnection() calls need a real-enough connection/resource
 * stub underneath regardless, so building the real object is less brittle than trying to mock
 * around AbstractCollection's constructor side effects).
 */
trait MakesRealCollectionTrait
{
    /**
     * @template T of AbstractCollection
     * @param class-string<T> $collectionClass
     * @return T
     */
    private function makeRealCollection(string $collectionClass, string $mainTable): AbstractCollection
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('order')->willReturnSelf();
        $select->method('__toString')->willReturn('SELECT 1');

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);

        $resource = $this->createStub(AbstractDb::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getMainTable')->willReturn($mainTable);
        $resource->method('getIdFieldName')->willReturn('entity_id');

        return new $collectionClass(
            $this->createStub(EntityFactoryInterface::class),
            $this->createStub(LoggerInterface::class),
            $this->createStub(FetchStrategyInterface::class),
            $this->createStub(ManagerInterface::class),
            null,
            $resource
        );
    }
}
