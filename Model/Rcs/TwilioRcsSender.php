<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Rcs;

use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\RateLimit\OutboundRateLimiter;
use Psr\Log\LoggerInterface;
use Twilio\Exceptions\RestException;
use Twilio\Exceptions\TwilioException;
use Twilio\Http\Client as TwilioHttpClient;
use Twilio\Rest\Client;

/**
 * Sends RCS (Rich Communication Services) via the same twilio/sdk package and Programmable
 * Messaging surface Model\Sms\TwilioSmsSender already uses for SMS — RCS Business Messaging is
 * accessed through the identical `messages->create()` call, just with a Messaging Service SID as
 * "from" (Twilio Console → Messaging → Services, provisioned for RCS) instead of a phone number,
 * and the same Account SID/API Key credentials already configured for SMS (Helper\Config's own
 * ordo_channels/sms fields) — not a new provider integration.
 *
 * Deliberately does NOT replicate TwilioSmsSender's OptedOutException handling (Twilio's SMS/MMS
 * error 21610 is specifically about the STOP-keyword opt-out convention, which RCS has no
 * equivalent of — RCS blocking happens at the device/carrier level, not via a reply keyword this
 * module could detect the same way) and does NOT pass a statusCallback (no RCS delivery-status
 * webhook controller exists yet). Both are documented v1 gaps, not oversights — see
 * docs/adr/0049.
 */
class TwilioRcsSender implements RcsSenderInterface
{
    private ?Client $client = null;

    /**
     * @var array{0: string, 1: string, 2: string}|null same "rebuild only if credentials
     *  actually changed" reasoning as TwilioSmsSender::$clientCredentials.
     */
    private ?array $clientCredentials = null;

    public function __construct(
        private readonly Config $config,
        private readonly LoggerInterface $logger,
        private readonly OutboundRateLimiter $rateLimiter
    ) {
    }

    public function send(string $toPhone, string $message): string
    {
        $client = $this->getClient();

        $this->rateLimiter->throttle('twilio_rcs', (float) $this->config->getTwilioRcsMaxRequestsPerSecond());

        try {
            $twilioMessage = $client->messages->create($toPhone, [
                'messagingServiceSid' => $this->config->getTwilioRcsMessagingServiceSid(),
                'body' => $message,
            ]);
        } catch (RestException $e) {
            $this->logger->error(sprintf(
                'Ordo_Automation: Twilio RCS request to %s failed (error %d): %s',
                $toPhone,
                $e->getCode(),
                $e->getMessage()
            ));

            throw new \RuntimeException('Twilio API rejected the RCS request.', 0, $e);
        } catch (TwilioException $e) {
            $this->logger->error(sprintf(
                'Ordo_Automation: Twilio RCS request to %s failed: %s',
                $toPhone,
                $e->getMessage()
            ));

            throw new \RuntimeException('Failed to reach the Twilio API.', 0, $e);
        }

        return (string) $twilioMessage->sid;
    }

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
     * Seam for tests - same reasoning as TwilioSmsSender::makeHttpClient()'s own docblock.
     */
    protected function makeHttpClient(): ?TwilioHttpClient
    {
        return null;
    }
}
