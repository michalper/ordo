<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Recommendation;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * "Customers who bought X also bought Y" — classic co-purchase affinity, computed live via raw
 * SQL (no separate ledger/materialized table), same style as CalculateReorderCycle/RfmCalculator.
 *
 * Algorithm:
 *  1. Look up the target customer's own purchased SKUs.
 *  2. If they've never ordered, skip straight to the best-sellers fallback (step 4).
 *  3. Find OTHER customers who bought any of those SKUs, then find what SKUs THOSE customers
 *     also bought (excluding the target's own SKUs), ranked by how many distinct other
 *     customers bought each one.
 *  4. If step 3 doesn't produce enough SKUs, pad the result with the store's overall
 *     best-sellers (by total qty ordered), excluding SKUs already picked or already owned.
 *
 * The "other customers" set in step 3 is deliberately capped at self::MAX_OTHER_CUSTOMERS —
 * without a bound, a customer who bought a very popular SKU (e.g. a common accessory) could pull
 * in a huge share of the entire customer base before the query even gets to counting co-purchased
 * SKUs, turning a "recommend 4 products" request into a full-table scan. Capping it keeps the
 * query's cost bounded independent of how many people bought the seed SKUs, matching this
 * module's existing convention of explicitly bounding cross-customer scans (see
 * CalculateReorderCycle::LOOKBACK_ORDERS_PER_SKU for the same reasoning applied to per-SKU order
 * history).
 */
class ProductRecommender
{
    private const MAX_OTHER_CUSTOMERS = 500;

    /** Best-sellers are store-wide (not customer-specific), so one cache serves every dispatch. */
    private const BEST_SELLERS_CACHE_TTL_SECONDS = 60;

    /** Cache enough ranked best-sellers that per-customer exclusions rarely exhaust the list. */
    private const BEST_SELLERS_CACHE_SIZE = 200;

    /** @var string[]|null */
    private ?array $bestSellerCache = null;

    private ?int $bestSellerCachedAt = null;

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly DateTime $dateTime
    ) {
    }

    /**
     * @return string[] SKUs, deduplicated, at most $limit entries, co-purchase results first,
     *                   best-seller padding after
     */
    public function getRecommendedSkus(int $customerId, int $limit = 4): array
    {
        if ($limit <= 0) {
            return [];
        }

        $ownSkus = $this->ownSkus($customerId);

        $recommended = [];
        if ($ownSkus !== []) {
            $recommended = $this->coPurchasedSkus($customerId, $ownSkus, $limit);
        }

        if (count($recommended) < $limit) {
            $exclude = array_merge($ownSkus, $recommended);
            $needed = $limit - count($recommended);
            $recommended = array_merge($recommended, $this->bestSellerSkus($exclude, $needed));
        }

        return array_slice(array_values(array_unique($recommended)), 0, $limit);
    }

    /**
     * @return string[]
     */
    private function ownSkus(int $customerId): array
    {
        if ($customerId <= 0) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(['o' => $this->resourceConnection->getTableName('sales_order')], [])
            ->joinInner(
                ['oi' => $this->resourceConnection->getTableName('sales_order_item')],
                'oi.order_id = o.entity_id',
                ['sku' => 'oi.sku']
            )
            ->where('o.customer_id = ?', $customerId)
            ->where('o.state != ?', 'canceled')
            ->distinct(true);

        return array_map('strval', $connection->fetchCol($select));
    }

    /**
     * @param string[] $ownSkus
     * @return string[]
     */
    private function coPurchasedSkus(int $customerId, array $ownSkus, int $limit): array
    {
        $connection = $this->resourceConnection->getConnection();
        $orderTable = $this->resourceConnection->getTableName('sales_order');
        $orderItemTable = $this->resourceConnection->getTableName('sales_order_item');

        // Other customers who bought at least one of the target customer's own SKUs, capped at
        // MAX_OTHER_CUSTOMERS (see class docblock for why this bound exists).
        $otherCustomersSelect = $connection->select()
            ->from(['o' => $orderTable], [])
            ->joinInner(['oi' => $orderItemTable], 'oi.order_id = o.entity_id', [])
            ->where('o.customer_id IS NOT NULL')
            ->where('o.customer_id != ?', $customerId)
            ->where('o.state != ?', 'canceled')
            ->where('oi.sku IN (?)', $ownSkus)
            ->distinct(true)
            ->columns('o.customer_id')
            ->order('o.customer_id ASC')
            ->limit(self::MAX_OTHER_CUSTOMERS);

        $otherCustomerIds = array_map('intval', $connection->fetchCol($otherCustomersSelect));
        if ($otherCustomerIds === []) {
            return [];
        }

        // What those other customers also bought, excluding the target's own SKUs, ranked by
        // how many distinct other customers bought each one.
        $select = $connection->select()
            ->from(['o' => $orderTable], [])
            ->joinInner(
                ['oi' => $orderItemTable],
                'oi.order_id = o.entity_id',
                ['sku' => 'oi.sku', 'affinity' => 'COUNT(DISTINCT o.customer_id)']
            )
            ->where('o.customer_id IN (?)', $otherCustomerIds)
            ->where('o.state != ?', 'canceled')
            ->where('oi.sku NOT IN (?)', $ownSkus)
            ->group('oi.sku')
            ->order('affinity DESC')
            ->limit($limit);

        return array_map('strval', $connection->fetchCol($select));
    }

    /**
     * @param string[] $exclude
     * @return string[]
     */
    private function bestSellerSkus(array $exclude, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        $excludeSet = array_flip($exclude);
        $ranked = array_filter(
            $this->rankedBestSellerSkus(),
            static fn (string $sku): bool => !isset($excludeSet[$sku])
        );

        return array_slice(array_values($ranked), 0, $limit);
    }

    /**
     * Store-wide best-sellers, independent of any single customer — cached in-instance for
     * self::BEST_SELLERS_CACHE_TTL_SECONDS since the underlying query is an uncached, full
     * `GROUP BY oi.sku`/`SUM(oi.qty_ordered)` scan over sales_order_item, and this is the common
     * fallback path hit whenever co-purchase affinity alone doesn't fill $limit (mirrors
     * RfmCalculator::percentileRanksCache).
     *
     * @return string[]
     */
    private function rankedBestSellerSkus(): array
    {
        $now = $this->dateTime->gmtTimestamp();
        if ($this->bestSellerCache !== null
            && $this->bestSellerCachedAt !== null
            && $now - $this->bestSellerCachedAt < self::BEST_SELLERS_CACHE_TTL_SECONDS
        ) {
            return $this->bestSellerCache;
        }

        $connection = $this->resourceConnection->getConnection();
        $orderTable = $this->resourceConnection->getTableName('sales_order');
        $orderItemTable = $this->resourceConnection->getTableName('sales_order_item');

        $select = $connection->select()
            ->from(['o' => $orderTable], [])
            ->joinInner(
                ['oi' => $orderItemTable],
                'oi.order_id = o.entity_id',
                ['sku' => 'oi.sku', 'total_qty' => 'SUM(oi.qty_ordered)']
            )
            ->where('o.state != ?', 'canceled')
            ->group('oi.sku')
            ->order('total_qty DESC')
            ->limit(self::BEST_SELLERS_CACHE_SIZE);

        $this->bestSellerCache = array_map('strval', $connection->fetchCol($select));
        $this->bestSellerCachedAt = $now;

        return $this->bestSellerCache;
    }
}
