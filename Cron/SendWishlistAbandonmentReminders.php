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
 * The shared scan/claim/dispatch mechanics (crash-safe claim-before-dispatch, the concurrent-run
 * freshness guard, logging) live in AbstractCappedTriggerDispatchCron - this class only supplies
 * the SELECT and the trigger code.
 *
 * @phpstan-type WishlistAbandonedRow array{
 *     customer_id: int|string,
 *     event_key: string,
 *     reminders_sent: int|string
 * }
 */
class SendWishlistAbandonmentReminders extends AbstractCappedTriggerDispatchCron
{
    private const string LOG_TABLE = 'ordo_wishlist_abandoned_reminder_log';

    public function __construct(
        private readonly Config $config,
        ResourceConnection $resourceConnection,
        CampaignDispatcher $campaignDispatcher,
        CronRunLogger $cronRunLogger,
        ReminderLogStore $reminderLogStore
    ) {
        parent::__construct($resourceConnection, $campaignDispatcher, $cronRunLogger, $reminderLogStore);
    }

    protected function isEnabled(): bool
    {
        return $this->config->isWishlistAbandonmentEnabled();
    }

    protected function logTable(): string
    {
        return self::LOG_TABLE;
    }

    protected function triggerCode(): string
    {
        return CampaignTriggerInterface::TRIGGER_WISHLIST_ABANDONED;
    }

    protected function triggerLabel(): string
    {
        return 'wishlist abandonment';
    }

    /**
     * @return array<int, WishlistAbandonedRow>
     */
    protected function fetchDueRows(): array
    {
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
            ->joinInner(['cpe' => $productTable], 'cpe.entity_id = wi.product_id', ['event_key' => 'cpe.sku'])
            ->joinLeft(
                ['l' => $logTable],
                'l.customer_id = w.customer_id AND l.event_key = cpe.sku',
                ['reminders_sent' => new \Zend_Db_Expr('COUNT(l.entity_id)')]
            )
            ->where('wi.added_at <= ?', $cutoff)
            ->where($notExistsOrderSinceForSku)
            ->group(['w.customer_id', 'cpe.sku'])
            ->having('reminders_sent < ?', $maxReminders);

        /** @var array<int, WishlistAbandonedRow> $rows */
        $rows = $connection->fetchAll($select);

        return $rows;
    }
}
