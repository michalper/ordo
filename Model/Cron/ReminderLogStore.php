<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Cron;

use Magento\Framework\App\ResourceConnection;

/**
 * "Count rows matching some conditions in a per-feature reminder/alert log table, or insert a
 * new one" — extracted after SonarCloud flagged the connection/select/fetchOne/insert boilerplate
 * duplicated across SendCreditLimitAlerts, SendOfferExpiryReminders, and SendReorderReminders.
 * Deliberately NOT a "has this already been sent" method with a unified signature: each caller's
 * actual condition differs (credit-limit checks a cooldown window, offer-expiry checks by type
 * with no date bound, reorder checks same-day only) — that's real business logic, not
 * boilerplate, so it stays in each cron and is passed through here as plain where-conditions.
 */
class ReminderLogStore
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * @param array<string, int|string> $conditions Maps a Zend_Db_Select::where() condition
     *   string (e.g. 'customer_id = ?') to its bind value.
     */
    public function countMatching(string $table, array $conditions): int
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()->from($this->resourceConnection->getTableName($table), 'COUNT(*)');

        foreach ($conditions as $condition => $value) {
            $select->where($condition, $value);
        }

        return (int) $connection->fetchOne($select);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function insert(string $table, array $data): void
    {
        $connection = $this->resourceConnection->getConnection();
        $connection->insert($this->resourceConnection->getTableName($table), $data);
    }

    /**
     * Atomic check-then-claim: true if this call is the one that just inserted $insertData (safe
     * to send), false if a row already matching $matchConditions existed - either from a genuine
     * earlier send, or from another process that claimed it first, concurrently. Either way, the
     * caller must not send.
     *
     * Reported directly: the plain countMatching()-then-insert() sequence every caller used to
     * do itself had a real race between those two calls - two overlapping cron runs (a slow tick
     * plus the next scheduled one, or a manual cron:run alongside the scheduler) could both pass
     * the count check before either inserted, sending the same reminder/alert twice. A MySQL
     * named lock (GET_LOCK()/RELEASE_LOCK(), server-wide, not tied to any row/table) serializes
     * the whole check+insert sequence across processes without needing a unique index matching
     * each caller's own "already sent" predicate - those differ enough (a same-day check, a
     * rolling cooldown window, a per-type check with no date bound at all) that no single unique
     * constraint could cover all three tables anyway.
     *
     * @param array<string, int|string> $matchConditions same shape as countMatching()'s own $conditions
     * @param array<string, mixed> $insertData
     */
    public function claim(string $table, array $matchConditions, array $insertData): bool
    {
        $connection = $this->resourceConnection->getConnection();
        // MySQL's GET_LOCK() name has a hard 64-char limit - a fixed-length hash keeps this well
        // under that regardless of $table/$matchConditions size, and only needs to be practically
        // unique per (table, conditions) pair, not cryptographically secure.
        $lockName = substr(hash('sha256', 'ordo_reminder_claim_' . $table . json_encode($matchConditions)), 0, 60);

        // 5s is generous for a fast COUNT+INSERT pair on a small log table - long enough to never
        // spuriously fail under normal load, short enough that a genuinely stuck lock (a crashed
        // process that never released it) doesn't wedge every future tick for this same key.
        if (!(bool) $connection->fetchOne('SELECT GET_LOCK(?, 5)', [$lockName])) {
            // Could not acquire the lock (contended, or unsupported) - fail closed: never send
            // rather than risk a double-send.
            return false;
        }

        try {
            if ($this->countMatching($table, $matchConditions) > 0) {
                return false;
            }

            $this->insert($table, $insertData);

            return true;
        } finally {
            // phpcs:ignore Magento2.SQL.RawQuery.FoundRawSql
            $connection->query('SELECT RELEASE_LOCK(?)', [$lockName]);
        }
    }

    /**
     * Rolls back a claim row written by insert() — every caller of insert() in this module now
     * writes the "already sent" row BEFORE calling the actual send (a claim, not an after-the-
     * fact log), so a crash between the insert and the send can never cause a duplicate send on
     * the next cron tick. If the send itself then genuinely fails (caught exception), the claim
     * must be undone here so the customer is retried on the next run instead of being
     * permanently skipped by a row that says "already sent" for a send that never happened.
     *
     * Deliberately deletes by matching the exact data insert() just wrote, not by a captured
     * entity_id/lastInsertId() - AdapterInterface (this store's only dependency, deliberately not
     * the concrete Zend adapter class) doesn't declare lastInsertId() at all, so relying on it
     * would make this store untestable without a real database connection.
     *
     * @param array<string, mixed> $data the exact same array just passed to insert()
     */
    public function deleteMatching(string $table, array $data): void
    {
        $connection = $this->resourceConnection->getConnection();

        $where = [];
        foreach ($data as $column => $value) {
            $where[] = $connection->quoteInto($connection->quoteIdentifier($column) . ' = ?', $value);
        }

        $connection->delete($this->resourceConnection->getTableName($table), implode(' AND ', $where));
    }
}
