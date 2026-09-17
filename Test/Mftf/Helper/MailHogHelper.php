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
     * @return array<int, array<string, mixed>>
     */
    private function fetchMessages(string $toAddress, int $limit, string $mailhogUrl): array
    {
        $endpoint = $toAddress === ''
            ? $mailhogUrl . '/api/v2/messages?limit=' . $limit
            : $mailhogUrl . '/api/v2/search?kind=to&query=' . rawurlencode($toAddress) . '&limit=' . $limit;

        $response = @file_get_contents($endpoint);
        if ($response === false) {
            throw new \RuntimeException("Could not reach MailHog at {$mailhogUrl}");
        }

        $data = json_decode($response, true);

        return $data['items'] ?? [];
    }

    private function decodeBody(array $item): string
    {
        $body = (string) ($item['Content']['Body'] ?? '');
        $encoding = $item['Content']['Headers']['Content-Transfer-Encoding'][0] ?? '';
        if ($encoding === 'quoted-printable') {
            $body = quoted_printable_decode($body);
        } elseif ($encoding === 'base64') {
            $body = (string) base64_decode($body, true);
        }

        return $body;
    }

    /**
     * Fetches the most recent message from MailHog and returns the href of the first link
     * whose visible text matches $linkText (e.g. "Approve" or "Reject").
     *
     * $toAddress narrows to the most recent message sent *to* that address specifically —
     * needed because a real checkout sends more than one email per order (the customer's own
     * order-confirmation email included), and Observer/HoldOrderForApproval.php's
     * approval-request email to the admin is not reliably the last one MailHog receives.
     * Confirmed via a real CI run: plain "most recent message overall" grabbed the customer's
     * order-confirmation email instead, which has no "Approve" link at all.
     */
    public function grabLinkFromLatestEmail(
        string $linkText,
        string $toAddress = '',
        string $mailhogUrl = 'http://127.0.0.1:8025'
    ): string {
        $item = $this->fetchMessages($toAddress, 1, $mailhogUrl)[0] ?? null;
        if ($item === null) {
            throw new \RuntimeException('MailHog has no messages.');
        }

        $body = $this->decodeBody($item);

        $pattern = '/<a[^>]+href="([^"]+)"[^>]*>(?:(?!<\/a>).)*?' . preg_quote($linkText, '/') . '(?:(?!<\/a>).)*?<\/a>/is';
        if (!preg_match($pattern, $body, $matches)) {
            throw new \RuntimeException("Link \"{$linkText}\" not found in the latest MailHog message.");
        }

        return html_entity_decode($matches[1]);
    }

    /**
     * Asserts $expectedText appears in the decoded body of the most recent message sent to
     * $toAddress — same MailHog lookup/decoding as grabLinkFromLatestEmail(), for campaigns'
     * send_email action, which (unlike the order-approval email) has no link to click through,
     * just template-rendered text ({{var customer_name}}, {{var message}}, ...) to verify.
     *
     * Polls for up to $timeoutSeconds instead of a single fetch — confirmed via real CI runs
     * (RFM condition tests: recency/monetary/order-frequency) that a single fixed <wait> before
     * this check is not reliable: sales_order_place_after's publish() commits asynchronously
     * relative to the checkout redirect the test's own action group waits for, and how long that
     * takes to become visible to queue:consumers:start varies with runner load, not a constant.
     * A caller that has JUST placed the order and drained the queue can still race this, so this
     * itself retries rather than asking every call site to guess a large-enough fixed delay.
     */
    public function seeTextInLatestEmail(
        string $expectedText,
        string $toAddress,
        string $mailhogUrl = 'http://127.0.0.1:8025',
        int $timeoutSeconds = 20
    ): void {
        $deadline = microtime(true) + $timeoutSeconds;
        $lastBody = null;
        $sawAnyMessage = false;

        do {
            $item = $this->fetchMessages($toAddress, 1, $mailhogUrl)[0] ?? null;
            if ($item !== null) {
                $sawAnyMessage = true;
                $lastBody = $this->decodeBody($item);
                if (str_contains($lastBody, $expectedText)) {
                    return;
                }
            }
            usleep(500000);
        } while (microtime(true) < $deadline);

        if (!$sawAnyMessage) {
            throw new \RuntimeException("MailHog has no messages sent to \"{$toAddress}\".");
        }

        throw new \RuntimeException(
            "Text \"{$expectedText}\" not found in the latest MailHog message sent to \"{$toAddress}\" "
            . "after waiting {$timeoutSeconds}s."
        );
    }

    /**
     * Same assertion as seeTextInLatestEmail(), but scans the last $limit messages sent to
     * $toAddress instead of only the very latest one — needed when a single cron tick (or
     * request) can legitimately send more than one email to the same address in an order this
     * test doesn't control (e.g. Cron\SendAbandonedCartReminders sends its own fixed reminder,
     * then immediately dispatches a "cart_abandoned" campaign that can itself send another
     * email - only the second is "latest").
     */
    public function seeTextInAnyRecentEmail(
        string $expectedText,
        string $toAddress,
        int $limit = 5,
        string $mailhogUrl = 'http://127.0.0.1:8025'
    ): void {
        $items = $this->fetchMessages($toAddress, $limit, $mailhogUrl);
        if ($items === []) {
            throw new \RuntimeException("MailHog has no messages sent to \"{$toAddress}\".");
        }

        foreach ($items as $item) {
            if (str_contains($this->decodeBody($item), $expectedText)) {
                return;
            }
        }

        throw new \RuntimeException(
            "Text \"{$expectedText}\" not found in any of the last {$limit} MailHog messages sent to \"{$toAddress}\"."
        );
    }

    /**
     * Negative counterpart to seeTextInAnyRecentEmail() — AdminGdprConsentAndErasureTest's own
     * proof that an explicit email opt-out actually skipped SendEmail's real template render,
     * not just a coincidental absence: the real storefront order-confirmation email still goes
     * to $toAddress (so a bare "no email at all" check would be wrong), only this specific
     * campaign message text must be missing from all of them.
     */
    public function assertTextNotInAnyRecentEmail(
        string $expectedText,
        string $toAddress,
        int $limit = 5,
        string $mailhogUrl = 'http://127.0.0.1:8025'
    ): void {
        $items = $this->fetchMessages($toAddress, $limit, $mailhogUrl);

        foreach ($items as $item) {
            if (str_contains($this->decodeBody($item), $expectedText)) {
                throw new \RuntimeException(
                    "Text \"{$expectedText}\" unexpectedly found in a MailHog message sent to \"{$toAddress}\"."
                );
            }
        }
    }
}
