<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\ContentBlock;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\HTTP\Client\Curl;
use Ordo\Automation\Model\ContentBlock;
use Ordo\Automation\Model\ContentBlock\Renderer\RssItemRenderer;
use Psr\Log\LoggerInterface;

/**
 * Fetches an RSS 2.0 feed for a single content block and writes the rendered result into
 * ordo_content_block_rss_cache — the only writer of that table. Called from
 * Cron\RefreshRssContentBlocks (every 30 min, one call per enabled rss-type block) and from
 * Controller\Adminhtml\ContentBlock\RefreshRss (a synchronous, on-demand admin refresh).
 *
 * On any failure (non-200 response, XML parse error, timeout/network exception) the cache row's
 * fetch_error column is written and rendered_html is left untouched — a broken/unreachable feed
 * degrades to stale-but-still-useful content rather than blanking out an already-working email
 * block. Upsert semantics: the first successful/failed fetch for a block inserts the row,
 * every fetch after that updates it.
 */
class RssFetcher
{
    private const int TIMEOUT_SECONDS = 5;

    /** Hard cap on the response body, to protect against a malicious/misbehaving feed. */
    private const MAX_RESPONSE_BYTES = 2 * 1024 * 1024;

    /** Parsed items are capped here regardless of the block's configured item_count. */
    private const int MAX_ITEMS = 50;

    private const int DEFAULT_ITEM_COUNT = 5;

    public function __construct(
        private readonly Curl $curl,
        private readonly ResourceConnection $resourceConnection,
        private readonly RssItemRenderer $rssItemRenderer,
        private readonly LoggerInterface $logger
    ) {
    }

    public function fetch(ContentBlock $block): void
    {
        $blockId = $block->getEntityId();
        if ($blockId === null) {
            return;
        }

        $config = $block->getConfigArray();
        $feedUrl = (string) ($config['feed_url'] ?? '');
        $itemCount = (int) ($config['item_count'] ?? self::DEFAULT_ITEM_COUNT);
        if ($itemCount <= 0) {
            $itemCount = self::DEFAULT_ITEM_COUNT;
        }

        if ($feedUrl === '') {
            $this->writeError($blockId, 'No feed_url configured.');
            return;
        }

        try {
            $body = $this->fetchBody($feedUrl);
            $items = $this->parseItems($body);
        } catch (\Throwable $e) {
            $this->logger->error(sprintf(
                'Ordo_Automation: RSS fetch failed for content block #%d (%s): %s',
                $blockId,
                $feedUrl,
                $e->getMessage()
            ));
            $this->writeError($blockId, $e->getMessage());
            return;
        }

        $html = $this->rssItemRenderer->render($items, $itemCount);
        $this->writeSuccess($blockId, $html);
    }

    private function fetchBody(string $feedUrl): string
    {
        $this->assertSafeUrl($feedUrl);

        $this->curl->setTimeout(self::TIMEOUT_SECONDS);
        $this->curl->setOption(CURLOPT_MAXFILESIZE, self::MAX_RESPONSE_BYTES);
        $this->curl->setOption(CURLOPT_FOLLOWLOCATION, false);
        $this->curl->get($feedUrl);

        $status = $this->curl->getStatus();
        if ($status !== 200) {
            throw new \RuntimeException(sprintf('Unexpected HTTP status %d', $status));
        }

        $body = $this->curl->getBody();
        if (strlen($body) > self::MAX_RESPONSE_BYTES) {
            throw new \RuntimeException('Response body exceeds the maximum allowed size.');
        }

        return $body;
    }

    /**
     * feed_url is admin-configured, but this still performs a server-side request driven by
     * that input, so it's an SSRF surface: block anything that isn't a plain http(s) URL, and
     * block hostnames that are themselves a literal loopback/private/link-local/reserved
     * address or a well-known local name, so an admin can't be used (deliberately or via a
     * compromised account) to make the app server probe its own cloud metadata endpoint,
     * localhost, or internal-network services by IP. Redirects are disabled above for the same
     * reason — a validated URL could otherwise 302 to an internal address at fetch time.
     *
     * This deliberately does NOT resolve arbitrary hostnames (DNS lookups here would make unit
     * tests network-dependent, and a real resolver check belongs behind an injectable interface
     * if this needs hardening further) — a hostname that resolves to a private address via DNS
     * rebinding is a known residual risk of this simpler check, not something this method
     * catches.
     */
    private function assertSafeUrl(string $feedUrl): void
    {
        // No Magento core alternative extracts scheme/host from an arbitrary string without a network call.
        // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged
        $parts = parse_url($feedUrl);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new \RuntimeException('Feed URL must be a plain http(s) URL.');
        }

        if ($host === 'localhost' || str_ends_with($host, '.local')) {
            throw new \RuntimeException('Feed URL must not point at a local host.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP)
            && !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
        ) {
            throw new \RuntimeException('Feed URL must not point at a private/reserved address.');
        }
    }

    /**
     * @return array<int, array{title: string, link: string, description: string}>
     */
    private function parseItems(string $body): array
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($body);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($xml === false || !isset($xml->channel->item)) {
            throw new \RuntimeException('Could not parse feed as RSS 2.0.');
        }

        $items = [];
        foreach ($xml->channel->item as $item) {
            if (count($items) >= self::MAX_ITEMS) {
                break;
            }

            $items[] = [
                'title' => trim((string) $item->title),
                'link' => trim((string) $item->link),
                'description' => trim((string) $item->description),
            ];
        }

        return $items;
    }

    private function writeSuccess(int $blockId, string $html): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('ordo_content_block_rss_cache');

        // ON DUPLICATE KEY UPDATE has no equivalent in Magento's query builder API; parameters
        // are still bound below, not interpolated (see CustomerScoreManager for the same
        // pattern elsewhere in this module).
        $connection->query(
            // phpcs:ignore Magento2.SQL.RawQuery.FoundRawSql
            'INSERT INTO ' . $connection->quoteIdentifier($table)
            . ' (content_block_id, rendered_html, fetched_at, fetch_error) VALUES (?, ?, NOW(), NULL) '
            . 'ON DUPLICATE KEY UPDATE rendered_html = VALUES(rendered_html), fetched_at = VALUES(fetched_at), '
            . 'fetch_error = NULL',
            [$blockId, $html]
        );
    }

    /**
     * rendered_html has no default and can't be NULL, so a first-ever failed fetch (no prior
     * success to leave untouched) still needs a row — inserted with an empty string, which
     * Producer\RssProducer will simply render as ''.
     */
    private function writeError(int $blockId, string $message): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('ordo_content_block_rss_cache');

        $connection->query(
            // phpcs:ignore Magento2.SQL.RawQuery.FoundRawSql
            'INSERT INTO ' . $connection->quoteIdentifier($table)
            . ' (content_block_id, rendered_html, fetch_error) VALUES (?, \'\', ?) '
            . 'ON DUPLICATE KEY UPDATE fetch_error = VALUES(fetch_error)',
            [$blockId, substr($message, 0, 255)]
        );
    }
}
