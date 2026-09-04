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
}
