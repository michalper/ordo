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
 *
 * Also runs this module's first-ever poll loop (see startPopupPolling below) when the
 * "tracking > popup_enabled" config is on: a campaign's "Show Popup" action has no way to push
 * anything onto an already-open page, so the only way for "the moment a threshold is crossed, a
 * banner appears" to actually happen live is the browser periodically asking the server "is
 * there anything for me?" — built on polling rather than a websocket/SSE connection since this
 * is meant to stay a plain <script> tag with no server-push infrastructure required.
 */
(function () {
    'use strict';

    var COOKIE_NAME = 'ordo_visitor_id';
    var COOKIE_DAYS = 365;
    var ENDPOINT = '/ordo/track/event';
    var POPUP_ENDPOINT = '/ordo/track/popup';
    var POPUP_BANNER_ID = 'ordo-popup-banner';

    // Captured synchronously, at the top of this script's own execution — document.currentScript
    // is only reliable for a plain, synchronously-executing <script src> tag like this one; it
    // would already be null by the time an async callback ran.
    var currentScript = document.currentScript;

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

    /**
     * Minimal, dependency-free banner injection — no template engine, matching the rest of this
     * file. Only one banner shown at a time: if a poll returns a new popup while one is already
     * on screen, the old one is replaced rather than stacking.
     */
    function renderPopup(popup) {
        var existing = document.getElementById(POPUP_BANNER_ID);
        if (existing) {
            existing.parentNode.removeChild(existing);
        }

        var banner = document.createElement('div');
        banner.id = POPUP_BANNER_ID;
        banner.setAttribute(
            'style',
            'position:fixed;right:16px;bottom:16px;max-width:320px;background:#fff;' +
            'color:#1a1a1a;border:1px solid #ccc;border-radius:6px;box-shadow:0 2px 12px rgba(0,0,0,.15);' +
            'padding:16px;z-index:2147483000;font-family:sans-serif;font-size:14px;line-height:1.4;'
        );

        var headline = document.createElement('div');
        headline.textContent = popup.headline;
        headline.setAttribute('style', 'font-weight:bold;margin-bottom:6px;padding-right:20px;');
        banner.appendChild(headline);

        if (popup.body) {
            var body = document.createElement('div');
            body.textContent = popup.body;
            body.setAttribute('style', 'margin-bottom:10px;');
            banner.appendChild(body);
        }

        if (popup.cta_label && popup.cta_url && /^https?:\/\//i.test(popup.cta_url)) {
            var cta = document.createElement('a');
            cta.textContent = popup.cta_label;
            cta.href = popup.cta_url;
            cta.setAttribute(
                'style',
                'display:inline-block;background:#1a1a1a;color:#fff;text-decoration:none;' +
                'padding:6px 12px;border-radius:4px;'
            );
            banner.appendChild(cta);
        }

        var close = document.createElement('button');
        close.textContent = '×';
        close.setAttribute('aria-label', 'Close');
        close.setAttribute(
            'style',
            'position:absolute;top:6px;right:8px;border:none;background:none;font-size:18px;' +
            'line-height:1;cursor:pointer;color:#666;'
        );
        close.onclick = function () {
            banner.parentNode.removeChild(banner);
        };
        banner.appendChild(close);

        document.body.appendChild(banner);
    }

    function pollForPopup() {
        var url = POPUP_ENDPOINT + '?visitor_id=' + encodeURIComponent(getVisitorId());

        fetch(url, { credentials: 'same-origin' })
            .then(function (response) {
                return response.json();
            })
            .then(function (data) {
                if (data && data.popup) {
                    renderPopup(data.popup);
                }
            })
            .catch(function () {});
    }

    function startPopupPolling() {
        if (!currentScript || currentScript.getAttribute('data-popup-enabled') !== '1') {
            return;
        }

        var intervalSeconds = parseInt(currentScript.getAttribute('data-popup-poll-interval'), 10);
        if (!intervalSeconds || intervalSeconds <= 0) {
            intervalSeconds = 15;
        }

        // Poll once shortly after load (not instantly — let the page settle) rather than waiting
        // a full interval for the first check.
        setTimeout(pollForPopup, 2000);
        setInterval(pollForPopup, intervalSeconds * 1000);
    }

    window.ordoTrack = track;
    track('page_view');
    startPopupPolling();
})();
