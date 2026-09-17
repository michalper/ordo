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
    var NOTIFICATION_ENDPOINT = '/ordo/track/notification';
    var DISMISS_NOTIFICATION_ENDPOINT = '/ordo/track/dismissnotification';
    var NOTIFICATION_LIST_ID = 'ordo-notification-list';
    var SURVEY_ENDPOINT = '/ordo/track/survey';
    var SUBMIT_SURVEY_ENDPOINT = '/ordo/track/submitsurveyresponse';
    var SURVEY_PROMPT_ID = 'ordo-survey-prompt';

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
            el.parentNode.removeChild(el);
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

            var close = document.createElement('button');
            close.textContent = '×';
            close.setAttribute('aria-label', 'Dismiss');
            close.setAttribute(
                'style',
                'position:absolute;top:6px;right:8px;border:none;background:none;font-size:18px;' +
                'line-height:1;cursor:pointer;color:#666;'
            );
            close.onclick = function () {
                dismissNotification(notification.id, card);
            };
            card.appendChild(close);

            list.appendChild(card);
        });

        Array.prototype.slice.call(list.children).forEach(function (child) {
            var childId = child.id.replace('ordo-notification-', '');
            if (!seenIds[childId]) {
                child.parentNode.removeChild(child);
            }
        });
    }

    function pollForNotifications() {
        var url = NOTIFICATION_ENDPOINT + '?visitor_id=' + encodeURIComponent(getVisitorId());

        fetch(url, { credentials: 'same-origin' })
            .then(function (response) {
                return response.json();
            })
            .then(function (data) {
                if (data && data.notifications) {
                    renderNotifications(data.notifications);
                }
            })
            .catch(function () {});
    }

    function startNotificationPolling() {
        if (!currentScript || currentScript.getAttribute('data-notification-enabled') !== '1') {
            return;
        }

        var intervalSeconds = parseInt(currentScript.getAttribute('data-notification-poll-interval'), 10);
        if (!intervalSeconds || intervalSeconds <= 0) {
            intervalSeconds = 20;
        }

        setTimeout(pollForNotifications, 2000);
        setInterval(pollForNotifications, intervalSeconds * 1000);
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
            el.parentNode.removeChild(el);
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

        var close = document.createElement('button');
        close.textContent = '×';
        close.setAttribute('aria-label', 'Close');
        close.setAttribute(
            'style',
            'position:absolute;top:6px;right:8px;border:none;background:none;font-size:18px;' +
            'line-height:1;cursor:pointer;color:#666;'
        );
        // Closing without answering just hides it locally — the row stays delivered-unanswered
        // server-side and is swept up later by Cron\PruneSurveyPrompts, same as an ignored popup
        // is never re-shown.
        close.onclick = function () {
            widget.parentNode.removeChild(widget);
        };
        widget.appendChild(close);

        document.body.appendChild(widget);
    }

    function pollForSurvey() {
        var url = SURVEY_ENDPOINT + '?visitor_id=' + encodeURIComponent(getVisitorId());

        fetch(url, { credentials: 'same-origin' })
            .then(function (response) {
                return response.json();
            })
            .then(function (data) {
                if (data && data.survey) {
                    renderSurvey(data.survey);
                }
            })
            .catch(function () {});
    }

    function startSurveyPolling() {
        if (!currentScript || currentScript.getAttribute('data-nps-survey-enabled') !== '1') {
            return;
        }

        var intervalSeconds = parseInt(currentScript.getAttribute('data-nps-survey-poll-interval'), 10);
        if (!intervalSeconds || intervalSeconds <= 0) {
            intervalSeconds = 25;
        }

        setTimeout(pollForSurvey, 2000);
        setInterval(pollForSurvey, intervalSeconds * 1000);
    }

    function urlBase64ToUint8Array(base64Url) {
        var padding = '='.repeat((4 - (base64Url.length % 4)) % 4);
        var base64 = (base64Url + padding).replace(/-/g, '+').replace(/_/g, '/');
        var rawData = window.atob(base64);
        var outputArray = new Uint8Array(rawData.length);
        for (var i = 0; i < rawData.length; i++) {
            outputArray[i] = rawData.charCodeAt(i);
        }
        return outputArray;
    }

    function arrayBufferToBase64Url(buffer) {
        var bytes = new Uint8Array(buffer);
        var binary = '';
        for (var i = 0; i < bytes.byteLength; i++) {
            binary += String.fromCharCode(bytes[i]);
        }
        return window.btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
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
        if (!currentScript || currentScript.getAttribute('data-push-enabled') !== '1') {
            return Promise.reject(new Error('Push notifications are disabled.'));
        }
        if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
            return Promise.reject(new Error('Push notifications are not supported in this browser.'));
        }

        var vapidPublicKey = currentScript.getAttribute('data-push-vapid-public-key');

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
     */
    function subscribeToPriceWatch(productId, watchType) {
        var body = new URLSearchParams({
            product_id: String(productId),
            watch_type: watchType
        });

        return fetch('/ordo/track/registerpricewatch', {
            method: 'POST',
            credentials: 'same-origin',
            body: body,
            keepalive: true
        }).then(function (response) {
            return response.json();
        });
    }

    window.ordoTrack = track;
    window.ordoSubscribeToPush = subscribeToPush;
    window.ordoSubscribeToPriceWatch = subscribeToPriceWatch;
    track('page_view');
    startPopupPolling();
    startNotificationPolling();
    startSurveyPolling();
})();
