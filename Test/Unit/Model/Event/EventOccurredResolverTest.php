<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Event;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Ordo\Automation\Model\Event\EventOccurredResolver;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class EventOccurredResolverTest extends TestCase
{
    private function stubSelect(): Select
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('distinct')->willReturnSelf();
        $select->method('columns')->willReturnSelf();

        return $select;
    }

    private function stubResourceConnection(AdapterInterface $connection): ResourceConnection
    {
        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        return $resourceConnection;
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testHasEventOccurredReturnsTrueWhenCountIsPositive(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->stubSelect());
        $connection->method('fetchOne')->willReturn('2');

        $resolver = new EventOccurredResolver($this->stubResourceConnection($connection));

        self::assertTrue($resolver->hasEventOccurred(42, 'cart_add', '24-MB01', 14));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testHasEventOccurredReturnsFalseWhenCountIsZero(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->stubSelect());
        $connection->method('fetchOne')->willReturn('0');

        $resolver = new EventOccurredResolver($this->stubResourceConnection($connection));

        self::assertFalse($resolver->hasEventOccurred(42, 'cart_add', '24-MB01', 14));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testHasEventOccurredWorksWithoutAnEventKey(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->stubSelect());
        $connection->method('fetchOne')->willReturn('1');

        $resolver = new EventOccurredResolver($this->stubResourceConnection($connection));

        self::assertTrue($resolver->hasEventOccurred(42, 'cart_add', null, 14));
    }

    public function testHasEventOccurredFailsClosedOnInvalidInputWithoutQuerying(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::never())->method('select');

        $resolver = new EventOccurredResolver($this->stubResourceConnection($connection));

        self::assertFalse($resolver->hasEventOccurred(0, 'cart_add', null, 14));
        self::assertFalse($resolver->hasEventOccurred(42, '', null, 14));
        self::assertFalse($resolver->hasEventOccurred(42, 'cart_add', null, 0));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetCustomerIdsWithEventReturnsIntCastIds(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->stubSelect());
        $connection->method('fetchCol')->willReturn(['8', '9']);

        $resolver = new EventOccurredResolver($this->stubResourceConnection($connection));

        self::assertSame([8, 9], $resolver->getCustomerIdsWithEvent('cart_add', '24-MB01', 14));
    }

    public function testGetCustomerIdsWithEventFailsClosedOnInvalidInputWithoutQuerying(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::never())->method('select');

        $resolver = new EventOccurredResolver($this->stubResourceConnection($connection));

        self::assertSame([], $resolver->getCustomerIdsWithEvent('', null, 14));
        self::assertSame([], $resolver->getCustomerIdsWithEvent('cart_add', null, 0));
    }
}
