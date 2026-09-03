<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Sms;

/**
 * The recipient has opted out (Twilio error 21610 — the number is on Twilio's own auto-managed
 * STOP/opt-out block list; see https://www.twilio.com/docs/messaging/features/opt-out-management).
 * A distinct, expected, routine outcome — not a delivery failure — so SendSms logs and records it
 * differently from a generic SmsSenderInterface::send() failure.
 */
class OptedOutException extends \RuntimeException
{
}
