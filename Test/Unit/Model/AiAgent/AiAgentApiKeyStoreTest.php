<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\AiAgent;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Ordo\Automation\Model\AiAgent\AiAgentApiKeyStore;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class AiAgentApiKeyStoreTest extends TestCase
{
    private AdapterInterface&\PHPUnit\Framework\MockObject\MockObject $connection;
    private DateTime $dateTime;
    private AiAgentApiKeyStore $store;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(AdapterInterface::class);
        $resourceConnection = $this->createStub(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($this->connection);
        $resourceConnection->method('getTableName')->willReturnCallback(fn (string $t) => $t);

        $this->dateTime = $this->createStub(DateTime::class);
        $this->dateTime->method('gmtDate')->willReturn('2026-01-01 00:00:00');

        $this->store = new AiAgentApiKeyStore($resourceConnection, $this->dateTime);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testCreateInsertsAnActiveRowWithTheGivenLabelAndHash(): void
    {
        $this->connection->expects(self::once())->method('insert')->with(
            'ordo_ai_agent_api_key',
            ['label' => 'My Agent', 'token_hash' => 'abc123', 'is_active' => 1]
        );

        $this->store->create('My Agent', 'abc123');
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testFindActiveByHashReturnsNullWhenNoRowMatches(): void
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $this->connection->method('select')->willReturn($select);
        $this->connection->method('fetchRow')->willReturn(false);

        self::assertNull($this->store->findActiveByHash('missing'));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testFindActiveByHashReturnsTheMatchingRow(): void
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $this->connection->method('select')->willReturn($select);
        $this->connection->method('fetchRow')->willReturn(['entity_id' => '7', 'label' => 'My Agent']);

        self::assertSame(['entity_id' => 7, 'label' => 'My Agent'], $this->store->findActiveByHash('abc123'));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testTouchLastUsedUpdatesLastUsedAtForTheGivenId(): void
    {
        $this->connection->expects(self::once())->method('update')->with(
            'ordo_ai_agent_api_key',
            ['last_used_at' => '2026-01-01 00:00:00'],
            ['entity_id = ?' => 7]
        );

        $this->store->touchLastUsed(7);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testRevokeReturnsTrueWhenAnActiveRowWasUpdated(): void
    {
        $this->connection->expects(self::once())->method('update')->with(
            'ordo_ai_agent_api_key',
            ['is_active' => 0],
            ['entity_id = ?' => 7, 'is_active = ?' => 1]
        )->willReturn(1);

        self::assertTrue($this->store->revoke(7));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testRevokeReturnsFalseWhenNoActiveRowMatched(): void
    {
        $this->connection->method('update')->willReturn(0);

        self::assertFalse($this->store->revoke(999));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetAllReturnsEveryRowMappedToItsTypedShape(): void
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('order')->willReturnSelf();
        $this->connection->method('select')->willReturn($select);
        $this->connection->method('fetchAll')->willReturn([
            [
                'entity_id' => '1',
                'label' => 'Active Agent',
                'is_active' => '1',
                'created_at' => '2026-01-01 00:00:00',
                'last_used_at' => '2026-01-02 00:00:00',
            ],
            [
                'entity_id' => '2',
                'label' => 'Revoked Agent',
                'is_active' => '0',
                'created_at' => '2026-01-01 00:00:00',
                'last_used_at' => null,
            ],
        ]);

        $result = $this->store->getAll();

        self::assertSame([
            [
                'entity_id' => 1,
                'label' => 'Active Agent',
                'is_active' => true,
                'created_at' => '2026-01-01 00:00:00',
                'last_used_at' => '2026-01-02 00:00:00',
            ],
            [
                'entity_id' => 2,
                'label' => 'Revoked Agent',
                'is_active' => false,
                'created_at' => '2026-01-01 00:00:00',
                'last_used_at' => null,
            ],
        ], $result);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetAllReturnsEmptyArrayWhenNoKeysExist(): void
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('order')->willReturnSelf();
        $this->connection->method('select')->willReturn($select);
        $this->connection->method('fetchAll')->willReturn([]);

        self::assertSame([], $this->store->getAll());
    }
}
