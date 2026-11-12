<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Rcs;

use Ordo\Automation\Model\Twilio\AbstractTwilioMessageSender;

/**
 * Sends RCS (Rich Communication Services) via Model\Twilio\AbstractTwilioMessageSender - the
 * same Twilio Programmable Messaging surface and Account SID/API Key credentials
 * Model\Sms\TwilioSmsSender already uses for SMS (see Helper\Config's own ordo_channels/sms
 * fields, reused as-is), just with a Messaging Service SID as "from" (Twilio Console > Messaging
 * > Services, provisioned for RCS) instead of a phone number - not a new provider integration.
 *
 * Deliberately does NOT override translateRestException() the way TwilioSmsSender does for
 * error 21610 (Twilio's SMS/MMS opt-out code is specifically about the STOP-keyword reply
 * convention, which RCS has no equivalent of — RCS blocking happens at the device/carrier level,
 * not via a reply keyword this module could detect the same way), and does NOT pass a
 * statusCallback (no RCS delivery-status webhook controller exists yet). Both are documented v1
 * gaps, not oversights — see docs/adr/0049.
 */
class TwilioRcsSender extends AbstractTwilioMessageSender implements RcsSenderInterface
{
    protected function providerOptions(string $toPhone): array
    {
        return [
            'messagingServiceSid' => $this->config->getTwilioRcsMessagingServiceSid(),
        ];
    }

    protected function rateLimiterKey(): string
    {
        return 'twilio_rcs';
    }

    protected function maxRequestsPerSecond(): int
    {
        return $this->config->getTwilioRcsMaxRequestsPerSecond();
    }

    protected function requestLabel(): string
    {
        return 'RCS';
    }
}
