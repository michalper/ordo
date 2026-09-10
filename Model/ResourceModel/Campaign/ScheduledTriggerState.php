<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ResourceModel\Campaign;

use Magento\Framework\App\ResourceConnection;

/**
 * Raw-query resource for ordo_campaign_scheduled_trigger_state (see its own db_schema.xml
 * comment) - a plain Magento\Framework\Model\ResourceModel\Db\AbstractDb needs a single-column
 * primary key to support its usual load()/save() pattern; this table's primary key is the
 * composite (campaign_id, trigger_event), so it is read/written directly through
 * ResourceConnection instead of going through an AbstractModel.
 */
class ScheduledTriggerState
{
    private const string TABLE = 'ordo_campaign_scheduled_trigger_state';

    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * @return array{config_hash: string, last_fired_at: string|null}|null null if this exact
     *  (campaign_id, trigger_event) pair has never been recorded before (config_hash meaningless
     *  either way until Model\Campaign\ScheduledTriggerScanner compares it against the trigger's
     *  current params hash).
     */
    public function getState(int $campaignId, string $triggerEvent): ?array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->resourceConnection->getTableName(self::TABLE), ['config_hash', 'last_fired_at'])
            ->where('campaign_id = ?', $campaignId)
            ->where('trigger_event = ?', $triggerEvent);

        $row = $connection->fetchRow($select);

        if (!$row) {
            return null;
        }

        return [
            'config_hash' => (string) $row['config_hash'],
            'last_fired_at' => $row['last_fired_at'] !== null ? (string) $row['last_fired_at'] : null,
        ];
    }

    /**
     * Upserts the fired-tracking row - INSERT ... ON DUPLICATE KEY UPDATE rather than a
     * load()-then-save() round trip, since the composite primary key already guarantees
     * uniqueness and this is only ever called right after a scan just confirmed the trigger is
     * due, not on any hot path needing read-modify-write semantics.
     */
    public function markFired(int $campaignId, string $triggerEvent, string $configHash, string $firedAt): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);

        $connection->insertOnDuplicate(
            $table,
            [
                'campaign_id' => $campaignId,
                'trigger_event' => $triggerEvent,
                'config_hash' => $configHash,
                'last_fired_at' => $firedAt,
            ],
            ['config_hash', 'last_fired_at']
        );
    }
}
