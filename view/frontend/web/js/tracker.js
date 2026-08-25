/**
 * Ordo Automation — anonymous visitor tracker.
 *
 * Deliberately dependency-free (no RequireJS/jQuery) so it can be dropped into any site,
 * Magento or not, as a plain <script> tag — matching the "works as a small JS snippet that
 * can be dropped into any site" goal from README → Roadmap → Phase 5.
 *
 * MVP scope, documented rather than pretended-complete: this always fires a "page_view" event
 * on load. Firing "product_view" / "category_view" with the right SKU/category id requires the
 * theme to call window.ordoTrack(eventType, eventKey) on PDP/PLP templates — there is no
 * automatic page-type detection here, since that varies by theme.
 */
(function () {
    'use strict';

    var COOKIE_NAME = 'ordo_visitor_id';
    var COOKIE_DAYS = 365;
    var ENDPOINT = '/ordo/track/event';

    function readCookie(name) {
        var match = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
        return match ? decodeURIComponent(match[1]) : null;
    }

    function writeCookie(name, value, days) {
        var expires = new Date(Date.now() + days * 24 * 60 * 60 * 1000).toUTCString();
        document.cookie = name + '=' + encodeURIComponent(value) + '; expires=' + expires + '; path=/; SameSite=Lax';
    }

    function generateVisitorId() {
        if (window.crypto && window.crypto.randomUUID) {
            return window.crypto.randomUUID();
        }
        // Fallback for older browsers — not cryptographically strong, doesn't need to be
        // for an anonymous, non-sensitive visitor correlation id.
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
            var r = (Math.random() * 16) | 0;
            var v = c === 'x' ? r : (r & 0x3) | 0x8;
            return v.toString(16);
        });
    }

    function getVisitorId() {
        var visitorId = readCookie(COOKIE_NAME);
        if (!visitorId) {
            visitorId = generateVisitorId();
            writeCookie(COOKIE_NAME, visitorId, COOKIE_DAYS);
        }
        return visitorId;
    }

    function track(eventType, eventKey) {
        var body = new URLSearchParams({
            visitor_id: getVisitorId(),
            event_type: eventType
        });
        if (eventKey) {
            body.append('event_key', eventKey);
        }

        // fire-and-forget — a tracking call should never block or break the page it's on.
        fetch(ENDPOINT, { method: 'POST', body: body, keepalive: true }).catch(function () {});
    }

    window.ordoTrack = track;
    track('page_view');
})();
