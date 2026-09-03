<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ContentBlock\Producer;

use Magento\Framework\App\ResourceConnection;
use Ordo\Automation\Model\ContentBlock;

/**
 * Reads ONLY the ordo_content_block_rss_cache table — never makes an HTTP call itself. The
 * cache is kept fresh by Cron\RefreshRssContentBlocks (and Controller\Adminhtml\ContentBlock\
 * RefreshRss for an on-demand admin refresh), both of which use RssFetcher; a dispatch-time
 * email render must never block on (or fail because of) a slow/unreachable external feed.
 */
class RssProducer implements ProducerInterface
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    public function render(ContentBlock $block): string
    {
        $blockId = $block->getEntityId();
        if ($blockId === null) {
            return '';
        }

        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('ordo_content_block_rss_cache');

        $html = $connection->fetchOne(
            $connection->select()
                ->from($table, 'rendered_html')
                ->where('content_block_id = ?', $blockId)
        );

        return $html !== false ? (string) $html : '';
    }
}
