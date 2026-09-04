<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\ContentBlock;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Magento\Framework\Registry;
use Ordo\Automation\Model\ContentBlock;
use Ordo\Automation\Model\ContentBlock\Renderer\RssItemRenderer;
use Ordo\Automation\Model\ContentBlock\RssFetcher;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RssFetcherTest extends TestCase
{
    private Curl&\PHPUnit\Framework\MockObject\MockObject $curl;
    private ResourceConnection&\PHPUnit\Framework\MockObject\MockObject $resourceConnection;
    private AdapterInterface&\PHPUnit\Framework\MockObject\MockObject $connection;
    private RssItemRenderer&\PHPUnit\Framework\MockObject\MockObject $rssItemRenderer;
    private LoggerInterface&\PHPUnit\Framework\MockObject\MockObject $logger;
    private RssFetcher $fetcher;

    protected function setUp(): void
    {
        $this->curl = $this->createMock(Curl::class);
        $this->resourceConnection = $this->createMock(ResourceConnection::class);
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->resourceConnection->method('getConnection')->willReturn($this->connection);
        $this->resourceConnection->method('getTableName')
            ->willReturnMap([['ordo_content_block_rss_cache', 'ordo_content_block_rss_cache']]);
        $this->rssItemRenderer = $this->createMock(RssItemRenderer::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->fetcher = new RssFetcher($this->curl, $this->resourceConnection, $this->rssItemRenderer, $this->logger);
    }

    private function makeBlock(array $config): ContentBlock
    {
        $resource = $this->createStub(AbstractDb::class);
        $resource->method('getIdFieldName')->willReturn('entity_id');

        $block = new ContentBlock($this->createStub(Context::class), $this->createStub(Registry::class), $resource);
        $block->setData('entity_id', 3);
        $block->setConfigArray($config);

        return $block;
    }

    private const VALID_RSS = <<<'XML'
<?xml version="1.0"?>
<rss version="2.0">
  <channel>
    <item>
      <title>First item</title>
      <link>https://example.com/1</link>
      <description>Description one</description>
    </item>
  </channel>
</rss>
XML;

    #[AllowMockObjectsWithoutExpectations]
    public function testDoesNothingWhenBlockHasNoEntityId(): void
    {
        $resource = $this->createStub(AbstractDb::class);
        $resource->method('getIdFieldName')->willReturn('entity_id');
        $block = new ContentBlock($this->createStub(Context::class), $this->createStub(Registry::class), $resource);

        $this->curl->expects(self::never())->method('get');
        $this->connection->expects(self::never())->method('query');
        $this->rssItemRenderer->expects(self::never())->method('render');
        $this->logger->expects(self::never())->method('error');

        $this->fetcher->fetch($block);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testWritesErrorWhenFeedUrlIsMissing(): void
    {
        $this->curl->expects(self::never())->method('get');
        $this->connection->expects(self::once())->method('query')->with(
            self::stringContains('ON DUPLICATE KEY UPDATE fetch_error = VALUES(fetch_error)'),
            [3, 'No feed_url configured.']
        );
        $this->rssItemRenderer->expects(self::never())->method('render');
        $this->logger->expects(self::never())->method('error');

        $this->fetcher->fetch($this->makeBlock([]));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSuccessfulFetchUpdatesRenderedHtmlAndClearsFetchError(): void
    {
        $this->curl->expects(self::once())->method('setTimeout')->with(5);
        $this->curl->expects(self::once())->method('get')->with('https://example.com/feed.xml');
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn(self::VALID_RSS);

        $this->rssItemRenderer->expects(self::once())
            ->method('render')
            ->with(
                [['title' => 'First item', 'link' => 'https://example.com/1', 'description' => 'Description one']],
                5
            )
            ->willReturn('<table>rendered</table>');

        $this->connection->expects(self::once())->method('query')->with(
            self::stringContains('fetch_error = NULL'),
            [3, '<table>rendered</table>']
        );

        $this->logger->expects(self::never())->method('error');

        $this->fetcher->fetch($this->makeBlock(['feed_url' => 'https://example.com/feed.xml']));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testOversizedResponseBodyWritesErrorAndDoesNotTouchRenderedHtml(): void
    {
        $this->curl->expects(self::once())->method('get')->with('https://example.com/feed.xml');
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn(str_repeat('a', 2 * 1024 * 1024 + 1));

        $this->rssItemRenderer->expects(self::never())->method('render');
        $this->logger->expects(self::once())->method('error');

        $this->connection->expects(self::once())->method('query')->with(
            self::logicalAnd(
                self::stringContains('fetch_error = VALUES(fetch_error)'),
                self::logicalNot(self::stringContains('rendered_html = VALUES'))
            ),
            [3, 'Response body exceeds the maximum allowed size.']
        );

        $this->fetcher->fetch($this->makeBlock(['feed_url' => 'https://example.com/feed.xml']));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testCapsParsedItemsAtMaxItemsRegardlessOfFeedSize(): void
    {
        $items = '';
        for ($i = 1; $i <= 60; $i++) {
            $items .= "<item><title>Item {$i}</title><link>https://example.com/{$i}</link>"
                . "<description>Desc {$i}</description></item>";
        }
        $feed = '<?xml version="1.0"?><rss version="2.0"><channel>' . $items . '</channel></rss>';

        $this->curl->expects(self::once())->method('get')->with('https://example.com/feed.xml');
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn($feed);

        $this->rssItemRenderer->expects(self::once())
            ->method('render')
            ->with(self::callback(static fn (array $parsed) => count($parsed) === 50), 5)
            ->willReturn('<table>rendered</table>');

        $this->connection->expects(self::once())->method('query');
        $this->logger->expects(self::never())->method('error');

        $this->fetcher->fetch($this->makeBlock(['feed_url' => 'https://example.com/feed.xml']));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testMalformedXmlWritesErrorAndDoesNotTouchRenderedHtml(): void
    {
        $this->curl->expects(self::once())->method('get')->with('https://example.com/feed.xml');
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn('not xml at all <<<');

        $this->rssItemRenderer->expects(self::never())->method('render');
        $this->logger->expects(self::once())->method('error');

        $this->connection->expects(self::once())->method('query')->with(
            self::logicalAnd(
                self::stringContains('fetch_error = VALUES(fetch_error)'),
                self::logicalNot(self::stringContains('rendered_html = VALUES'))
            ),
            [3, 'Could not parse feed as RSS 2.0.']
        );

        $this->fetcher->fetch($this->makeBlock(['feed_url' => 'https://example.com/feed.xml']));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testNon200StatusWritesErrorAndDoesNotTouchRenderedHtml(): void
    {
        $this->curl->expects(self::once())->method('get')->with('https://example.com/feed.xml');
        $this->curl->method('getStatus')->willReturn(404);
        $this->curl->method('getBody')->willReturn('');

        $this->rssItemRenderer->expects(self::never())->method('render');
        $this->logger->expects(self::once())->method('error');

        $this->connection->expects(self::once())->method('query')->with(
            self::logicalAnd(
                self::stringContains('fetch_error = VALUES(fetch_error)'),
                self::logicalNot(self::stringContains('rendered_html = VALUES'))
            ),
            [3, 'Unexpected HTTP status 404']
        );

        $this->fetcher->fetch($this->makeBlock(['feed_url' => 'https://example.com/feed.xml']));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testExceptionDuringFetchWritesErrorAndDoesNotTouchRenderedHtml(): void
    {
        $this->curl->expects(self::once())->method('get')->with('https://example.com/feed.xml')
            ->willThrowException(new \RuntimeException('Connection timed out'));

        $this->rssItemRenderer->expects(self::never())->method('render');
        $this->logger->expects(self::once())->method('error')->with(self::stringContains('Connection timed out'));

        $this->connection->expects(self::once())->method('query')->with(
            self::logicalAnd(
                self::stringContains('fetch_error = VALUES(fetch_error)'),
                self::logicalNot(self::stringContains('rendered_html = VALUES'))
            ),
            [3, 'Connection timed out']
        );

        $this->fetcher->fetch($this->makeBlock(['feed_url' => 'https://example.com/feed.xml']));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testUsesConfiguredItemCount(): void
    {
        $this->curl->expects(self::once())->method('get')->with('https://example.com/feed.xml');
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn(self::VALID_RSS);

        $this->rssItemRenderer->expects(self::once())->method('render')->with(self::isArray(), 10)
            ->willReturn('');
        $this->connection->expects(self::once())->method('query');
        $this->logger->expects(self::never())->method('error');

        $this->fetcher->fetch($this->makeBlock(['feed_url' => 'https://example.com/feed.xml', 'item_count' => 10]));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testRejectsFeedUrlPointingAtPrivateAddressWithoutCallingCurl(): void
    {
        $this->curl->expects(self::never())->method('get');
        $this->connection->expects(self::once())->method('query')->with(
            self::anything(),
            [3, 'Feed URL must not point at a private/reserved address.']
        );
        $this->rssItemRenderer->expects(self::never())->method('render');
        $this->logger->expects(self::once())->method('error');

        $this->fetcher->fetch($this->makeBlock(['feed_url' => 'http://169.254.169.254/latest/meta-data']));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testRejectsLocalhostFeedUrlWithoutCallingCurl(): void
    {
        $this->curl->expects(self::never())->method('get');
        $this->connection->expects(self::once())->method('query')->with(
            self::anything(),
            [3, 'Feed URL must not point at a local host.']
        );
        $this->rssItemRenderer->expects(self::never())->method('render');
        $this->logger->expects(self::once())->method('error');

        $this->fetcher->fetch($this->makeBlock(['feed_url' => 'http://localhost/feed.xml']));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testRejectsNonHttpFeedUrlSchemeWithoutCallingCurl(): void
    {
        $this->curl->expects(self::never())->method('get');
        $this->connection->expects(self::once())->method('query')->with(
            self::anything(),
            [3, 'Feed URL must be a plain http(s) URL.']
        );
        $this->rssItemRenderer->expects(self::never())->method('render');
        $this->logger->expects(self::once())->method('error');

        $this->fetcher->fetch($this->makeBlock(['feed_url' => 'file:///etc/passwd']));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testNonPositiveItemCountFallsBackToDefault(): void
    {
        $this->curl->expects(self::once())->method('get')->with('https://example.com/feed.xml');
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn(self::VALID_RSS);

        $this->rssItemRenderer->expects(self::once())->method('render')->with(self::isArray(), 5)
            ->willReturn('');
        $this->connection->expects(self::once())->method('query');
        $this->logger->expects(self::never())->method('error');

        $this->fetcher->fetch($this->makeBlock(['feed_url' => 'https://example.com/feed.xml', 'item_count' => -1]));
    }
}
