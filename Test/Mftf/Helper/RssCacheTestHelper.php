<?php

declare(strict_types=1);

namespace Ordo\Automation\Test\Mftf\Helper;

use Magento\FunctionalTestingFramework\Helper\Helper;

/**
 * Reads/clears ordo_content_block_rss_cache directly — the only way to prove
 * Model\ContentBlock\RssFetcher actually fetched+rendered a real feed (no admin UI shows this
 * cached HTML directly; it's only ever read at campaign-dispatch time, see
 * Model\ContentBlock\Producer\RssProducer), and the only way to reset a block back to
 * "never fetched" between AdminContentBlockRssTest's own admin-button and cron assertions
 * (Cron\RefreshRssContentBlocks::isFresh() would otherwise skip a block fetched moments earlier
 * within its own 30-minute freshness window).
 */
class RssCacheTestHelper extends Helper
{
    use DbConnectionTrait;

    /**
     * @throws \RuntimeException if no cache row exists, or it doesn't contain $expectedText
     */
    public function assertRssCacheContains(
        string $contentBlockId,
        string $expectedText
    ): void {
        $pdo = $this->resolveDbConnection();

        $statement = $pdo->prepare(
            'SELECT rendered_html FROM ordo_content_block_rss_cache WHERE content_block_id = :id'
        );
        $statement->execute(['id' => $contentBlockId]);
        $html = $statement->fetchColumn();

        if ($html === false) {
            throw new \RuntimeException(sprintf(
                'No ordo_content_block_rss_cache row found for content_block_id=%d.',
                $contentBlockId
            ));
        }

        if (!str_contains((string) $html, $expectedText)) {
            throw new \RuntimeException(sprintf(
                'ordo_content_block_rss_cache for content_block_id=%d does not contain "%s".',
                $contentBlockId,
                $expectedText
            ));
        }
    }

    public function deleteRssCache(
        string $contentBlockId
    ): void {
        $pdo = $this->resolveDbConnection();

        $statement = $pdo->prepare('DELETE FROM ordo_content_block_rss_cache WHERE content_block_id = :id');
        $statement->execute(['id' => $contentBlockId]);
    }
}
