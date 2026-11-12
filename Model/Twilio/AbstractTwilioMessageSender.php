<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Twilio;

use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\RateLimit\OutboundRateLimiter;
use Psr\Log\LoggerInterface;
use Twilio\Exceptions\RestException;
use Twilio\Exceptions\TwilioException;
use Twilio\Http\Client as TwilioHttpClient;
use Twilio\Rest\Client;

/**
 * Shared Twilio\Rest\Client construction/caching and request/error-handling shape for every
 * Twilio Programmable Messaging sender this module has (Model\Sms\TwilioSmsSender, Model\Rcs\
 * TwilioRcsSender - both authenticate with the same Restricted API Key, see Helper\Config's own
 * getTwilioApiKeySid()/Secret()/getTwilioAccountSid() docblocks). The official twilio/sdk package
 * (Twilio\Rest\Client), not a hand-rolled Curl call, gives correct request signing, a maintained
 * error taxonomy, and request building, at the cost of one Composer dependency.
 *
 * Subclasses only need to say what the request's provider-specific options are
 * (providerOptions()), how to rate-limit it, and - optionally - how to turn a REST error into a
 * more specific exception (translateRestException()); see TwilioSmsSender's own override for
 * why only SMS needs that last one.
 */
abstract class AbstractTwilioMessageSender
{
    private ?Client $client = null;

    /**
     * @var array{0: string, 1: string, 2: string}|null the (apiKeySid, apiKeySecret, accountSid)
     *  tuple $client was actually built with, so a config change (credential rotation) still
     *  rebuilds the client instead of silently keeping stale credentials - this only matters for
     *  the long-lived queue consumer process (see AGENTS.md), not a normal per-request lifecycle.
     */
    private ?array $clientCredentials = null;

    public function __construct(
        protected readonly Config $config,
        protected readonly LoggerInterface $logger,
        private readonly OutboundRateLimiter $rateLimiter
    ) {
    }

    /**
     * @return string the provider's message ID (Twilio's "Sid")
     * @throws \Throwable on any failure to send - see translateRestException()'s own docblock for
     *   how a subclass can turn a specific REST error code into something more specific than the
     *   generic \RuntimeException this throws by default.
     */
    public function send(string $toPhone, string $message): string
    {
        $client = $this->getClient();

        $this->rateLimiter->throttle($this->rateLimiterKey(), (float) $this->maxRequestsPerSecond());

        try {
            $options = $this->providerOptions($toPhone);
            $options['body'] = $message;
            $twilioMessage = $client->messages->create($toPhone, $options);
        } catch (RestException $e) {
            throw $this->translateRestException($toPhone, $e);
        } catch (TwilioException $e) {
            $this->logger->error(sprintf(
                'Ordo_Automation: Twilio %s request to %s failed: %s',
                $this->requestLabel(),
                $toPhone,
                $e->getMessage()
            ));

            throw new \RuntimeException('Failed to reach the Twilio API.', 0, $e);
        }

        return (string) $twilioMessage->sid;
    }

    /**
     * The request's provider-specific options (e.g. "from"/"statusCallback" for SMS,
     * "messagingServiceSid" for RCS), everything Twilio\Rest\Client::messages->create() needs
     * besides "body", which send() adds itself.
     *
     * @return array<string, mixed>
     */
    abstract protected function providerOptions(string $toPhone): array;

    abstract protected function rateLimiterKey(): string;

    abstract protected function maxRequestsPerSecond(): int;

    /**
     * Short label for log/exception messages ("SMS"/"RCS").
     */
    abstract protected function requestLabel(): string;

    /**
     * Turns a Twilio REST error into the exception send() throws. The default just logs and
     * wraps it in a generic \RuntimeException - TwilioSmsSender overrides this to special-case
     * error 21610 (recipient opted out via Twilio's STOP-keyword convention) into
     * Sms\OptedOutException instead. RCS has no equivalent override since it has no STOP-keyword
     * convention to detect - see TwilioRcsSender's own docblock.
     */
    protected function translateRestException(string $toPhone, RestException $e): \Throwable
    {
        $this->logger->error(sprintf(
            'Ordo_Automation: Twilio %s request to %s failed (error %d): %s',
            $this->requestLabel(),
            $toPhone,
            $e->getCode(),
            $e->getMessage()
        ));

        return new \RuntimeException(
            sprintf('Twilio API rejected the %s request.', $this->requestLabel()),
            0,
            $e
        );
    }

    /**
     * Reuses one Twilio\Rest\Client instance across calls instead of constructing a new one per
     * send() - rebuilt only if the credentials actually changed since the last call (a rotated
     * API key/secret), not on every send.
     *
     * Twilio's PHP SDK has no separate "forApiKey" constructor - API Key auth uses the same
     * Client constructor as Auth Token auth, just with the API Key SID/Secret as
     * username/password and the real Account SID passed explicitly as the third argument
     * (see https://www.twilio.com/docs/iam/api-keys).
     */
    private function getClient(): Client
    {
        $credentials = [
            $this->config->getTwilioApiKeySid(),
            $this->config->getTwilioApiKeySecret(),
            $this->config->getTwilioAccountSid(),
        ];

        if (!$this->client instanceof Client || $this->clientCredentials !== $credentials) {
            $this->client = new Client(
                $credentials[0],
                $credentials[1],
                $credentials[2],
                null,
                $this->makeHttpClient()
            );
            $this->clientCredentials = $credentials;
        }

        return $this->client;
    }

    /**
     * Seam for tests: *SenderTest overrides this to inject a fake Twilio\Http\Client so it can
     * drive the real SDK request-building/error-parsing logic without a real network call,
     * rather than hand-mocking Twilio\Rest\Client's large generated class graph. Returning null
     * here (the production default) lets the SDK fall back to its own CurlClient.
     */
    protected function makeHttpClient(): ?TwilioHttpClient
    {
        return null;
    }
}
