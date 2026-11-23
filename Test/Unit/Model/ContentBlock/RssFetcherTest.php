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
use Ordo\Automation\Model\Push\PushEndpointValidator;
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
    private PushEndpointValidator&\PHPUnit\Framework\MockObject\MockObject $endpointValidator;
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
        $this->endpointValidator = $this->createMock(PushEndpointValidator::class);
        // Every test below uses either https://example.com/... (pins to 93.184.216.34, the
        // documented TEST-NET-adjacent example.com address) or a literal IP host - see
        // individual tests for their own resolvePinnedIp() overrides.
        $this->endpointValidator->method('resolvePinnedIp')->willReturnCallback(
            function (string $url, array $allowedSchemes) {
                // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged
                $host = parse_url($url, PHP_URL_HOST);
                if ($host === 'example.com') {
                    return '93.184.216.34';
                }
                if (is_string($host) && filter_var($host, FILTER_VALIDATE_IP) !== false) {
                    return $host;
                }
                return null;
            }
        );

        $this->fetcher = new RssFetcher(
            $this->curl,
            $this->resourceConnection,
            $this->rssItemRenderer,
            $this->logger,
            $this->endpointValidator
        );
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

    private const string VALID_RSS = <<<'XML'
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

    /**
     * fetchBody() delegates all SSRF validation to PushEndpointValidator (see that class's own
     * docblock and tests for every distinct rejection reason it covers - bad scheme, a literal
     * private/reserved IP, a hostname resolving to one, an unresolvable host, localhost/.local) -
     * this only needs to confirm a rejected URL is never passed to curl and produces one clear
     * fetch_error message, not re-verify PushEndpointValidator's own internal logic.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testRejectsFeedUrlThatFailsEndpointValidationWithoutCallingCurl(): void
    {
        $this->endpointValidator = $this->createMock(PushEndpointValidator::class);
        $this->endpointValidator->expects(self::once())->method('resolvePinnedIp')
            ->with('http://169.254.169.254/latest/meta-data', ['http', 'https'])
            ->willReturn(null);
        $this->fetcher = new RssFetcher(
            $this->curl,
            $this->resourceConnection,
            $this->rssItemRenderer,
            $this->logger,
            $this->endpointValidator
        );

        $this->curl->expects(self::never())->method('get');
        $this->connection->expects(self::once())->method('query')->with(
            self::anything(),
            [3, 'Feed URL failed validation (must be http(s) and resolve only to a public address).']
        );
        $this->rssItemRenderer->expects(self::never())->method('render');
        $this->logger->expects(self::once())->method('error');

        $this->fetcher->fetch($this->makeBlock(['feed_url' => 'http://169.254.169.254/latest/meta-data']));
    }

    /**
     * fetchBody() must pin the real curl connection to the exact address PushEndpointValidator
     * validated - not just trust curl's own, separate resolution of the same hostname at connect
     * time (that would reopen the DNS-rebinding gap resolving/validating exists to close).
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testPinsTheCurlConnectionToTheValidatedResolvedAddress(): void
    {
        $setOptionCalls = [];
        $this->curl->method('setOption')->willReturnCallback(function ($option, $value) use (&$setOptionCalls) {
            $setOptionCalls[$option] = $value;
            return true;
        });
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn(self::VALID_RSS);

        $this->fetcher->fetch($this->makeBlock(['feed_url' => 'https://example.com/feed.xml']));

        self::assertSame(['example.com:443:93.184.216.34'], $setOptionCalls[CURLOPT_RESOLVE] ?? null);
    }

    /**
     * A feed URL whose host is already a literal, safe IP still goes through
     * PushEndpointValidator (which short-circuits DNS resolution for a literal IP itself) - the
     * same address it returns is what fetchBody() pins the curl connection to.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testAcceptsFeedUrlWithALiteralPublicIpHost(): void
    {
        $setOptionCalls = [];
        $this->curl->method('setOption')->willReturnCallback(function ($option, $value) use (&$setOptionCalls) {
            $setOptionCalls[$option] = $value;
            return true;
        });
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn(self::VALID_RSS);

        $this->fetcher->fetch($this->makeBlock(['feed_url' => 'http://93.184.216.34/feed.xml']));

        self::assertSame(['93.184.216.34:80:93.184.216.34'], $setOptionCalls[CURLOPT_RESOLVE] ?? null);
    }

    /**
     * fetchBody() must pass both schemes to PushEndpointValidator - unlike push/webhook, a
     * legitimately configured RSS feed can be plain http, not just https.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testPassesBothHttpAndHttpsAsAllowedSchemesToTheEndpointValidator(): void
    {
        $this->endpointValidator = $this->createMock(PushEndpointValidator::class);
        $this->endpointValidator->expects(self::once())->method('resolvePinnedIp')
            ->with('https://example.com/feed.xml', ['http', 'https'])
            ->willReturn('93.184.216.34');
        $this->fetcher = new RssFetcher(
            $this->curl,
            $this->resourceConnection,
            $this->rssItemRenderer,
            $this->logger,
            $this->endpointValidator
        );

        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn(self::VALID_RSS);

        $this->fetcher->fetch($this->makeBlock(['feed_url' => 'https://example.com/feed.xml']));
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
