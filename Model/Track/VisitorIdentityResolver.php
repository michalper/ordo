<?php
declare(strict_types=1);

namespace Ordo\Automation\Model\Track;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Stdlib\CookieManagerInterface;

/**
 * Shared identity-resolution and CSRF trust model for the public, unauthenticated
 * Controller\Track\* endpoints that tracker.js posts to (RegisterPushSubscription,
 * RegisterPriceWatch, ...). Extracted from RegisterPushSubscription — the original of the two -
 * once RegisterPriceWatch copied it verbatim, so both now share one implementation instead of
 * two copies that could silently drift apart.
 *
 * CSRF is only skipped for anonymous registrations (no session/form key exists yet, same trust
 * model as Controller\Track\Event's own harmless analytics writes). A LOGGED-IN registration is
 * checked against Origin/Referer instead, since this is a bare fetch() call with no page-rendered
 * form_key to attach — see the individual controllers for the full reasoning specific to what
 * each one persists.
 */
class VisitorIdentityResolver
{
    private const string VISITOR_ID_COOKIE = 'ordo_visitor_id';

    public function __construct(
        private readonly CustomerSession $customerSession,
        private readonly CookieManagerInterface $cookieManager
    ) {
    }

    /**
     * @return string the `ordo_visitor_id` cookie value, or '' when absent/no cookie set yet.
     */
    public function resolveVisitorId(): string
    {
        return (string) ($this->cookieManager->getCookie(self::VISITOR_ID_COOKIE) ?? '');
    }

    /**
     * True when the request carries neither a visitor cookie nor a logged-in customer session -
     * i.e. there is nothing to attach this registration to at all.
     */
    public function hasNoIdentity(string $visitorId): bool
    {
        return $visitorId === '' && !$this->customerSession->isLoggedIn();
    }

    public function resolveCustomerId(): ?int
    {
        return $this->customerSession->isLoggedIn() ? (int) $this->customerSession->getCustomerId() : null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        if (!$this->customerSession->isLoggedIn()) {
            return true;
        }

        $origin = $request->getHeader('Origin');
        // No Magento core alternative parses a URL into its component parts.
        // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged
        $originHost = is_string($origin) ? parse_url($origin, PHP_URL_HOST) : false;
        if (is_string($originHost)) {
            return $originHost === $request->getHttpHost();
        }

        // Some browsers omit Origin on a same-origin fetch() in certain configurations - fall
        // back to Referer rather than failing every logged-in browser outright.
        $referer = $request->getHeader('Referer');
        // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged
        $refererHost = is_string($referer) ? parse_url($referer, PHP_URL_HOST) : false;

        return is_string($refererHost) && $refererHost === $request->getHttpHost();
    }
}
