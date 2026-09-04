<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Sms;

use Ordo\Automation\Helper\Config;
use Psr\Log\LoggerInterface;
use Twilio\Exceptions\RestException;
use Twilio\Exceptions\TwilioException;
use Twilio\Http\Client as TwilioHttpClient;
use Twilio\Rest\Client;

/**
 * Sends an SMS via the official twilio/sdk package (Twilio\Rest\Client), not a hand-rolled Curl
 * call — the SDK gives correct request signing, a maintained error taxonomy (so the opted-out
 * case in send() can be detected by error code rather than string-matching a response body), and
 * request building, at the cost of one new Composer dependency — this module's first third-party
 * API SDK (see composer.json).
 *
 * Error code 21610 is Twilio's own "recipient has opted out" code
 * (https://www.twilio.com/docs/api/errors/21610) — Twilio auto-manages the STOP/opt-out block
 * list for long-code/toll-free numbers, so this is an expected, routine outcome, not a delivery
 * failure, and gets its own exception type so the campaign action layer can log/record it
 * distinctly from a real failure.
 */
class TwilioSmsSender implements SmsSenderInterface
{
    private const int OPTED_OUT_ERROR_CODE = 21610;

    public function __construct(
        private readonly Config $config,
        private readonly CallbackUrlBuilder $callbackUrlBuilder,
        private readonly LoggerInterface $logger
    ) {
    }

    public function send(string $toPhone, string $message): string
    {
        $client = new Client(
            $this->config->getTwilioAccountSid(),
            $this->config->getTwilioAuthToken(),
            null,
            null,
            $this->makeHttpClient()
        );

        try {
            $twilioMessage = $client->messages->create($toPhone, [
                'from' => $this->config->getTwilioFromNumber(),
                'body' => $message,
                'statusCallback' => $this->callbackUrlBuilder->getSmsStatusCallbackUrl(),
            ]);
        } catch (RestException $e) {
            if ($e->getCode() === self::OPTED_OUT_ERROR_CODE) {
                throw new OptedOutException(sprintf('%s has opted out of SMS.', $toPhone), 0, $e);
            }

            $this->logger->error(sprintf(
                'Ordo_Automation: Twilio SMS request to %s failed (error %d): %s',
                $toPhone,
                $e->getCode(),
                $e->getMessage()
            ));

            throw new \RuntimeException('Twilio API rejected the SMS request.', 0, $e);
        } catch (TwilioException $e) {
            $this->logger->error(sprintf(
                'Ordo_Automation: Twilio SMS request to %s failed: %s',
                $toPhone,
                $e->getMessage()
            ));

            throw new \RuntimeException('Failed to reach the Twilio API.', 0, $e);
        }

        return (string) $twilioMessage->sid;
    }

    /**
     * Seam for tests: TwilioSmsSenderTest overrides this to inject a fake Twilio\Http\Client so
     * it can drive the real SDK request-building/error-parsing logic without a real network
     * call, rather than hand-mocking Twilio\Rest\Client's large generated class graph. Returning
     * null here (the production default) lets the SDK fall back to its own CurlClient.
     */
    protected function makeHttpClient(): ?TwilioHttpClient
    {
        return null;
    }
}
