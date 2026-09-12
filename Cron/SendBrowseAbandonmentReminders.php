<?php
declare(strict_types=1);

namespace Ordo\Automation\Cron;

use Magento\Framework\App\ResourceConnection;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\CampaignDispatcher;
use Ordo\Automation\Model\Cron\CronRunLogger;

/**
 * One step earlier in the funnel than Cron\SendAbandonedCartReminders: finds registered
 * customers (`ordo_visitor_event.customer_id` set — an anonymous visitor has nothing this cron
 * can email or dispatch a campaign against, unlike cart_abandoned's fixed reminder email, which
 * has none here at all) who viewed a product (`event_type = 'product_view'`) at least
 * `delay_minutes` ago and placed no order since that view, then dispatches a "browse_abandoned"
 * campaign trigger for them — capped per customer/product the same way cart_abandoned caps
 * reminders per cart, via a dedicated log table.
 *
 * Deliberately dispatches a campaign trigger only, with no built-in reminder email of its own
 * (unlike SendAbandonedCartReminders) — there is no cart/order entity here to summarize into a
 * fixed email template, so a store wires whatever action (email/SMS/popup/etc.) it wants onto the
 * `browse_abandoned` trigger via the campaign builder instead.
 *
 * Only product_view is scanned (not category_view, see ROADMAP.md's "Candidate new features"
 * entry this closes) — category-level abandonment has no single `event_key` naturally analogous
 * to "the product the customer didn't buy", and would need its own dedup/params shape; scoped
 * out to keep this cron's semantics exactly mirrored to cart_abandoned's "one entity, one dedup
 * key" shape.
 *
 * @phpstan-type BrowseAbandonedRow array{
 *     customer_id: int|string,
 *     event_key: string,
 *     reminders_sent: int|string
 * }
 */
class SendBrowseAbandonmentReminders
{
    public function __construct(
        private readonly Config $config,
        private readonly ResourceConnection $resourceConnection,
        private readonly CampaignDispatcher $campaignDispatcher,
        private readonly CronRunLogger $cronRunLogger
    ) {
    }

    public function execute(): void
    {
        if (!$this->config->isBrowseAbandonmentEnabled()) {
            return;
        }

        $delayMinutes = $this->config->getBrowseAbandonmentDelayMinutes();
        $maxReminders = $this->config->getBrowseAbandonmentMaxReminders();
        $cutoff = date('Y-m-d H:i:s', (int) strtotime("-{$delayMinutes} minutes"));

        $connection = $this->resourceConnection->getConnection();
        $eventTable = $this->resourceConnection->getTableName('ordo_visitor_event');
        $orderTable = $this->resourceConnection->getTableName('sales_order');
        $logTable = $this->resourceConnection->getTableName('ordo_browse_abandoned_reminder_log');

        $notExistsOrderSince = sprintf(
            'NOT EXISTS (SELECT 1 FROM %s AS o WHERE o.customer_id = e.customer_id'
                . ' AND o.created_at > e.created_at)',
            $connection->quoteIdentifier($orderTable)
        );

        $select = $connection->select()
            ->from(['e' => $eventTable], ['customer_id', 'event_key'])
            ->joinLeft(
                ['l' => $logTable],
                'l.customer_id = e.customer_id AND l.event_key = e.event_key',
                ['reminders_sent' => new \Zend_Db_Expr('COUNT(l.entity_id)')]
            )
            ->where('e.event_type = ?', 'product_view')
            ->where('e.customer_id IS NOT NULL')
            ->where('e.event_key IS NOT NULL')
            ->where('e.created_at <= ?', $cutoff)
            ->where($notExistsOrderSince)
            ->group(['e.customer_id', 'e.event_key'])
            ->having('reminders_sent < ?', $maxReminders);

        /** @var array<int, BrowseAbandonedRow> $rows */
        $rows = $connection->fetchAll($select);

        $dispatched = 0;
        foreach ($rows as $row) {
            // Claim (log) BEFORE dispatching, not after - a crash between a successful dispatch
            // and the log write must never cause a duplicate trigger on the next tick. If the
            // dispatch itself then fails, the claim is rolled back so this row is retried next
            // run - same reasoning as SendAbandonedCartReminders::deleteReminderLog().
            $reminderLogRow = $this->buildReminderLogRow((int) $row['customer_id'], (string) $row['event_key']);
            $this->logReminderSent($reminderLogRow);

            try {
                $this->campaignDispatcher->dispatch('browse_abandoned', [
                    'customer_id' => (int) $row['customer_id'],
                    'product_sku' => (string) $row['event_key'],
                ]);
                $dispatched++;
            } catch (\Throwable $e) {
                $this->deleteReminderLog($reminderLogRow);
                $this->cronRunLogger->logFailure(
                    sprintf(
                        'dispatch browse_abandoned trigger for customer #%d / %s',
                        (int) $row['customer_id'],
                        (string) $row['event_key']
                    ),
                    $e
                );
            }
        }

        $this->cronRunLogger->logSummary(sprintf('dispatched %d browse abandonment triggers', $dispatched));
    }

    /**
     * @return array<string, mixed>
     */
    private function buildReminderLogRow(int $customerId, string $eventKey): array
    {
        return [
            'customer_id' => $customerId,
            'event_key' => $eventKey,
            'sent_at' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function logReminderSent(array $row): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('ordo_browse_abandoned_reminder_log');

        $connection->insert($table, $row);
    }

    /**
     * Rolls back a claim row from logReminderSent() when the dispatch it claimed then fails - see
     * SendAbandonedCartReminders::deleteReminderLog()'s own docblock for the same reasoning
     * applied there (deletes by matching the exact row just inserted, not by a captured
     * entity_id/lastInsertId()).
     *
     * @param array<string, mixed> $row the exact same array just passed to logReminderSent()
     */
    private function deleteReminderLog(array $row): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('ordo_browse_abandoned_reminder_log');

        $where = [];
        foreach ($row as $column => $value) {
            $where[] = $connection->quoteInto($connection->quoteIdentifier($column) . ' = ?', $value);
        }

        $connection->delete($table, implode(' AND ', $where));
    }
}
