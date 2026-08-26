<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model;

use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Magento\Framework\Registry;
use PHPUnit\Framework\TestCase;

/**
 * Shared setup for plain AbstractModel subclasses — a mocked resource is passed directly
 * into the constructor so _getResource() never falls through to ObjectManager::getInstance().
 */
abstract class AbstractModelTestCase extends TestCase
{
    protected function makeModelContext(): Context
    {
        return $this->createMock(Context::class);
    }

    protected function makeRegistry(): Registry
    {
        return $this->createMock(Registry::class);
    }

    protected function makeModelResource(string $idFieldName = 'entity_id'): AbstractDb
    {
        $resource = $this->createMock(AbstractDb::class);
        $resource->method('getIdFieldName')->willReturn($idFieldName);

        return $resource;
    }
}
