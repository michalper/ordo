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
     * Batched counterpart of getState() - ScheduledTriggerScanner::scan() used to call getState()
     * once per scheduled/recurring trigger in its loop (one SELECT per trigger, every 5 minutes).
     * This fetches every state row for the given campaigns in one query instead, keyed the same
     * way the scanner looks callers up: "{campaign_id}:{trigger_event}".
     *
     * @param int[] $campaignIds
     * @return array<string, array{config_hash: string, last_fired_at: string|null}>
     */
    public function getStatesForCampaigns(array $campaignIds): array
    {
        if ($campaignIds === []) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(
                $this->resourceConnection->getTableName(self::TABLE),
                ['campaign_id', 'trigger_event', 'config_hash', 'last_fired_at']
            )
            ->where('campaign_id IN (?)', array_unique($campaignIds));

        $states = [];
        foreach ($connection->fetchAll($select) as $row) {
            $key = $row['campaign_id'] . ':' . $row['trigger_event'];
            $states[$key] = [
                'config_hash' => (string) $row['config_hash'],
                'last_fired_at' => $row['last_fired_at'] !== null ? (string) $row['last_fired_at'] : null,
            ];
        }

        return $states;
    }

    /**
     * Serializes a single (campaign_id, trigger_event)'s whole due-check + claim + dispatch
     * sequence against a genuinely overlapping scan (a manual "Run Now" racing the scheduled
     * instance, or one tick's scan still running when the next tick starts) - without this,
     * both runs could read this pair's state before either calls markFired(), both decide it's
     * due, and both dispatch, firing a scheduled_at trigger more than once or a recurring_schedule
     * trigger twice in the same matching minute. Same GET_LOCK pattern
     * Model\LoyaltyPointsManager::earnForOrder()/redeem() already use for their own equivalent
     * races. 5s is generous for a fast read+upsert pair for a single trigger.
     */
    public function acquireLock(int $campaignId, string $triggerEvent): bool
    {
        $connection = $this->resourceConnection->getConnection();
        $lockName = 'ordo_scheduled_trigger_' . $campaignId . '_' . $triggerEvent;

        return (bool) $connection->fetchOne('SELECT GET_LOCK(?, 5)', [$lockName]);
    }

    public function releaseLock(int $campaignId, string $triggerEvent): void
    {
        $connection = $this->resourceConnection->getConnection();
        $lockName = 'ordo_scheduled_trigger_' . $campaignId . '_' . $triggerEvent;

        // phpcs:ignore Magento2.SQL.RawQuery.FoundRawSql
        $connection->query('SELECT RELEASE_LOCK(?)', [$lockName]);
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
