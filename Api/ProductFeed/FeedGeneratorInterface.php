<?php
declare(strict_types=1);

namespace Ordo\Automation\Api\ProductFeed;

/**
 * One product feed format (Google Merchant Center RSS+g:, Meta Catalog CSV). Registered by feed
 * code in di.xml (Model\ProductFeed\FeedGeneratorPool) — same "interface + pool, keyed by string
 * code, wired via di.xml" shape as Api\AdAudience\SyncClientInterface/
 * Model\AdAudience\SyncClientPool. Cron\RefreshProductFeed and Controller\Adminhtml\ProductFeed\
 * RefreshNow only ever depend on this interface, the pool, and Model\ProductFeed\
 * ProductFeedRefresher (which owns the actual catalog walk — see its own class doc), never on a
 * concrete generator, so adding a third format never means touching any of them.
 */
interface FeedGeneratorInterface
{
    /**
     * Short, stable identifier persisted as ordo_product_feed_cache.feed_code /
     * ordo_product_feed_run_log.feed_code (max 32 chars) — e.g. "google_merchant", "meta_catalog".
     */
    public function getFeedCode(): string;

    /**
     * The Content-Type header Controller\ProductFeed serves the cached body under.
     */
    public function getContentType(): string;

    /**
     * Whether this feed format is turned on for the given store — its own config toggle
     * (e.g. Config::isShoppingFeedEnabled()/isMetaCatalogFeedEnabled()). Cron\RefreshProductFeed
     * and Controller\Adminhtml\ProductFeed\RefreshNow skip a disabled feed entirely rather than
     * generating and caching one nobody asked for.
     */
    public function isEnabled(int $storeId): bool;

    /**
     * Resets this generator's own per-store state (store/currency scope, item accumulator, any
     * format header) ahead of a batch of addProduct() calls for $storeId. ProductFeedRefresher
     * calls this once per store, before the ONE shared catalog walk every enabled generator reads
     * from starts feeding it products — see ADR 0022 for why generate() became three steps
     * instead of one: every registered format scopes the catalog identically (enabled,
     * catalog/search-visible), so walking it once and offering each product to every active
     * generator replaced each generator walking the whole catalog separately.
     *
     * @param int $storeId Which store's price/currency/base-URL scope (and store-scoped feed
     *   config) this batch is for.
     */
    public function startFeed(int $storeId): void;

    /**
     * Offered once per product from the shared catalog walk startFeed() was just called ahead of.
     * Implementations decide their own per-product skip rules here (missing price/image/required
     * field) — the walk itself scopes every generator identically, so a skip here is a
     * format-specific business rule, not a catalog filter.
     */
    public function addProduct(\Magento\Catalog\Model\Product $product): void;

    /**
     * Called once per store, after every product from that store's walk has been offered via
     * addProduct() — finalizes and returns the accumulated feed body. Must not be called without
     * a preceding startFeed(), and the next startFeed() call resets the state this reads.
     *
     * @return array{content: string, productCount: int}
     */
    public function finishFeed(): array;
}
