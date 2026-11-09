/**
 * Ordo Automation — anonymous visitor tracker.
 *
 * Deliberately dependency-free (no RequireJS/jQuery) so it can be dropped into any site,
 * Magento or not, as a plain <script> tag — matching the "works as a small JS snippet that
 * can be dropped into any site" goal from README → Roadmap → Phase 5.
 *
 * MVP scope, documented rather than pretended-complete: this always fires a "page_view" event
 * on load. Firing "product_view" / "category_view" with the right SKU/category id, or
 * "checkout_start" with no key, requires the theme to call window.ordoTrack(eventType, eventKey)
 * on the relevant PDP/PLP/checkout templates — there is no automatic page-type detection here,
 * since that varies by theme. "checkout_start" is what Model\ResourceModel\FunnelReport\Grid\
 * Collection's own funnel report reads for its own middle stage — see docs/adr/0031.
 *
 * Also runs this module's first-ever poll loop (see startPolling below) when the
 * "tracking > popup_enabled" config is on: a campaign's "Show Popup" action has no way to push
 * anything onto an already-open page, so the only way for "the moment a threshold is crossed, a
 * banner appears" to actually happen live is the browser periodically asking the server "is
 * there anything for me?" — built on polling rather than a websocket/SSE connection since this
 * is meant to stay a plain <script> tag with no server-push infrastructure required.
 *
 * When "tracking > gtm_bridge_enabled" is on, every track() call is also mirrored onto
 * window.dataLayer (see pushToDataLayer below) — see docs/adr/0036.
 *
 * When "tracking > exit_intent_enabled" is on, also fires an "exit_intent" tracking event (see
 * startExitIntentDetection below) once per page load — see docs/adr/0037.
 *
 * window.ordoRenderSocialProof(sku, containerSelector) is another theme integration point, same
 * shape as window.ordoTrack()/window.ordoSubscribeToPriceWatch(): the PDP template calls it with
 * the product it already knows and a container it has placed in its own markup — see
 * renderSocialProof below and docs/adr/0039.
 *
 * window.ordoRenderLowStock(sku, containerSelector) is the same shape again, for "Only N left in
 * stock" — see renderLowStock below and docs/adr/0042.
 *
 * When "tracking > email_capture_enabled" is on, also shows a newsletter-signup popup after a
 * configurable delay (see startEmailCaptureTimer/renderEmailCapture below and docs/adr/0043) -
 * unlike the three integration points above, this is a standing, store-wide feature wired
 * directly off the script tag's own data attributes, not a function the theme calls.
 */
