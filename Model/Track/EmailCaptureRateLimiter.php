<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Track;

use Ordo\Automation\Model\RateLimit\CacheWindowCounter;

/**
 * Same bespoke cache-backed attempt counter shape as Model\Approval\ApprovalRateLimiter, for
 * Controller\Track\EmailCapture - a public, unauthenticated, CSRF-exempt endpoint (same trust
 * model as Controller\Track\Event) that subscribes an arbitrary submitted email address and
 * triggers Magento's own newsletter confirmation send. Without this, a script could repeatedly
 * POST any victim's email address to flood their inbox with confirmation emails ("newsletter
 * bombing") - or, on a store that doesn't require confirmation, sign up arbitrary addresses
 * outright - with no throttling at all.
 *
 * Keyed by IP alone, not IP+email the way ApprovalRateLimiter keys by token+IP: there's no
 * per-request secret here to combine with the IP (the email itself is attacker-chosen, not a
 * credential), so limiting the request RATE from a given source is the only lever - this still
 * stops a single script from bombing many different victims, or one victim repeatedly, from one
 * IP.
 */
class EmailCaptureRateLimiter
{
    private const int MAX_ATTEMPTS = 10;

    private const int WINDOW_SECONDS = 900;

    private const string CACHE_TAG = 'ORDO_EMAIL_CAPTURE_RATE_LIMIT';

    public function __construct(
        private readonly CacheWindowCounter $counter
    ) {
    }

    /**
     * @return bool true if this submission is allowed (and now counted), false if this IP has
     *   already hit MAX_ATTEMPTS within the current WINDOW_SECONDS window.
     */
    public function isAllowed(string $ip): bool
    {
        return $this->counter->hit(
            $this->cacheKey($ip),
            self::MAX_ATTEMPTS,
            self::WINDOW_SECONDS,
            self::CACHE_TAG
        );
    }

    private function cacheKey(string $ip): string
    {
        return 'ordo_email_capture_rl_' . hash('sha256', $ip);
    }
}
