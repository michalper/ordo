<?php
declare(strict_types=1);

namespace Ordo\Automation\Cron;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Ordo\Automation\Model\ContentBlock;
use Ordo\Automation\Model\ContentBlock\RssFetcher;
use Ordo\Automation\Model\ResourceModel\ContentBlock\CollectionFactory as ContentBlockCollectionFactory;
use Psr\Log\LoggerInterface;

/**
 * Refreshes ordo_content_block_rss_cache for every enabled rss-type content block, every 30
 * minutes — see etc/crontab.xml. Dispatch-time reads (Model\ContentBlock\Producer\RssProducer)
 * only ever read that cache, never the network, so this cron is the only thing that actually
 * fetches feeds outside of an admin's on-demand "refresh now" (Controller\Adminhtml\
 * ContentBlock\RefreshRss).
 *
 * A block whose cache row is still within the freshness window is skipped rather than
 * re-fetched — the cron runs every 30 minutes, matching the window, but a slow cron run
 * (or a block refreshed on-demand moments earlier) shouldn't cause a redundant fetch.
 * One block's fetch failure (network exception, unexpected DB error) is caught and logged so
 * it never stops the rest of the batch.
 */
class RefreshRssContentBlocks
{
    private const FRESHNESS_WINDOW_SECONDS = 30 * 60;

    public function __construct(
        private readonly ContentBlockCollectionFactory $contentBlockCollectionFactory,
        private readonly ResourceConnection $resourceConnection,
        private readonly RssFetcher $rssFetcher,
        private readonly DateTime $dateTime,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        $collection = $this->contentBlockCollectionFactory->create();
        $collection->addFieldToFilter('type', 'rss');
        $collection->addFieldToFilter('enabled', 1);

        $refreshed = 0;
        foreach ($collection as $block) {
            /** @var ContentBlock $block */
            $blockId = $block->getEntityId();
            if ($blockId === null || $this->isFresh($blockId)) {
                continue;
            }

            try {
                $this->rssFetcher->fetch($block);
                $refreshed++;
            } catch (\Throwable $e) {
                $this->logger->error(sprintf(
                    'Ordo_Automation: RefreshRssContentBlocks failed for content block #%d: %s',
                    $blockId,
                    $e->getMessage()
                ));
            }
        }

        $this->logger->info(sprintf('Ordo_Automation: refreshed %d RSS content block(s).', $refreshed));
    }

    private function isFresh(int $blockId): bool
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('ordo_content_block_rss_cache');

        $fetchedAt = $connection->fetchOne(
            $connection->select()
                ->from($table, 'fetched_at')
                ->where('content_block_id = ?', $blockId)
        );

        if ($fetchedAt === false) {
            return false;
        }

        return $this->dateTime->gmtTimestamp() - strtotime((string) $fetchedAt) < self::FRESHNESS_WINDOW_SECONDS;
    }
}
