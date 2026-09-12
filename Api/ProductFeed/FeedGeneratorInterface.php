<?php
declare(strict_types=1);

namespace Ordo\Automation\Api\ProductFeed;

/**
 * One product feed format (Google Merchant Center RSS+g:, Meta Catalog CSV). Registered by feed
 * code in di.xml (Model\ProductFeed\FeedGeneratorPool) — same "interface + pool, keyed by string
 * code, wired via di.xml" shape as Api\AdAudience\SyncClientInterface/
 * Model\AdAudience\SyncClientPool. Cron\RefreshProductFeed and Controller\Adminhtml\ProductFeed\
 * RefreshNow only ever depend on this interface and the pool, never on a concrete generator, so
 * adding a third format never means touching either of them.
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
     * @param int $storeId Which store's price/currency/base-URL scope (and store-scoped feed
     *   config) to generate the feed for.
     * @return array{content: string, productCount: int}
     */
    public function generate(int $storeId): array;
}
