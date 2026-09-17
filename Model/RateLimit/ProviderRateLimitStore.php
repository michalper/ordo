<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\RateLimit;

use Magento\Framework\App\ResourceConnection;

/**
 * The cross-process claim primitive backing Model\RateLimit\OutboundRateLimiter - see
 * etc/db_schema.xml's ordo_provider_rate_limit comment for why this replaced the old
 * CacheInterface-backed pacing. Deliberately NOT an AbstractDb + Model pair like this module's
 * other "claim" tables (ResourceModel\MessageSendRetry::claim() etc.) - there's no real domain
 * entity here, just one shared counter row per channel, so a thin standalone class talking
 * straight to ResourceConnection is a better fit than forcing an ActiveRecord model onto it.
 */
class ProviderRateLimitStore
{
    private const string TABLE = 'ordo_provider_rate_limit';

    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * Atomically reserves the next available slot for $channel, at least $minIntervalMicros
     * microseconds after whichever call last won this same claim (across every process).
     *
     * A single INSERT..ON DUPLICATE KEY UPDATE does both the "ensure a row exists for a
     * never-before-seen channel" and the "is it actually time yet" check in one round trip,
     * relying on MySQL's own affected-rows semantics for that statement to tell them apart:
     *   - 1 row affected: the INSERT branch ran (first-ever call for this channel) - always
     *     allowed, since there's nothing to space out from yet.
     *   - 2 rows affected: the UPDATE branch ran AND actually changed the value - this call
     *     arrived at/after the reserved slot, so it won the claim and pushed the next slot
     *     forward by $minIntervalMicros.
     *   - 0 rows affected: the UPDATE branch ran but the IF() left the value unchanged - another
     *     call already claimed the current window; too soon.
     * ":next"/":next2" bind the same computed value twice (not one placeholder reused) - PDO
     * doesn't allow binding one named parameter to two different call sites of the same value
     * with emulation off.
     */
    public function claim(string $channel, int $minIntervalMicros): bool
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);
        $now = (int) round(microtime(true) * 1_000_000);
        $next = $now + $minIntervalMicros;

        // ON DUPLICATE KEY UPDATE has no equivalent in Magento's query builder API; parameters
        // are still bound below, not interpolated, and the table name comes from
        // getTableName()/quoteIdentifier(), never from user input.
        $statement = $connection->query(
            // phpcs:ignore Magento2.SQL.RawQuery.FoundRawSql
            'INSERT INTO ' . $connection->quoteIdentifier($table) . ' (channel, next_available_at_micros) '
            . 'VALUES (:channel, :next) ON DUPLICATE KEY UPDATE next_available_at_micros = '
            . 'IF(next_available_at_micros <= :now, :next2, next_available_at_micros)',
            ['channel' => $channel, 'next' => $next, 'now' => $now, 'next2' => $next]
        );

        return $statement->rowCount() > 0;
    }
}
