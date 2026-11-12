<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Rcs;

/**
 * One RCS delivery provider. Swappable via di.xml preference (see TwilioRcsSender) — the campaign
 * action layer (Model\Campaign\Action\SendRcs) only ever depends on this interface, never on
 * Twilio directly, same separation Model\Sms\SmsSenderInterface already established for SMS.
 */
interface RcsSenderInterface
{
    /**
     * @return string the provider's message ID (Twilio's "Sid") — SendRcs persists this into
     *   ordo_message_log, same shape as send_sms.
     * @throws \Throwable on any failure to send — the sender reports failure honestly, it never
     *   swallows an error itself. SendRcs is the one place that catches, logs, and swallows.
     */
    public function send(string $toPhone, string $message): string;
}
