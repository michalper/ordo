<?php
declare(strict_types=1);

namespace Ordo\Automation\Cron;

use Magento\Store\Model\StoreManagerInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Cron\CronRunLogger;
use Ordo\Automation\Model\ProductFeed\GoogleMerchantFeedGenerator;
use Ordo\Automation\Model\ProductFeed\ProductFeedCacheWriter;
use Psr\Log\LoggerInterface;

/**
 * Regenerates the cached Google Merchant Center feed XML, once per store — same "generate on a
 * schedule, serve the cache on request" split as Model\ContentBlock\RssFetcher/
 * Cron\RefreshRssContentBlocks, for the same reason: a public request should never trigger a
 * full-catalog collection load. Each store's own price/currency/base-URL scope (and shopping
 * feed title/description/enabled config, all store-scoped) gets its own cached row and run-log
 * history, so one store's failure doesn't affect another's cached feed.
 */
class RefreshProductFeed
{
    public function __construct(
        private readonly GoogleMerchantFeedGenerator $googleMerchantFeedGenerator,
        private readonly ProductFeedCacheWriter $productFeedCacheWriter,
        private readonly Config $config,
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

        foreach ($this->storeManager->getStores() as $store) {
            $storeId = (int) $store->getId();

            if (!$this->config->isShoppingFeedEnabled($storeId)) {
                $skipped++;
                continue;
            }

            try {
                $result = $this->googleMerchantFeedGenerator->generate($storeId);
                $this->productFeedCacheWriter->writeSuccess($storeId, $result['xml'], $result['productCount']);
                $generated++;
            } catch (\Throwable $e) {
                $this->logger->error(sprintf(
                    'Ordo_Automation: shopping feed generation failed for store #%d: %s',
                    $storeId,
                    $e->getMessage()
                ));
                $this->productFeedCacheWriter->writeError($storeId, $e->getMessage());
                $failed++;
            }
        }

        $this->cronRunLogger->logSummary(sprintf(
            'refreshed shopping feed for %d store(s), %d skipped (disabled), %d failed',
            $generated,
            $skipped,
            $failed
        ));
    }
}
