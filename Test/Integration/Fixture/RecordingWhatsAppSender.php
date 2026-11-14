<?php
declare(strict_types=1);

namespace Ordo\Automation\Test\Integration\Fixture;

use Ordo\Automation\Model\WhatsApp\WhatsAppSender;

/**
 * Same "extend the real class, override only the network call" shape as
 * RecordingTransportBuilder - every real dependency (Helper\Config reading real store config,
 * Model\RateLimit\OutboundRateLimiter) comes from real DI, only sendFreeform()'s own call to
 * Meta's Graph API never actually happens. Used by Test/Integration/OutboundReplySenderTest for
 * the WhatsApp half of the admin reply path (SmsSenderInterface already has
 * RecordingTwilioSmsSender for the SMS half).
 */
class RecordingWhatsAppSender extends WhatsAppSender
{
    /** @var array<int, array{toPhone: string, message: string}> */
    public array $calls = [];

    public function sendFreeform(string $toPhone, string $message): string
    {
        $this->calls[] = ['toPhone' => $toPhone, 'message' => $message];

        return 'wamid.recorded_test_id';
    }
}
