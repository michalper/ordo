<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Sms;

use Ordo\Automation\Helper\Config;
use Ordo\Automation\Model\RateLimit\OutboundRateLimiter;
use Ordo\Automation\Model\Twilio\AbstractTwilioMessageSender;
use Psr\Log\LoggerInterface;
use Twilio\Exceptions\RestException;

/**
 * Sends an SMS via Model\Twilio\AbstractTwilioMessageSender - see that class's own docblock for
 * why the SDK/client-caching mechanics live there rather than here.
 *
 * Error code 21610 is Twilio's own "recipient has opted out" code
 * (https://www.twilio.com/docs/api/errors/21610) — Twilio auto-manages the STOP/opt-out block
 * list for long-code/toll-free numbers, so this is an expected, routine outcome, not a delivery
 * failure, and gets its own exception type (translateRestException() below) so the campaign
 * action layer can log/record it distinctly from a real failure.
 *
 * Authenticates with a Restricted API Key (Config::getTwilioApiKeySid()/getTwilioApiKeySecret()),
 * not the Account Auth Token — per Twilio's own guidance
 * (https://www.twilio.com/docs/iam/api-keys), a key scoped to Programmable Messaging limits the
 * blast radius of a leaked credential to sending SMS, instead of granting full account access.
 * The Auth Token itself is kept in config only for Controller\Sms\StatusCallback's webhook
 * signature check, which Twilio always signs with the Auth Token regardless of how outbound
 * calls are authenticated.
 */
class TwilioSmsSender extends AbstractTwilioMessageSender implements SmsSenderInterface
{
    private const int OPTED_OUT_ERROR_CODE = 21610;

    public function __construct(
        Config $config,
        private readonly CallbackUrlBuilder $callbackUrlBuilder,
        LoggerInterface $logger,
        OutboundRateLimiter $rateLimiter
    ) {
        parent::__construct($config, $logger, $rateLimiter);
    }

    protected function providerOptions(string $toPhone): array
    {
        return [
            'from' => $this->config->getTwilioFromNumber(),
            'statusCallback' => $this->callbackUrlBuilder->getSmsStatusCallbackUrl(),
        ];
    }

    protected function rateLimiterKey(): string
    {
        return 'twilio';
    }

    protected function maxRequestsPerSecond(): int
    {
        return $this->config->getTwilioMaxRequestsPerSecond();
    }

    protected function requestLabel(): string
    {
        return 'SMS';
    }

    protected function translateRestException(string $toPhone, RestException $e): \Throwable
    {
        if ($e->getCode() === self::OPTED_OUT_ERROR_CODE) {
            return new OptedOutException(sprintf('%s has opted out of SMS.', $toPhone), 0, $e);
        }

        return parent::translateRestException($toPhone, $e);
    }
}
