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
 * The wishlist-funnel counterpart to Cron\SendBrowseAbandonmentReminders, same "claim before
 * dispatch, no built-in action" shape - finds registered customers who added a product to their
 * wishlist (Magento core's own native `wishlist`/`wishlist_item` tables, NOT this module's
 * `ordo_visitor_event` - a Magento wishlist always requires a logged-in customer, so there's no
 * anonymous-visitor carve-out to make here, same reasoning Observer\TrackWishlistAdd's own
 * docblock gives) at least `delay_days` ago and have placed no non-canceled order line for that
 * exact SKU since the item was added, then dispatches a `wishlist_abandoned` campaign trigger for
 * them - capped per customer/product the same way browse_abandoned caps reminders, via a
 * dedicated log table.
 *
 * Unlike SendBrowseAbandonmentReminders' "placed no order at all since the view" check (loose,
 * because a single product view isn't a strong enough purchase intent signal to tie the
 * "did they buy" check to that exact SKU), a wishlist add is a much stronger, deliberate signal -
 * so this scopes the "did they already buy" check to the exact SKU, not "any order since", the
 * same per-SKU join shape Model\Purchase\PurchasedProductResolver::hasPurchasedSku() already
 * established for the `purchased_sku` condition (sales_order joined to sales_order_item on
 * oi.sku), with an added date bound (order placed after the wishlist add, not merely ever) since
 * a customer who already owned the SKU before wishlisting it again isn't "abandoning" anything.
 *
 * Deliberately dispatches a campaign trigger only, with no built-in reminder email of its own,
 * same reasoning as browse_abandoned - a store wires whatever action it wants onto the
 * `wishlist_abandoned` trigger via the campaign builder.
 *
 * @phpstan-type WishlistAbandonedRow array{
 *     customer_id: int|string,
 *     sku: string,
 *     reminders_sent: int|string
 * }
 */
class SendWishlistAbandonmentReminders
{
    private const string LOG_TABLE = 'ordo_wishlist_abandoned_reminder_log';

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
        if (!$this->config->isWishlistAbandonmentEnabled()) {
            return;
        }

        $delayDays = $this->config->getWishlistAbandonmentDelayDays();
        $maxReminders = $this->config->getWishlistAbandonmentMaxReminders();
        $cutoff = date('Y-m-d H:i:s', (int) strtotime("-{$delayDays} days"));

        $connection = $this->resourceConnection->getConnection();
        $wishlistTable = $this->resourceConnection->getTableName('wishlist');
        $wishlistItemTable = $this->resourceConnection->getTableName('wishlist_item');
        $productTable = $this->resourceConnection->getTableName('catalog_product_entity');
        $orderTable = $this->resourceConnection->getTableName('sales_order');
        $orderItemTable = $this->resourceConnection->getTableName('sales_order_item');
        $logTable = $this->resourceConnection->getTableName(self::LOG_TABLE);

        // 'canceled' is a fixed, trusted literal (Magento's own Order::STATE_CANCELED value), not
        // user-controlled input - embedded directly rather than through quote()/quoteInto(),
        // which exist to escape untrusted values, not to type-juggle a literal already known safe.
        $notExistsOrderSinceForSku = sprintf(
            'NOT EXISTS (SELECT 1 FROM %s AS o INNER JOIN %s AS oi ON oi.order_id = o.entity_id'
                . " WHERE o.customer_id = w.customer_id AND o.state != 'canceled' AND oi.sku = cpe.sku"
                . ' AND o.created_at > wi.added_at)',
            $connection->quoteIdentifier($orderTable),
            $connection->quoteIdentifier($orderItemTable)
        );

        $select = $connection->select()
            ->from(['wi' => $wishlistItemTable], [])
            ->joinInner(['w' => $wishlistTable], 'w.wishlist_id = wi.wishlist_id', ['customer_id' => 'w.customer_id'])
            ->joinInner(['cpe' => $productTable], 'cpe.entity_id = wi.product_id', ['sku' => 'cpe.sku'])
            ->joinLeft(
                ['l' => $logTable],
                'l.customer_id = w.customer_id AND l.event_key = cpe.sku',
                ['reminders_sent' => new \Zend_Db_Expr('COUNT(l.entity_id)')]
            )
            ->where('wi.added_at <= ?', $cutoff)
            ->where($notExistsOrderSinceForSku)
            ->group(['w.customer_id', 'cpe.sku'])
            ->having('reminders_sent < ?', $maxReminders);

        // Captured before the rows are fetched: any log row for the same customer/sku with
        // sent_at at/after this moment can only have been written by a concurrently overlapping
        // run (this run's own select already counted everything older into reminders_sent).
        $runStartedAt = date('Y-m-d H:i:s');

        /** @var array<int, WishlistAbandonedRow> $rows */
        $rows = $connection->fetchAll($select);

        $dispatched = 0;
        foreach ($rows as $row) {
            // Claim (log) BEFORE dispatching, not after - same reasoning as every other reminder
            // cron in this module: a crash between a successful dispatch and the log write must
            // never cause a duplicate trigger on the next tick, and a failed dispatch must roll
            // the claim back so this row is retried next run. claim() atomically re-checks that
            // no overlapping run already claimed this customer/sku since this run started - the
            // select's HAVING cap alone is only a pre-filter.
            $reminderLogRow = [
                'customer_id' => (int) $row['customer_id'],
                'event_key' => (string) $row['sku'],
                'sent_at' => date('Y-m-d H:i:s'),
            ];
            $claimed = $this->reminderLogStore->claim(
                self::LOG_TABLE,
                [
                    'customer_id = ?' => (int) $row['customer_id'],
                    'event_key = ?' => (string) $row['sku'],
                    'sent_at >= ?' => $runStartedAt,
                ],
                $reminderLogRow
            );
            if (!$claimed) {
                continue;
            }

            try {
                $this->campaignDispatcher->dispatch(CampaignTriggerInterface::TRIGGER_WISHLIST_ABANDONED, [
                    'customer_id' => (int) $row['customer_id'],
                    'product_sku' => (string) $row['sku'],
                ]);
                $dispatched++;
            } catch (\Throwable $e) {
                $this->reminderLogStore->deleteMatching(self::LOG_TABLE, $reminderLogRow);
                $this->cronRunLogger->logFailure(
                    sprintf(
                        'dispatch wishlist_abandoned trigger for customer #%d / %s',
                        (int) $row['customer_id'],
                        (string) $row['sku']
                    ),
                    $e
                );
            }
        }

        $this->cronRunLogger->logSummary(sprintf('dispatched %d wishlist abandonment triggers', $dispatched));
    }
}
