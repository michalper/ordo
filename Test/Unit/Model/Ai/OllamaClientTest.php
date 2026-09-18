<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Model\Ai;

use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\Ai\OllamaClient;
use Ordo\Automation\Model\Http\JsonApiClient;
use Ordo\Automation\Model\RateLimit\OutboundRateLimiter;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class OllamaClientTest extends TestCase
{
    private JsonApiClient&\PHPUnit\Framework\MockObject\MockObject $jsonApiClient;
    private Config&\PHPUnit\Framework\MockObject\Stub $config;
    private OutboundRateLimiter&\PHPUnit\Framework\MockObject\MockObject $rateLimiter;
    private LoggerInterface&\PHPUnit\Framework\MockObject\MockObject $logger;
    private OllamaClient $client;

    protected function setUp(): void
    {
        $this->jsonApiClient = $this->createMock(JsonApiClient::class);
        $this->config = $this->createStub(Config::class);
        $this->rateLimiter = $this->createMock(OutboundRateLimiter::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->client = new OllamaClient($this->jsonApiClient, $this->config, $this->rateLimiter, $this->logger);

        $this->config->method('getOllamaBaseUrl')->willReturn('http://localhost:11434');
        $this->config->method('getOllamaModel')->willReturn('llama3.2');
        $this->config->method('getAiMaxRequestsPerSecond')->willReturn(2);
    }

    public function testNoBaseUrlConfiguredReturnsNullWithoutCallingOut(): void
    {
        $this->config = $this->createStub(Config::class);
        $this->config->method('getOllamaBaseUrl')->willReturn('');
        $client = new OllamaClient($this->jsonApiClient, $this->config, $this->rateLimiter, $this->logger);

        $this->jsonApiClient->expects(self::never())->method('postJson');
        $this->rateLimiter->expects(self::never())->method('throttle');
        $this->logger->expects(self::never())->method('error');

        self::assertNull($client->generate('a prompt'));
    }

    public function testSuccessfulResponseReturnsTrimmedText(): void
    {
        $this->rateLimiter->expects(self::once())->method('throttle')->with('ollama', 2.0);
        $this->jsonApiClient->expects(self::once())
            ->method('postJson')
            ->with(
                'http://localhost:11434/api/generate',
                ['model' => 'llama3.2', 'prompt' => 'a prompt', 'stream' => false],
                [],
                'Ollama'
            )
            ->willReturn(['response' => "  generated text  \n"]);
        $this->logger->expects(self::never())->method('error');

        self::assertSame('generated text', $this->client->generate('a prompt'));
    }

    public function testEmptyResponseFieldReturnsNull(): void
    {
        $this->rateLimiter->expects(self::once())->method('throttle');
        $this->jsonApiClient->expects(self::once())->method('postJson')->willReturn(['response' => '   ']);
        $this->logger->expects(self::never())->method('error');

        self::assertNull($this->client->generate('a prompt'));
    }

    public function testMissingResponseFieldReturnsNull(): void
    {
        $this->rateLimiter->expects(self::once())->method('throttle');
        $this->jsonApiClient->expects(self::once())->method('postJson')->willReturn([]);
        $this->logger->expects(self::never())->method('error');

        self::assertNull($this->client->generate('a prompt'));
    }

    public function testTransportFailureIsCaughtAndLoggedReturnsNull(): void
    {
        $this->rateLimiter->expects(self::once())->method('throttle');
        $this->jsonApiClient->expects(self::once())
            ->method('postJson')
            ->willThrowException(new RuntimeException('connection refused'));
        $this->logger->expects(self::once())->method('error');

        self::assertNull($this->client->generate('a prompt'));
    }
}
