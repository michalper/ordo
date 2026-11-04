<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Email;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Ordo\Automation\Model\Email\MessageEngagementResolver;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class MessageEngagementResolverTest extends TestCase
{
    private function stubSelect(): Select
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('joinInner')->willReturnSelf();
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
    public function testHasEngagedReturnsTrueWhenCountIsPositive(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->stubSelect());
        $connection->method('fetchOne')->willReturn('1');

        $resolver = new MessageEngagementResolver($this->stubResourceConnection($connection));

        self::assertTrue($resolver->hasEngaged(42, 7, 'opened', 14));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testHasEngagedReturnsFalseWhenCountIsZero(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->stubSelect());
        $connection->method('fetchOne')->willReturn('0');

        $resolver = new MessageEngagementResolver($this->stubResourceConnection($connection));

        self::assertFalse($resolver->hasEngaged(42, 7, 'opened', 14));
    }

    public function testHasEngagedFailsClosedOnInvalidInputWithoutQuerying(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::never())->method('select');

        $resolver = new MessageEngagementResolver($this->stubResourceConnection($connection));

        self::assertFalse($resolver->hasEngaged(0, 7, 'opened', 14));
        self::assertFalse($resolver->hasEngaged(42, 0, 'opened', 14));
        self::assertFalse($resolver->hasEngaged(42, 7, '', 14));
        self::assertFalse($resolver->hasEngaged(42, 7, 'opened', 0));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetCustomerIdsWithEngagementReturnsIntCastIds(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->stubSelect());
        $connection->method('fetchCol')->willReturn(['3', '5']);

        $resolver = new MessageEngagementResolver($this->stubResourceConnection($connection));

        self::assertSame([3, 5], $resolver->getCustomerIdsWithEngagement(7, 'clicked', 14));
    }

    public function testGetCustomerIdsWithEngagementFailsClosedOnInvalidInputWithoutQuerying(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::never())->method('select');

        $resolver = new MessageEngagementResolver($this->stubResourceConnection($connection));

        self::assertSame([], $resolver->getCustomerIdsWithEngagement(0, 'opened', 14));
        self::assertSame([], $resolver->getCustomerIdsWithEngagement(7, '', 14));
        self::assertSame([], $resolver->getCustomerIdsWithEngagement(7, 'opened', 0));
    }
}
