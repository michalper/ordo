<?php
declare(strict_types=1);

namespace Ordo\Automation\Cron;

use Magento\Framework\App\ResourceConnection;
use Ordo\Automation\Api\Data\CampaignTriggerInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\CampaignDispatcher;
use Ordo\Automation\Model\Cron\CronRunLogger;
use Ordo\Automation\Model\Cron\ReminderLogStore;

/**
 * A stronger, later-funnel signal than Cron\SendAbandonedCartReminders' own "cart_abandoned":
 * finds active, registered-customer quotes that (a) have had no activity for `delay_minutes`,
 * (b) are tied to a `checkout_start` tracked event (`ordo_visitor_event`, no `event_key` - see
 * view/frontend/web/js/tracker.js's own docblock) at or after the quote's own creation, and
 * (c) have no order placed since the quote started - then dispatches the "checkout_abandoned"
 * campaign trigger, capped per quote the same way cart_abandoned's own fixed reminder caps per
 * cart. Deliberately dispatches a trigger only, with no built-in reminder email of its own (same
 * reasoning as Cron\SendBrowseAbandonmentReminders) - a store wires whatever action it wants onto
 * the trigger via the campaign builder, without this cron needing to know what to send.
 *
 * Deliberately NOT built on AbstractCappedTriggerDispatchCron (its context shape hardcodes a
 * per-item event_key/product_sku, which checkout_start has none of) or
 * AbstractTriggerRowClaimedDispatchCron (that's for triggers scoped to one specific campaign_id,
 * not this dispatch()-fan-out-to-every-campaign shape) - same "structurally similar but not
 * identical, don't force it into an existing abstraction" precedent docs/adr/0065 and
 * Cron\SendLoyaltyPointsExpiringReminders's own docblock already establish.
 *
 * @phpstan-type CheckoutAbandonedRow array{
 *     quote_id: int|string,
 *     customer_id: int|string,
 *     subtotal: float|string,
 *     reminders_sent: int|string
 * }
 */
class SendCheckoutAbandonmentReminders
{
    private const string LOG_TABLE = 'ordo_checkout_abandoned_reminder_log';

    public function __construct(
        private readonly Config $config,
        private readonly ResourceConnection $resourceConnection,
        private readonly CampaignDispatcher $campaignDispatcher,
        private readonly CronRunLogger $cronRunLogger,
        private readonly ReminderLogStore $reminderLogStore
    ) {
    }

    public function execute(): void
    {
        if (!$this->config->isCheckoutAbandonmentEnabled()) {
            return;
        }

        $maxReminders = $this->config->getCheckoutAbandonmentMaxReminders();
        $rows = $this->fetchDueRows($maxReminders);

        $dispatched = 0;
        foreach ($rows as $row) {
            $quoteId = (int) $row['quote_id'];
            $logRow = ['quote_id' => $quoteId, 'sent_at' => date('Y-m-d H:i:s')];

            try {
                $claimed = $this->reminderLogStore->claimAndRunUnderLimit(
                    self::LOG_TABLE,
                    ['quote_id = ?' => $quoteId],
                    $maxReminders,
                    $logRow,
                    function () use ($row): void {
                        $this->campaignDispatcher->dispatch(
                            CampaignTriggerInterface::TRIGGER_CHECKOUT_ABANDONED,
                            [
                                'customer_id' => (int) $row['customer_id'],
                                'cart_subtotal' => (float) $row['subtotal'],
                            ]
                        );
                    }
                );
            } catch (\Throwable $e) {
                $this->cronRunLogger->logFailure(
                    sprintf('dispatch checkout_abandoned trigger for quote #%d', $quoteId),
                    $e
                );
                continue;
            }

            if ($claimed) {
                $dispatched++;
            }
        }

        $this->cronRunLogger->logSummary(sprintf('dispatched %d checkout_abandoned trigger(s)', $dispatched));
    }

    /**
     * @return array<int, CheckoutAbandonedRow>
     */
    private function fetchDueRows(int $maxReminders): array
    {
        $delayMinutes = $this->config->getCheckoutAbandonmentDelayMinutes();
        $minSubtotal = $this->config->getCheckoutAbandonmentMinSubtotal();
        $cutoff = date('Y-m-d H:i:s', (int) strtotime("-{$delayMinutes} minutes"));

        $connection = $this->resourceConnection->getConnection();
        $quoteTable = $this->resourceConnection->getTableName('quote');
        $eventTable = $this->resourceConnection->getTableName('ordo_visitor_event');
        $orderTable = $this->resourceConnection->getTableName('sales_order');
        $logTable = $this->resourceConnection->getTableName(self::LOG_TABLE);

        $hasCheckoutStartSinceCreated = sprintf(
            'EXISTS (SELECT 1 FROM %s AS e WHERE e.customer_id = q.customer_id'
                . ' AND e.event_type = \'checkout_start\' AND e.created_at >= q.created_at)',
            $connection->quoteIdentifier($eventTable)
        );
        $notExistsOrderSince = sprintf(
            'NOT EXISTS (SELECT 1 FROM %s AS o WHERE o.customer_id = q.customer_id'
                . ' AND o.created_at > q.created_at)',
            $connection->quoteIdentifier($orderTable)
        );

        $select = $connection->select()
            ->from(['q' => $quoteTable], ['quote_id' => 'q.entity_id', 'customer_id', 'subtotal'])
            ->joinLeft(
                ['l' => $logTable],
                'l.quote_id = q.entity_id',
                ['reminders_sent' => new \Zend_Db_Expr('COUNT(l.entity_id)')]
            )
            ->where('q.is_active = 1')
            ->where('q.items_count > 0')
            ->where('q.customer_id IS NOT NULL')
            ->where('q.updated_at <= ?', $cutoff)
            ->where('q.subtotal >= ?', $minSubtotal)
            ->where($hasCheckoutStartSinceCreated)
            ->where($notExistsOrderSince)
            ->group('q.entity_id')
            ->having('reminders_sent < ?', $maxReminders);

        /** @var array<int, CheckoutAbandonedRow> $rows */
        $rows = $connection->fetchAll($select);

        return $rows;
    }
}
