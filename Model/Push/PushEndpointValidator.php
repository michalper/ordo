<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Push;

/**
 * A push subscription's `endpoint` is a URL a customer's browser hands this module, and this
 * module later POSTs a real HTTP request to (Model\Push\PushSender) on that browser's own
 * schedule (whenever a campaign fires) — nothing about the Web Push protocol constrains it to
 * an actual push service. Without this check, a client could register `http://169.254.169.254/
 * latest/meta-data/` or any internal-only host as an "endpoint" and this server would dutifully
 * make that request (carrying a VAPID Authorization header) the next time a marketing campaign
 * targeted that customer — a textbook server-side request forgery. Enforced both at registration
 * time (reject bad input early) and again immediately before every send (defends against DNS
 * rebinding between the two).
 */
class PushEndpointValidator
{
    public function isAllowed(string $url): bool
    {
        return $this->resolvePinnedIp($url) !== null;
    }

    /**
     * Resolves and validates $url's host exactly as isAllowed() does, and additionally returns
     * one of its validated public IPs for the caller (PushSender) to pin the actual HTTP
     * connection to via CURLOPT_RESOLVE. Without this, this validator and the HTTP client each
     * resolve the hostname independently, and a DNS answer that changes between the two lookups
     * (DNS rebinding, TTL=0) can validate a public address here while the client's own, separate
     * resolution connects to a private one moments later - defeating this check entirely despite
     * it running "immediately before every send".
     */
    public function resolvePinnedIp(string $url): ?string
    {
        // No Magento core alternative parses a URL into its component parts.
        // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged
        $parts = parse_url($url);
        if (!isset($parts['scheme'], $parts['host']) || strtolower($parts['scheme']) !== 'https') {
            return null;
        }

        $host = $parts['host'];
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $this->isPublicIp($host) ? $host : null;
        }

        // Resolve the hostname ourselves rather than trusting it's what it looks like - every
        // resolved address must be public, not just the first one, since a hostname can legally
        // return a mix of records. A custom (not "@") error handler swallows the E_WARNING
        // dns_get_record raises for an unresolvable host, since failure is an expected,
        // explicitly-handled outcome here (rejected below), not a bug to surface.
        set_error_handler(static fn (): bool => true);
        try {
            // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged
            $records = dns_get_record($host, DNS_A | DNS_AAAA);
        } finally {
            restore_error_handler();
        }
        if ($records === false || $records === []) {
            return null;
        }

        $pinnedIp = null;
        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (!is_string($ip) || !$this->isPublicIp($ip)) {
                return null;
            }
            $pinnedIp ??= $ip;
        }

        return $pinnedIp;
    }

    /**
     * Rejects private (RFC 1918/4193), loopback, and link-local ranges - link-local in
     * particular is what blocks the classic `169.254.169.254` cloud metadata SSRF target.
     */
    private function isPublicIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }
}