(function () {
    'use strict';

    var COOKIE_NAME = 'ordo_visitor_id';
    var COOKIE_DAYS = 365;
    var ENDPOINT = '/ordo/track/event';
    var POLL_ENDPOINT = '/ordo/track/poll';
    var POPUP_BANNER_ID = 'ordo-popup-banner';
    var DISMISS_NOTIFICATION_ENDPOINT = '/ordo/track/dismissnotification';
    var NOTIFICATION_LIST_ID = 'ordo-notification-list';
    var SUBMIT_SURVEY_ENDPOINT = '/ordo/track/submitsurveyresponse';
    var SURVEY_PROMPT_ID = 'ordo-survey-prompt';
    var SOCIAL_PROOF_ENDPOINT = '/ordo/track/socialproof';
    var LOW_STOCK_ENDPOINT = '/ordo/track/lowstock';
    var EMAIL_CAPTURE_ENDPOINT = '/ordo/track/emailcapture';
    var EMAIL_CAPTURE_DISMISSED_COOKIE = 'ordo_email_capture_dismissed';
    var EMAIL_CAPTURE_BANNER_ID = 'ordo-email-capture-banner';

    // Captured synchronously, at the top of this script's own execution — document.currentScript
    // is only reliable for a plain, synchronously-executing <script src> tag like this one; it
    // would already be null by the time an async callback ran.
    var currentScript = document.currentScript;

    function readCookie(name) {
        var match = new RegExp('(?:^|; )' + name + '=([^;]*)').exec(document.cookie);
        return match ? decodeURIComponent(match[1]) : null;
    }

    function writeCookie(name, value, days) {
        var expires = new Date(Date.now() + days * 24 * 60 * 60 * 1000).toUTCString();
        document.cookie = name + '=' + encodeURIComponent(value) + '; expires=' + expires + '; path=/; SameSite=Lax';
    }

    function generateVisitorId() {
        if (window.crypto?.randomUUID) {
            return window.crypto.randomUUID();
        }
        // Fallback for older browsers — not cryptographically strong, doesn't need to be
        // for an anonymous, non-sensitive visitor correlation id.
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
            var r = Math.trunc(Math.random() * 16);
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

        pushToDataLayer(eventType, eventKey);
    }

    /**
     * Mirrors every track() call onto window.dataLayer too, gated by its own config toggle
     * (data-gtm-bridge-enabled — see ADR 0036) so a store's existing GTM container can build
     * GA4/Ads audiences from Ordo's own event taxonomy (segmentation tags, campaign triggers)
     * without waiting on this module's own, separately-gated ad-audience sync. Deliberately
     * doesn't load GTM itself — the store's own container script is assumed already on the page,
     * same "this is a bridge, not a GTM installer" scope ADR 0036 documents.
     */
    function pushToDataLayer(eventType, eventKey) {
        if (currentScript?.dataset.gtmBridgeEnabled !== '1') {
            return;
        }

        window.dataLayer = window.dataLayer || [];
        window.dataLayer.push({
            event: 'ordo_' + eventType,
            ordoEventKey: eventKey || null
        });
    }

    /**
     * The one close/dismiss "×" button used by every widget this file renders — same glyph and
     * style everywhere, only the accessible label and the click behavior differ per widget.
     */
    function createCloseButton(ariaLabel, onClick) {
        var close = document.createElement('button');
        close.textContent = '×';
        close.setAttribute('aria-label', ariaLabel);
        close.setAttribute(
            'style',
            'position:absolute;top:6px;right:8px;border:none;background:none;font-size:18px;' +
            'line-height:1;cursor:pointer;color:#666;'
        );
        close.onclick = onClick;
        return close;
    }

    /**
     * The single poll loop for popup, notifications and survey — these used to be three separate
     * loops, one per concern, each hitting its own endpoint on its own timer (15/20/25s). Merged
     * into one /ordo/track/poll request (Controller\Track\Poll, see ADR 0024) gated by one
     * data-poll-enabled/data-poll-interval pair instead of three, so an open tab makes one request
     * per tick instead of three.
     *
     * Visibility-aware: a tick in a hidden tab returns early instead of fetching — nobody can see
     * what it would render, and backgrounded tabs shouldn't keep hitting the server. The interval
     * itself is never cleared, so polling resumes by itself on the next tick once the tab is
     * visible again (guarded for ancient browsers with no Page Visibility API, matching this
     * file's defensive style — those just keep polling unconditionally, the pre-existing behavior).
     *
     * @param {Number} defaultIntervalSeconds used when data-poll-interval is missing/invalid
     * @param {Function} render called with the whole parsed response body each tick that returns one
     */
    function startPolling(defaultIntervalSeconds, render) {
        if (currentScript?.dataset.pollEnabled !== '1') {
            return;
        }

        var intervalSeconds = Number.parseInt(currentScript.dataset.pollInterval, 10);
        if (!intervalSeconds || intervalSeconds <= 0) {
            intervalSeconds = defaultIntervalSeconds;
        }

        function poll() {
            if (document.hidden !== undefined && document.hidden) {
                return;
            }

            var url = POLL_ENDPOINT + '?visitor_id=' + encodeURIComponent(getVisitorId());

            fetch(url, { credentials: 'same-origin' })
                .then(function (response) {
                    return response.json();
                })
                .then(function (data) {
                    if (data) {
                        render(data);
                    }
                })
                .catch(function () {});
        }

        // Poll once shortly after load (not instantly — let the page settle) rather than waiting
        // a full interval for the first check.
        setTimeout(poll, 2000);
        setInterval(poll, intervalSeconds * 1000);
    }

    /**
     * Dispatches one merged /ordo/track/poll response to each concern's own render function - a
     * concern's key is only ever present when its own feature is enabled server-side (Controller\
     * Track\Poll), and falsy (missing/null/empty array) means nothing new to show, same per-concern
     * truthy gate each standalone endpoint used to apply itself before this merge.
     */
    function renderPollResponse(data) {
        if (data.popup) {
            renderPopup(data.popup);
        }
        if (data.notifications) {
            renderNotifications(data.notifications);
        }
        if (data.survey) {
            renderSurvey(data.survey);
        }
    }

    /**
     * Minimal, dependency-free banner injection — no template engine, matching the rest of this
     * file. Only one banner shown at a time: if a poll returns a new popup while one is already
     * on screen, the old one is replaced rather than stacking.
     */
    function renderPopup(popup) {
        var existing = document.getElementById(POPUP_BANNER_ID);
        if (existing) {
            existing.remove();
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

        banner.appendChild(createCloseButton('Close', function () {
            banner.remove();
        }));

        document.body.appendChild(banner);
    }

    function getNotificationList() {
        var list = document.getElementById(NOTIFICATION_LIST_ID);
        if (!list) {
            list = document.createElement('div');
            list.id = NOTIFICATION_LIST_ID;
            list.setAttribute(
                'style',
                'position:fixed;left:16px;bottom:16px;max-width:320px;z-index:2147483000;' +
                'font-family:sans-serif;font-size:14px;line-height:1.4;display:flex;' +
                'flex-direction:column;gap:8px;'
            );
            document.body.appendChild(list);
        }
        return list;
    }

    function dismissNotification(notificationId, el) {
        var body = new URLSearchParams({
            visitor_id: getVisitorId(),
            notification_id: notificationId
        });

        // Remove from screen immediately — don't make the visitor wait on the round-trip just
        // to see their own dismiss take effect. If the request fails, the next poll simply
        // re-adds it (same fail-open-to-"still there" behavior as a missed popup poll).
        if (el.parentNode) {
            el.remove();
        }

        fetch(DISMISS_NOTIFICATION_ENDPOINT, { method: 'POST', body: body, keepalive: true })
            .catch(function () {});
    }

    /**
     * Non-modal, persistent — unlike renderPopup() above, this never replaces an existing
     * banner; it reconciles the on-screen set against the server's current unread list each
     * poll, so a notification dismissed from another tab (or expired server-side) disappears
     * here too, and one dismissed locally never reappears just because the same poll response
     * is still in flight elsewhere.
     */
    function renderNotifications(notifications) {
        var list = getNotificationList();
        var seenIds = {};

        notifications.forEach(function (notification) {
            seenIds[notification.id] = true;

            if (document.getElementById('ordo-notification-' + notification.id)) {
                return;
            }

            var card = document.createElement('div');
            card.id = 'ordo-notification-' + notification.id;
            card.setAttribute(
                'style',
                'position:relative;background:#fff;color:#1a1a1a;border:1px solid #ccc;' +
                'border-radius:6px;box-shadow:0 2px 12px rgba(0,0,0,.15);padding:16px;'
            );

            var headline = document.createElement('div');
            headline.textContent = notification.headline;
            headline.setAttribute('style', 'font-weight:bold;margin-bottom:6px;padding-right:20px;');
            card.appendChild(headline);

            if (notification.body) {
                var body = document.createElement('div');
                body.textContent = notification.body;
                body.setAttribute('style', 'margin-bottom:10px;');
                card.appendChild(body);
            }

            if (notification.cta_label && notification.cta_url && /^https?:\/\//i.test(notification.cta_url)) {
                var cta = document.createElement('a');
                cta.textContent = notification.cta_label;
                cta.href = notification.cta_url;
                cta.setAttribute(
                    'style',
                    'display:inline-block;background:#1a1a1a;color:#fff;text-decoration:none;' +
                    'padding:6px 12px;border-radius:4px;'
                );
                card.appendChild(cta);
            }

            card.appendChild(createCloseButton('Dismiss', function () {
                dismissNotification(notification.id, card);
            }));

            list.appendChild(card);
        });

        Array.prototype.slice.call(list.children).forEach(function (child) {
            var childId = child.id.replace('ordo-notification-', '');
            if (!seenIds[childId]) {
                child.remove();
            }
        });
    }

    function submitSurveyResponse(surveyId, score, el) {
        var body = new URLSearchParams({
            visitor_id: getVisitorId(),
            survey_id: surveyId,
            score: score
        });

        // Same fail-open reasoning as dismissNotification: pull the widget immediately, don't
        // make the visitor wait on the round-trip to see their own click take effect.
        if (el.parentNode) {
            el.remove();
        }

        fetch(SUBMIT_SURVEY_ENDPOINT, { method: 'POST', body: body, keepalive: true }).catch(function () {});
    }

    /**
     * One 0-10 button row — deliberately minimal, no rating widget dependency, matching the rest
     * of this file. Only one prompt shown at a time (there is realistically only ever one
     * unclaimed survey queued per visitor at once, same as popup).
     */
    function renderSurvey(survey) {
        if (document.getElementById(SURVEY_PROMPT_ID)) {
            return;
        }

        var widget = document.createElement('div');
        widget.id = SURVEY_PROMPT_ID;
        widget.setAttribute(
            'style',
            'position:fixed;right:16px;bottom:16px;max-width:340px;background:#fff;' +
            'color:#1a1a1a;border:1px solid #ccc;border-radius:6px;box-shadow:0 2px 12px rgba(0,0,0,.15);' +
            'padding:16px;z-index:2147483000;font-family:sans-serif;font-size:14px;line-height:1.4;'
        );

        var question = document.createElement('div');
        question.textContent = survey.question;
        question.setAttribute('style', 'font-weight:bold;margin-bottom:10px;padding-right:20px;');
        widget.appendChild(question);

        var buttons = document.createElement('div');
        buttons.setAttribute('style', 'display:flex;flex-wrap:wrap;gap:4px;');
        for (var score = 0; score <= 10; score++) {
            (function (score) {
                var button = document.createElement('button');
                button.textContent = String(score);
                button.setAttribute(
                    'style',
                    'min-width:26px;padding:4px 6px;border:1px solid #ccc;border-radius:4px;' +
                    'background:#f5f5f5;cursor:pointer;'
                );
                button.onclick = function () {
                    submitSurveyResponse(survey.id, score, widget);
                };
                buttons.appendChild(button);
            })(score);
        }
        widget.appendChild(buttons);

        // Closing without answering just hides it locally — the row stays delivered-unanswered
        // server-side and is swept up later by Cron\PruneSurveyPrompts, same as an ignored popup
        // is never re-shown.
        widget.appendChild(createCloseButton('Close', function () {
            widget.remove();
        }));

        document.body.appendChild(widget);
    }

    function urlBase64ToUint8Array(base64Url) {
        var padding = '='.repeat((4 - (base64Url.length % 4)) % 4);
        var base64 = (base64Url + padding).replaceAll('-', '+').replaceAll('_', '/');
        var rawData = window.atob(base64);
        var outputArray = new Uint8Array(rawData.length);
        for (var i = 0; i < rawData.length; i++) {
            outputArray[i] = rawData.codePointAt(i);
        }
        return outputArray;
    }

    function arrayBufferToBase64Url(buffer) {
        var bytes = new Uint8Array(buffer);
        var binary = '';
        for (var i = 0; i < bytes.byteLength; i++) {
            binary += String.fromCodePoint(bytes[i]);
        }
        var base64 = window.btoa(binary).replaceAll('+', '-').replaceAll('/', '_');
        while (base64.endsWith('=')) {
            base64 = base64.slice(0, -1);
        }
        return base64;
    }

    function registerPushSubscription(subscription) {
        var body = new URLSearchParams({
            endpoint: subscription.endpoint,
            p256dh: arrayBufferToBase64Url(subscription.getKey('p256dh')),
            auth: arrayBufferToBase64Url(subscription.getKey('auth'))
        });

        fetch('/ordo/track/registerpushsubscription', {
            method: 'POST',
            credentials: 'same-origin',
            body: body,
            keepalive: true
        }).catch(function () {});
    }

    /**
     * Only ever subscribes on an explicit, separate user gesture (e.g. a theme's own "Enable
     * notifications" button calling window.ordoSubscribeToPush()) - never auto-prompts on page
     * load. An unsolicited native permission prompt the moment a visitor lands on the page is
     * both a bad first impression and, on Chrome, a well-known way to get a site's own prompt
     * quietly auto-blocked by the browser's own spam heuristics.
     */
    function subscribeToPush() {
        if (currentScript?.dataset.pushEnabled !== '1') {
            return Promise.reject(new Error('Push notifications are disabled.'));
        }
        if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
            return Promise.reject(new Error('Push notifications are not supported in this browser.'));
        }

        var vapidPublicKey = currentScript.dataset.pushVapidPublicKey;

        return navigator.serviceWorker.register('/ordo/track/pushserviceworker', { scope: '/' })
            .then(function () {
                // pushManager.subscribe() requires an ACTIVE worker, not just a registered one -
                // register()'s own returned registration can still be "installing" at this point
                // (a real, reproducible race, not theoretical - Chrome throws "no active Service
                // Worker" if subscribe() is called too early). navigator.serviceWorker.ready only
                // resolves once a worker actually reaches the active state.
                return navigator.serviceWorker.ready;
            })
            .then(function (registration) {
                return registration.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: urlBase64ToUint8Array(vapidPublicKey)
                });
            })
            .then(function (subscription) {
                registerPushSubscription(subscription);
                return subscription;
            });
    }

    /**
     * Registers a price-drop or back-in-stock watch for a single product - called from a
     * theme's own PDP "Notify me" button. Deliberately fire-and-forget, same shape as
     * registerPushSubscription() above: the caller gets a resolved/rejected promise from the
     * fetch itself, there is no separate polling/confirmation step.
     *
     * `email` is optional and only looked at for a signed-out visitor - it's the only address the
     * backend has to notify a guest directly once the price/stock actually changes, since a guest
     * has no account for a campaign email to go to. A logged-in customer doesn't need it (their
     * account email is used instead), and an anonymous call with no email still registers the
     * watch exactly as before, it just never gets notified.
     */
    function subscribeToPriceWatch(productId, watchType, email) {
        var params = {
            product_id: String(productId),
            watch_type: watchType
        };
        if (email) {
            params.email = email;
        }
        var body = new URLSearchParams(params);

        return fetch('/ordo/track/registerpricewatch', {
            method: 'POST',
            credentials: 'same-origin',
            body: body,
            keepalive: true
        }).then(function (response) {
            return response.json();
        });
    }

    /**
     * Fires window.ordoTrack('exit_intent') the first time, per page load, the mouse leaves the
     * page entirely via the top edge - the usual "about to click the tab/back/bookmark bar"
     * signal a campaign's Exit Intent trigger (see docs/adr/0037) listens for. A plain mouseout
     * fires constantly as the cursor crosses between child elements; event.relatedTarget is null
     * only when it actually left the document, and clientY near 0 is what distinguishes "leaving
     * upward, toward the browser chrome" from leaving out any other edge (sideways/downward exits
     * are rarely "closing the tab" and would make this fire on ordinary page interaction).
     * Deliberately fires at most once per page load - repeat exits across a session are still
     * each their own fresh page load, and Model\Campaign\Action\ShowPopup's own
     * popup_frequency_cap_hours already caps how often a popup actually reaches the screen, so no
     * further dedup is needed here.
     */
    function startExitIntentDetection() {
        if (currentScript?.dataset.exitIntentEnabled !== '1') {
            return;
        }

        var fired = false;

        document.addEventListener('mouseout', function (event) {
            if (fired || event.relatedTarget !== null || event.clientY > 0) {
                return;
            }
            fired = true;
            track('exit_intent');
        });
    }

    /**
     * Fills `containerSelector` with "N people viewed this today" for `sku`, once, on demand -
     * called by the theme's own PDP template, which already knows both. The container starts
     * hidden in the theme's own markup and is only revealed once a real message comes back, so a
     * disabled feature, a below-minimum view count, or a failed request all leave the page
     * exactly as the theme rendered it - never an empty or stale-looking box. The message text
     * itself is server-formatted (Controller\Track\SocialProof, from Helper\Config::
     * getSocialProofMessageTemplate()) - this function has no template/count logic of its own.
     *
     * @param {String} sku
     * @param {String} containerSelector e.g. "#ordo-social-proof"
     */
    function renderSocialProof(sku, containerSelector) {
        if (currentScript?.dataset.socialProofEnabled !== '1') {
            return;
        }

        var container = document.querySelector(containerSelector);
        if (!container || !sku) {
            return;
        }

        fetch(SOCIAL_PROOF_ENDPOINT + '?sku=' + encodeURIComponent(sku), { credentials: 'same-origin' })
            .then(function (response) {
                return response.json();
            })
            .then(function (data) {
                if (data?.message) {
                    container.textContent = data.message;
                    container.style.display = '';
                }
            })
            .catch(function () {});
    }

    function renderLowStock(sku, containerSelector) {
        if (currentScript?.dataset.lowStockEnabled !== '1') {
            return;
        }

        var container = document.querySelector(containerSelector);
        if (!container || !sku) {
            return;
        }

        fetch(LOW_STOCK_ENDPOINT + '?sku=' + encodeURIComponent(sku), { credentials: 'same-origin' })
            .then(function (response) {
                return response.json();
            })
            .then(function (data) {
                if (data?.message) {
                    container.textContent = data.message;
                    container.style.display = '';
                }
            })
            .catch(function () {});
    }

    /**
     * Shows a small popup after a configurable delay, inviting an anonymous visitor to sign up
     * for the store's newsletter — see ADR 0043. Unlike the other widgets above, this is a
     * standing, store-wide feature (no theme integration call needed) rather than a per-SKU one,
     * so it's wired directly off the script tag's own data attributes rather than exposed as a
     * window.ordoRender*() function.
     *
     * Never shown twice: a 365-day cookie is set the moment a visitor either submits or
     * dismisses it, checked both before starting the timer and again right before rendering (a
     * visitor could submit/dismiss in one tab while this timer is still pending in another).
     */
    function startEmailCaptureTimer() {
        if (currentScript?.dataset.emailCaptureEnabled !== '1') {
            return;
        }
        if (readCookie(EMAIL_CAPTURE_DISMISSED_COOKIE)) {
            return;
        }

        var delaySeconds = Number.parseInt(currentScript.dataset.emailCaptureDelay, 10);
        if (!delaySeconds || delaySeconds < 0) {
            delaySeconds = 8;
        }

        setTimeout(renderEmailCapture, delaySeconds * 1000);
    }

    function dismissEmailCapture(banner) {
        writeCookie(EMAIL_CAPTURE_DISMISSED_COOKIE, '1', 365);
        banner.remove();
    }

    function renderEmailCapture() {
        if (readCookie(EMAIL_CAPTURE_DISMISSED_COOKIE) || document.getElementById(EMAIL_CAPTURE_BANNER_ID)) {
            return;
        }

        var banner = document.createElement('div');
        banner.id = EMAIL_CAPTURE_BANNER_ID;
        banner.setAttribute(
            'style',
            'position:fixed;right:16px;bottom:16px;max-width:320px;background:#fff;' +
            'color:#1a1a1a;border:1px solid #ccc;border-radius:6px;box-shadow:0 2px 12px rgba(0,0,0,.15);' +
            'padding:16px;z-index:2147483000;font-family:sans-serif;font-size:14px;line-height:1.4;'
        );

        var headline = document.createElement('div');
        headline.textContent = currentScript.dataset.emailCaptureHeadline || '';
        headline.setAttribute('style', 'font-weight:bold;margin-bottom:6px;padding-right:20px;');
        banner.appendChild(headline);

        var bodyText = currentScript.dataset.emailCaptureBody;
        if (bodyText) {
            var body = document.createElement('div');
            body.textContent = bodyText;
            body.setAttribute('style', 'margin-bottom:10px;');
            banner.appendChild(body);
        }

        var form = document.createElement('form');

        var input = document.createElement('input');
        input.type = 'email';
        input.required = true;
        input.placeholder = 'you@example.com';
        input.setAttribute(
            'style',
            'width:100%;padding:6px;margin-bottom:8px;box-sizing:border-box;border:1px solid #ccc;border-radius:4px;'
        );
        form.appendChild(input);

        var message = document.createElement('div');
        message.setAttribute('style', 'margin-bottom:8px;font-size:12px;color:#b00020;');
        form.appendChild(message);

        var submit = document.createElement('button');
        submit.type = 'submit';
        submit.textContent = currentScript.dataset.emailCaptureCtaLabel || 'Subscribe';
        submit.setAttribute(
            'style',
            'background:#1a1a1a;color:#fff;border:none;padding:6px 12px;border-radius:4px;cursor:pointer;'
        );
        form.appendChild(submit);

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            message.textContent = '';

            var body = new URLSearchParams({ visitor_id: getVisitorId(), email: input.value });
            fetch(EMAIL_CAPTURE_ENDPOINT, { method: 'POST', body: body, credentials: 'same-origin' })
                .then(function (response) {
                    return response.json();
                })
                .then(function (data) {
                    if (data?.ok) {
                        dismissEmailCapture(banner);
                    } else {
                        message.textContent = 'Please enter a valid email address.';
                    }
                })
                .catch(function () {});
        });

        banner.appendChild(form);
        banner.appendChild(createCloseButton('Close', function () {
            dismissEmailCapture(banner);
        }));

        document.body.appendChild(banner);
    }

    window.ordoTrack = track;
    window.ordoSubscribeToPush = subscribeToPush;
    window.ordoSubscribeToPriceWatch = subscribeToPriceWatch;
    window.ordoRenderSocialProof = renderSocialProof;
    window.ordoRenderLowStock = renderLowStock;
    track('page_view');
    startPolling(15, renderPollResponse);
    startExitIntentDetection();
    startEmailCaptureTimer();
})();
