<?php
declare(strict_types=1);

namespace Ordo\Automation\Cron;

use Ordo\Automation\Model\Cron\CronRunLogger;
use Ordo\Automation\Model\ProductFeed\ProductFeedRefresher;

/**
 * Regenerates every registered product feed format's cached body (ProductFeedRefresher), once per
 * store per format — same "generate on a schedule, serve the cache on request" split as
 * Model\ContentBlock\RssFetcher/Cron\RefreshRssContentBlocks, for the same reason: a public
 * request should never trigger a full-catalog collection load. Each store's own price/currency/
 * base-URL scope (and format-specific enabled config) gets its own cached row and run-log
 * history, so one store's or one format's failure doesn't affect another's cached feed.
 */
class RefreshProductFeed
{
    public function __construct(
        private readonly ProductFeedRefresher $productFeedRefresher,
        private readonly CronRunLogger $cronRunLogger
    ) {
    }

    public function execute(): void
    {
        $result = $this->productFeedRefresher->refreshAll();

        $this->cronRunLogger->logSummary(sprintf(
            'refreshed %d product feed(s), %d skipped (disabled), %d failed',
            $result['generated'],
            $result['skipped'],
            $result['failed']
        ));
    }
}
