<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Unit\Plugin\AiAgent;

use Magento\Framework\Webapi\Exception as WebapiException;
use Magento\Framework\Webapi\Rest\Request as RestRequest;
use Ordo\Automation\Api\AiAgentQuoteManagementInterface;
use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\AiAgent\ApiKeyAuthenticator;
use Ordo\Automation\Model\AiAgent\InboundRateLimiter;
use Ordo\Automation\Plugin\AiAgent\AuthenticateQuoteRequestPlugin;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class AuthenticateQuoteRequestPluginTest extends TestCase
{
    private RestRequest $request;
    private ApiKeyAuthenticator $authenticator;
    private InboundRateLimiter $rateLimiter;
    private Config $config;
    private AiAgentQuoteManagementInterface $subject;
    private AuthenticateQuoteRequestPlugin $plugin;

    protected function setUp(): void
    {
        $this->request = $this->createStub(RestRequest::class);
        $this->authenticator = $this->createStub(ApiKeyAuthenticator::class);
        $this->rateLimiter = $this->createStub(InboundRateLimiter::class);
        $this->config = $this->createStub(Config::class);
        $this->config->method('isAiAgentEnabled')->willReturn(true);
        $this->subject = $this->createStub(AiAgentQuoteManagementInterface::class);

        $this->plugin = new AuthenticateQuoteRequestPlugin(
            $this->request,
            $this->authenticator,
            $this->rateLimiter,
            $this->config
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testThrows404WhenAiAgentIsDisabled(): void
    {
        $this->config = $this->createStub(Config::class);
        $this->config->method('isAiAgentEnabled')->willReturn(false);
        $this->plugin = new AuthenticateQuoteRequestPlugin(
            $this->request,
            $this->authenticator,
            $this->rateLimiter,
            $this->config
        );

        try {
            $this->plugin->beforeGetQuote($this->subject);
            self::fail('Expected a WebapiException.');
        } catch (WebapiException $e) {
            self::assertSame(WebapiException::HTTP_NOT_FOUND, $e->getHttpCode());
        }
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testThrows401WhenAuthorizationHeaderIsMissing(): void
    {
        $this->request->method('getHeader')->willReturn(false);

        try {
            $this->plugin->beforeGetQuote($this->subject);
            self::fail('Expected a WebapiException.');
        } catch (WebapiException $e) {
            self::assertSame(WebapiException::HTTP_UNAUTHORIZED, $e->getHttpCode());
        }
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testThrows401WhenAuthorizationHeaderIsNotBearer(): void
    {
        $this->request->method('getHeader')->willReturn('Basic dXNlcjpwYXNz');

        try {
            $this->plugin->beforeGetQuote($this->subject);
            self::fail('Expected a WebapiException.');
        } catch (WebapiException $e) {
            self::assertSame(WebapiException::HTTP_UNAUTHORIZED, $e->getHttpCode());
        }
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testThrows401WhenTheKeyDoesNotAuthenticate(): void
    {
        $this->request->method('getHeader')->willReturn('Bearer oaa_wrongkey');
        $this->authenticator->method('authenticate')->willReturn(null);

        try {
            $this->plugin->beforeGetQuote($this->subject);
            self::fail('Expected a WebapiException.');
        } catch (WebapiException $e) {
            self::assertSame(WebapiException::HTTP_UNAUTHORIZED, $e->getHttpCode());
        }
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testThrows429WhenTheRateLimitIsExceeded(): void
    {
        $this->request->method('getHeader')->willReturn('Bearer oaa_realkey');
        $this->authenticator->method('authenticate')->willReturn('somehash');
        $this->rateLimiter->method('isAllowed')->willReturn(false);

        try {
            $this->plugin->beforeGetQuote($this->subject);
            self::fail('Expected a WebapiException.');
        } catch (WebapiException $e) {
            self::assertSame(WebapiException::HTTP_TOO_MANY_REQUESTS, $e->getHttpCode());
        }
    }

    public function testPassesWhenEnabledAuthenticatedAndUnderTheRateLimit(): void
    {
        $this->request->method('getHeader')->willReturn('Bearer oaa_realkey');
        $this->authenticator->method('authenticate')->willReturn('somehash');
        $this->rateLimiter->method('isAllowed')->willReturn(true);

        $this->plugin->beforeGetQuote($this->subject);

        $this->addToAssertionCount(1);
    }
}
