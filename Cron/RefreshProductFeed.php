<?php
declare(strict_types=1);

namespace Ordo\Automation\Cron;

use Magento\Store\Model\StoreManagerInterface;
use Ordo\Automation\Model\Cron\CronRunLogger;
use Ordo\Automation\Model\ProductFeed\FeedGeneratorPool;
use Ordo\Automation\Model\ProductFeed\ProductFeedCacheWriter;
use Psr\Log\LoggerInterface;

/**
 * Regenerates every registered product feed format's cached body (FeedGeneratorPool), once per
 * store per format — same "generate on a schedule, serve the cache on request" split as
 * Model\ContentBlock\RssFetcher/Cron\RefreshRssContentBlocks, for the same reason: a public
 * request should never trigger a full-catalog collection load. Each store's own price/currency/
 * base-URL scope (and format-specific enabled config) gets its own cached row and run-log
 * history, so one store's or one format's failure doesn't affect another's cached feed.
 */
class RefreshProductFeed
{
    public function __construct(
        private readonly FeedGeneratorPool $feedGeneratorPool,
        private readonly ProductFeedCacheWriter $productFeedCacheWriter,
        private readonly StoreManagerInterface $storeManager,
        private readonly CronRunLogger $cronRunLogger,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        $generated = 0;
        $skipped = 0;
        $failed = 0;

        $stores = $this->storeManager->getStores();

        foreach ($this->feedGeneratorPool->getAll() as $generator) {
            foreach ($stores as $store) {
                $storeId = (int) $store->getId();

                if (!$generator->isEnabled($storeId)) {
                    $skipped++;
                    continue;
                }

                try {
                    $result = $generator->generate($storeId);
                    $this->productFeedCacheWriter->writeSuccess(
                        $generator->getFeedCode(),
                        $storeId,
                        $result['content'],
                        $result['productCount']
                    );
                    $generated++;
                } catch (\Throwable $e) {
                    $this->logger->error(sprintf(
                        'Ordo_Automation: "%s" feed generation failed for store #%d: %s',
                        $generator->getFeedCode(),
                        $storeId,
                        $e->getMessage()
                    ));
                    $this->productFeedCacheWriter->writeError($generator->getFeedCode(), $storeId, $e->getMessage());
                    $failed++;
                }
            }
        }

        $this->cronRunLogger->logSummary(sprintf(
            'refreshed %d product feed(s), %d skipped (disabled), %d failed',
            $generated,
            $skipped,
            $failed
        ));
    }
}
