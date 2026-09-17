<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Conversation;

/**
 * The one place the STOP/UNSUBSCRIBE/CANCEL/END/QUIT keyword list is defined — a real regulatory
 * expectation for SMS in particular (carriers themselves auto-enforce a subset of these on
 * long-code/toll-free numbers, see Model\Sms\TwilioSmsSender's OPTED_OUT_ERROR_CODE docblock),
 * and this module treats it the same way for WhatsApp even though Meta itself has no equivalent
 * auto-enforcement.
 *
 * Matches the WHOLE trimmed body case-insensitively, not a substring search — a customer typing
 * "please stop sending me these" in normal conversation must not be silently opted out. This is
 * the same one-word-keyword convention every SMS carrier/aggregator already trains customers to
 * expect ("Reply STOP to unsubscribe").
 */
class StopKeywordDetector
{
    /**
     * @var string[]
     */
    private const array KEYWORDS = ['STOP', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT'];

    public function matches(string $body): bool
    {
        return in_array(strtoupper(trim($body)), self::KEYWORDS, true);
    }
}
