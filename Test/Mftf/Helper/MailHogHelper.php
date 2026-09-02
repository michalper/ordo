<?php

declare(strict_types=1);

namespace Ordo\Automation\Test\Mftf\Helper;

use Magento\FunctionalTestingFramework\Helper\Helper;

/**
 * Reads the order-approval token out of a real mailbox via MailHog's REST API, so the
 * approve/reject MFTF scenario can click through exactly the same link an admin would receive
 * by email — Observer/HoldOrderForApproval.php only ever delivers that token by email, there is
 * no admin grid or other UI surface exposing it (see ROADMAP.md's Phase 6 "still missing" note).
 * Only used by MFTF, wired into mftf.yml's MailHog service container — never loaded in a real
 * store request.
 */
class MailHogHelper extends Helper
{
    /**
     * Fetches the most recent message from MailHog and returns the href of the first link
     * whose visible text matches $linkText (e.g. "Approve" or "Reject").
     */
    public function grabLinkFromLatestEmail(string $linkText, string $mailhogUrl = 'http://127.0.0.1:8025'): string
    {
        $response = @file_get_contents($mailhogUrl . '/api/v2/messages?limit=1');
        if ($response === false) {
            throw new \RuntimeException("Could not reach MailHog at {$mailhogUrl}");
        }

        $data = json_decode($response, true);
        $item = $data['items'][0] ?? null;
        if ($item === null) {
            throw new \RuntimeException('MailHog has no messages.');
        }

        $body = (string) ($item['Content']['Body'] ?? '');
        $encoding = $item['Content']['Headers']['Content-Transfer-Encoding'][0] ?? '';
        if ($encoding === 'quoted-printable') {
            $body = quoted_printable_decode($body);
        } elseif ($encoding === 'base64') {
            $body = (string) base64_decode($body, true);
        }

        $pattern = '/<a[^>]+href="([^"]+)"[^>]*>(?:(?!<\/a>).)*?' . preg_quote($linkText, '/') . '(?:(?!<\/a>).)*?<\/a>/is';
        if (!preg_match($pattern, $body, $matches)) {
            throw new \RuntimeException("Link \"{$linkText}\" not found in the latest MailHog message.");
        }

        return html_entity_decode($matches[1]);
    }
}
