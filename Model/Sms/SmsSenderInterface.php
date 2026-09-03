<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Sms;

/**
 * One SMS delivery provider. Swappable via di.xml preference (see TwilioSmsSender) — the campaign
 * action layer (Model\Campaign\Action\SendSms) only ever depends on this interface, never on
 * Twilio directly, the same separation ContentBlock\Producer\ProducerInterface and
 * Framework\Mail\Template\TransportBuilder keep elsewhere in this module.
 */
interface SmsSenderInterface
{
    /**
     * @return string the provider's message ID (Twilio's "Sid") — SendSms persists this into
     *   ordo_message_log so a later delivery-status webhook can correlate back to this send.
     * @throws OptedOutException when the recipient has opted out (e.g. Twilio error 21610) — a
     *   distinct, expected outcome, not a failure.
     * @throws \Throwable on any other failure to send — the sender reports failure honestly, it
     *   never swallows an error itself. SendSms is the one place that catches, logs, and swallows.
     */
    public function send(string $toPhone, string $message): string;
}
