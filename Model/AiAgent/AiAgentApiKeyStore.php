<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\AiAgent;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * Thin ResourceConnection-backed store for ordo_ai_agent_api_key - same "no real domain entity,
 * so a plain class talking straight to ResourceConnection beats an AbstractDb/Model pair" reasoning
 * as Model\RateLimit\ProviderRateLimitStore, except here rows ARE individually addressable
 * (issued/revoked one at a time by entity_id) rather than one shared counter row per key. No admin
 * grid/repository interface - Console\Command\ApiKey\* are the only callers, since these are
 * machine credentials issued out of band, not something browsed in the admin UI.
 */
class AiAgentApiKeyStore
{
    private const string TABLE = 'ordo_ai_agent_api_key';

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly DateTime $dateTime
    ) {
    }

    /**
     * Doesn't return the new row's entity_id - AdapterInterface (this store's only dependency,
     * deliberately not the concrete Zend adapter class) doesn't declare lastInsertId() at all,
     * same reasoning as Model\Cron\ReminderLogStore::deleteMatching()'s own docblock. Console\
     * Command\ApiKey\GenerateCommand looks the new row back up by its (unique) token hash instead.
     */
    public function create(string $label, string $tokenHash): void
    {
        $connection = $this->resourceConnection->getConnection();
        $connection->insert($this->resourceConnection->getTableName(self::TABLE), [
            'label' => $label,
            'token_hash' => $tokenHash,
            'is_active' => 1,
        ]);
    }

    /**
     * @return array{entity_id: int, label: string}|null
     */
    public function findActiveByHash(string $tokenHash): ?array
    {
        $connection = $this->resourceConnection->getConnection();
        $row = $connection->fetchRow(
            $connection->select()
                ->from($this->resourceConnection->getTableName(self::TABLE), ['entity_id', 'label'])
                ->where('token_hash = ?', $tokenHash)
                ->where('is_active = ?', 1)
        );

        if (!$row) {
            return null;
        }

        return ['entity_id' => (int) $row['entity_id'], 'label' => (string) $row['label']];
    }

    public function touchLastUsed(int $entityId): void
    {
        $connection = $this->resourceConnection->getConnection();
        $connection->update(
            $this->resourceConnection->getTableName(self::TABLE),
            ['last_used_at' => $this->dateTime->gmtDate()],
            ['entity_id = ?' => $entityId]
        );
    }

    /**
     * @return bool true if an active key with this id was found and revoked
     */
    public function revoke(int $entityId): bool
    {
        $connection = $this->resourceConnection->getConnection();
        $affected = $connection->update(
            $this->resourceConnection->getTableName(self::TABLE),
            ['is_active' => 0],
            ['entity_id = ?' => $entityId, 'is_active = ?' => 1]
        );

        return $affected > 0;
    }

    /**
     * @return array<int, array{entity_id: int, label: string, is_active: bool, created_at: string,
     *     last_used_at: ?string}>
     */
    public function getAll(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $rows = $connection->fetchAll(
            $connection->select()
                ->from(
                    $this->resourceConnection->getTableName(self::TABLE),
                    ['entity_id', 'label', 'is_active', 'created_at', 'last_used_at']
                )
                ->order('entity_id ASC')
        );

        $keys = [];
        foreach ($rows as $row) {
            $keys[] = [
                'entity_id' => (int) $row['entity_id'],
                'label' => (string) $row['label'],
                'is_active' => (bool) $row['is_active'],
                'created_at' => (string) $row['created_at'],
                'last_used_at' => $row['last_used_at'] !== null ? (string) $row['last_used_at'] : null,
            ];
        }

        return $keys;
    }
}
