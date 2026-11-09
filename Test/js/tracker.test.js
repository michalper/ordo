'use strict';

const QUnit = require('qunit');
const path = require('path');
const { createDomEnv } = require('./support/dom-env');

const MODULE_PATH = 'view/frontend/web/js/tracker.js';
const ABSOLUTE_PATH = path.join(__dirname, '..', '..', MODULE_PATH);

/**
 * tracker.js is neither an AMD module (amd-shim) nor a service worker (sw-env): it's a plain
 * IIFE that runs everything as a side effect of being loaded as a <script> tag, reading its
 * configuration off document.currentScript synchronously. So loading it under Node means:
 * a fresh jsdom document (dom-env, same as the AMD tests), a stand-in <script> element pinned
 * onto document.currentScript BEFORE requiring the file, a recording fetch stub, and recording
 * setTimeout/setInterval stubs (restored immediately after the require — the IIFE registers its
 * timers synchronously at load, so nothing later needs them stubbed and QUnit gets the real
 * ones back).
 *
 * @param {Object} [scriptAttrs] data-* attributes for the stand-in <script> tag
 * @param {Object} [options]
 * @param {Boolean} [options.noRandomUUID] shadows window.crypto.randomUUID with undefined before
 *   the script loads, for testing generateVisitorId()'s older-browser fallback path - a plain
 *   `delete` doesn't work here, randomUUID lives on Crypto.prototype in jsdom, not as an own
 *   property, so `delete instance.randomUUID` is a silent no-op (confirmed directly)
 * @param {Object} [options.pushSubscription] jsdom has neither navigator.serviceWorker nor
 *   window.PushManager at all - stubs both, with pushManager.subscribe() resolving to this object
 *   (needs endpoint + a getKey(name) returning an ArrayBuffer-ish value), for testing
 *   subscribeToPush()'s real happy path
 * @param {String} [options.preCookie] raw "name=value" set on document.cookie before the script
 *   loads, for testing a guard that reads a cookie synchronously at load time (e.g.
 *   startEmailCaptureTimer()'s dismissed-on-a-previous-page-load check) - setting it only after
 *   loadTracker() returns would be too late, that code already ran once as a load-time side effect
 * @return {Object} dom, recorded fetch calls/timers, and a setter for the next poll response
 */
function loadTracker(scriptAttrs, options) {
    const { dom } = createDomEnv();
    // jsdom silently no-ops document.cookie writes on the default about:blank origin - the
    // email-capture tests need a real one so the "dismissed" cookie actually persists.
    dom.reconfigure({ url: 'https://example.test/' });
    const document = dom.window.document;

    if (options?.preCookie) {
        document.cookie = options.preCookie;
    }

    // Node 21+ ships its own built-in global `navigator` (a Node-specific object, unrelated to
    // jsdom's `dom.window.navigator`) - a bare `navigator` reference inside tracker.js resolves
    // to THAT (global.navigator is a getter-only accessor, can't be reassigned wholesale -
    // confirmed directly), so the push stub below must be set as a property on global.navigator,
    // not on dom.window.navigator. It isn't swapped out fresh per test the way dom.window is, so
    // it's cleared unconditionally first to avoid leaking a stub into a later, unrelated test.
    delete global.navigator.serviceWorker;

    if (options?.noRandomUUID) {
        dom.window.crypto.randomUUID = undefined;
    }

    if (options?.pushSubscription) {
        dom.window.PushManager = function () {};
        global.navigator.serviceWorker = {
            register: function () {
                return Promise.resolve({});
            },
            ready: Promise.resolve({
                pushManager: {
                    subscribe: function () {
                        return Promise.resolve(options.pushSubscription);
                    }
                }
            })
        };
    }

    const script = document.createElement('script');
    Object.keys(scriptAttrs || {}).forEach(function (name) {
        script.setAttribute(name, scriptAttrs[name]);
    });
    // jsdom defines currentScript as a prototype getter (null outside real script execution);
    // an own property on the instance shadows it, same trick as `hidden` in setHidden() below.
    Object.defineProperty(document, 'currentScript', { value: script, configurable: true });

    // jsdom's own document.hidden defaults to TRUE (visibilityState "prerender"), which would
    // make the visibility-aware poll skip every tick — pin it to a visible tab by default and
    // let individual tests flip it with setHidden().
    setHidden(document, false);

    const fetchCalls = [];
    let pollResponse = {};
    global.fetch = function (url, options) {
        fetchCalls.push({ url: url, options: options });
        return Promise.resolve({
            json: function () {
                return Promise.resolve(pollResponse);
            }
        });
    };

    const timeouts = [];
    const intervals = [];
    const realSetTimeout = global.setTimeout;
    const realSetInterval = global.setInterval;
    global.setTimeout = function (fn, ms) {
        timeouts.push({ fn: fn, ms: ms });
        return 0;
    };
    global.setInterval = function (fn, ms) {
        intervals.push({ fn: fn, ms: ms });
        return 0;
    };

    try {
        delete require.cache[require.resolve(ABSOLUTE_PATH)];
        require(ABSOLUTE_PATH);
    } finally {
        global.setTimeout = realSetTimeout;
        global.setInterval = realSetInterval;
    }

    return {
        dom: dom,
        fetchCalls: fetchCalls,
        timeouts: timeouts,
        intervals: intervals,
        setPollResponse: function (response) {
            pollResponse = response;
        }
    };
}

/**
 * Shadows jsdom's prototype-level `document.hidden` getter with an own value, the way a real
 * browser flips it when the tab goes to the background.
 */
function setHidden(document, hidden) {
    Object.defineProperty(document, 'hidden', { value: hidden, configurable: true });
}

/** Lets the poll's whole fetch → json() → render promise chain settle. */
function flush() {
    return new Promise(function (resolve) {
        setImmediate(resolve);
    });
}

QUnit.module('Ordo_Automation/js/tracker (polling)', function () {
    QUnit.test('loading the script fires a page_view tracking event', function (assert) {
        const env = loadTracker();

        assert.strictEqual(env.fetchCalls.length, 1);
        assert.strictEqual(env.fetchCalls[0].url, '/ordo/track/event');
        assert.strictEqual(new URLSearchParams(env.fetchCalls[0].options.body).get('event_type'), 'page_view');
    });

    QUnit.test('no polling starts when data-poll-enabled is not "1"', function (assert) {
        const env = loadTracker();

        assert.deepEqual(env.timeouts, []);
        assert.deepEqual(env.intervals, []);
    });

    QUnit.test('polling starts at the default interval, first poll 2s after load', function (assert) {
        const env = loadTracker({ 'data-poll-enabled': '1' });

        assert.deepEqual(env.timeouts.map(function (t) { return t.ms; }), [2000]);
        assert.deepEqual(env.intervals.map(function (i) { return i.ms; }), [15000]);
    });

    QUnit.test('a data-poll-interval attribute overrides the default; an invalid one falls back', function (assert) {
        const env = loadTracker({ 'data-poll-enabled': '1', 'data-poll-interval': '7' });

        assert.deepEqual(env.intervals.map(function (i) { return i.ms; }), [7000]);

        const envZero = loadTracker({ 'data-poll-enabled': '1', 'data-poll-interval': '0' });
        assert.deepEqual(envZero.intervals.map(function (i) { return i.ms; }), [15000]);

        const envInvalid = loadTracker({ 'data-poll-enabled': '1', 'data-poll-interval': 'soon' });
        assert.deepEqual(envInvalid.intervals.map(function (i) { return i.ms; }), [15000]);
    });

    QUnit.test('one poll tick renders popup, notifications and survey from a single merged response', async function (assert) {
        const env = loadTracker({ 'data-poll-enabled': '1' });

        env.setPollResponse({
            popup: { headline: 'Hi there', body: 'A deal for you' },
            notifications: [{ id: 5, headline: 'Back in stock' }],
            survey: { id: 9, question: 'How likely…?' }
        });
        env.intervals[0].fn();
        await flush();

        assert.strictEqual(env.fetchCalls.length, 2, 'page_view + one merged poll, not three');
        const pollUrl = env.fetchCalls[1].url;
        assert.true(pollUrl.indexOf('/ordo/track/poll?visitor_id=') === 0, 'polls ' + pollUrl);
        assert.deepEqual(env.fetchCalls[1].options, { credentials: 'same-origin' });

        const banner = env.dom.window.document.getElementById('ordo-popup-banner');
        assert.true(!!banner, 'popup banner rendered');
        assert.true(banner.textContent.indexOf('Hi there') !== -1);

        const card = env.dom.window.document.getElementById('ordo-notification-5');
        assert.true(!!card, 'notification card rendered');
        assert.true(card.textContent.indexOf('Back in stock') !== -1);

        const widget = env.dom.window.document.getElementById('ordo-survey-prompt');
        assert.true(!!widget, 'survey prompt rendered');
        assert.true(widget.textContent.indexOf('How likely…?') !== -1);
    });

    QUnit.test('a poll tick whose response has no key for a concern renders nothing for it', async function (assert) {
        const env = loadTracker({ 'data-poll-enabled': '1' });

        env.setPollResponse({ notifications: [] });
        env.intervals[0].fn();
        await flush();

        assert.strictEqual(env.dom.window.document.getElementById('ordo-popup-banner'), null);
        assert.strictEqual(env.dom.window.document.getElementById('ordo-survey-prompt'), null);
    });

    QUnit.test('a poll tick in a hidden tab skips the fetch; the next visible tick fetches again', async function (assert) {
        const env = loadTracker({ 'data-poll-enabled': '1' });
        const document = env.dom.window.document;

        setHidden(document, true);
        env.intervals[0].fn();
        env.timeouts[0].fn();
        await flush();

        assert.strictEqual(env.fetchCalls.length, 1, 'only the page_view fetch — hidden ticks fetched nothing');

        setHidden(document, false);
        env.intervals[0].fn();
        await flush();

        assert.strictEqual(env.fetchCalls.length, 2, 'polling resumed once the tab was visible again');
        assert.true(env.fetchCalls[1].url.indexOf('/ordo/track/poll?visitor_id=') === 0);
    });

    QUnit.test('a tick still fetches when the browser has no Page Visibility API at all', async function (assert) {
        const env = loadTracker({ 'data-poll-enabled': '1' });

        // typeof document.hidden === 'undefined' — the pre-visibility-API browser case.
        setHidden(env.dom.window.document, undefined);
        env.intervals[0].fn();
        await flush();

        assert.strictEqual(env.fetchCalls.length, 2, 'polled unconditionally, the pre-existing behavior');
    });

    QUnit.test('the popup close button removes the banner', async function (assert) {
        const env = loadTracker({ 'data-poll-enabled': '1' });
        const document = env.dom.window.document;

        env.setPollResponse({ popup: { headline: 'Hi there' } });
        env.intervals[0].fn();
        await flush();

        const close = document.getElementById('ordo-popup-banner').querySelector('button[aria-label="Close"]');
        assert.true(!!close, 'close button rendered');
        assert.strictEqual(close.textContent, '×');

        close.onclick();
        assert.strictEqual(document.getElementById('ordo-popup-banner'), null);
    });

    QUnit.test('a popup with a cta_label/cta_url renders a link button with the right href and text', async function (assert) {
        const env = loadTracker({ 'data-poll-enabled': '1' });
        const document = env.dom.window.document;

        env.setPollResponse({
            popup: { headline: 'Sale', body: 'Ends soon', cta_label: 'Shop now', cta_url: 'https://example.test/sale' }
        });
        env.intervals[0].fn();
        await flush();

        const banner = document.getElementById('ordo-popup-banner');
        const cta = banner.querySelector('a');

        assert.true(!!cta, 'cta link rendered');
        assert.strictEqual(cta.textContent, 'Shop now');
        assert.strictEqual(cta.getAttribute('href'), 'https://example.test/sale');
        assert.true(banner.textContent.indexOf('Ends soon') !== -1, 'optional body rendered too');
    });

    QUnit.test('a popup cta_url that is not http(s) is not rendered as a link', async function (assert) {
        const env = loadTracker({ 'data-poll-enabled': '1' });
        const document = env.dom.window.document;

        env.setPollResponse({
            popup: { headline: 'Sale', cta_label: 'Shop now', cta_url: 'javascript:alert(1)' }
        });
        env.intervals[0].fn();
        await flush();

        assert.strictEqual(document.getElementById('ordo-popup-banner').querySelector('a'), null);
    });

    QUnit.test('the notification dismiss button removes the card and reports the dismissal', async function (assert) {
        const env = loadTracker({ 'data-poll-enabled': '1' });
        const document = env.dom.window.document;

        env.setPollResponse({ notifications: [{ id: 5, headline: 'Back in stock' }] });
        env.intervals[0].fn();
        await flush();

        const close = document.getElementById('ordo-notification-5').querySelector('button[aria-label="Dismiss"]');
        assert.true(!!close, 'dismiss button rendered');

        close.onclick();
        assert.strictEqual(document.getElementById('ordo-notification-5'), null);
        const dismissCall = env.fetchCalls[env.fetchCalls.length - 1];
        assert.strictEqual(dismissCall.url, '/ordo/track/dismissnotification');
        assert.strictEqual(new URLSearchParams(dismissCall.options.body).get('notification_id'), '5');
    });

    QUnit.test('a notification already on screen is left alone, not rebuilt, on a later tick that still includes it', async function (assert) {
        const env = loadTracker({ 'data-poll-enabled': '1' });
        const document = env.dom.window.document;

        env.setPollResponse({ notifications: [{ id: 5, headline: 'Back in stock' }] });
        env.intervals[0].fn();
        await flush();

        const firstCard = document.getElementById('ordo-notification-5');

        env.setPollResponse({ notifications: [{ id: 5, headline: 'Back in stock' }] });
        env.intervals[0].fn();
        await flush();

        assert.strictEqual(document.querySelectorAll('#ordo-notification-5').length, 1, 'still only one card');
        assert.strictEqual(document.getElementById('ordo-notification-5'), firstCard, 'same element, not rebuilt');
    });

    QUnit.test('a notification with a body and a cta_label/cta_url renders both', async function (assert) {
        const env = loadTracker({ 'data-poll-enabled': '1' });
        const document = env.dom.window.document;

        env.setPollResponse({
            notifications: [{
                id: 5,
                headline: 'Back in stock',
                body: 'Only a few left',
                cta_label: 'View product',
                cta_url: 'https://example.test/product'
            }]
        });
        env.intervals[0].fn();
        await flush();

        const card = document.getElementById('ordo-notification-5');
        const cta = card.querySelector('a');

        assert.true(card.textContent.indexOf('Only a few left') !== -1, 'body rendered');
        assert.true(!!cta, 'cta link rendered');
        assert.strictEqual(cta.textContent, 'View product');
        assert.strictEqual(cta.getAttribute('href'), 'https://example.test/product');
    });

    QUnit.test('a notification cta_url that is not http(s) is not rendered as a link', async function (assert) {
        const env = loadTracker({ 'data-poll-enabled': '1' });
        const document = env.dom.window.document;

        env.setPollResponse({
            notifications: [{ id: 5, headline: 'Back in stock', cta_label: 'View', cta_url: 'javascript:alert(1)' }]
        });
        env.intervals[0].fn();
        await flush();

        assert.strictEqual(document.getElementById('ordo-notification-5').querySelector('a'), null);
    });

    QUnit.test('a survey already on screen is left alone on a later tick instead of being rebuilt', async function (assert) {
        const env = loadTracker({ 'data-poll-enabled': '1' });
        const document = env.dom.window.document;

        env.setPollResponse({ survey: { id: 9, question: 'How likely…?' } });
        env.intervals[0].fn();
        await flush();

        const firstPrompt = document.getElementById('ordo-survey-prompt');

        env.setPollResponse({ survey: { id: 9, question: 'How likely…?' } });
        env.intervals[0].fn();
        await flush();

        assert.strictEqual(document.querySelectorAll('#ordo-survey-prompt').length, 1, 'still only one prompt');
        assert.strictEqual(document.getElementById('ordo-survey-prompt'), firstPrompt, 'same element, not rebuilt');
    });

    QUnit.test('the survey close button hides the prompt without submitting a response', async function (assert) {
        const env = loadTracker({ 'data-poll-enabled': '1' });
        const document = env.dom.window.document;

        env.setPollResponse({ survey: { id: 9, question: 'How likely…?' } });
        env.intervals[0].fn();
        await flush();

        const callsBefore = env.fetchCalls.length;
        const close = document.getElementById('ordo-survey-prompt').querySelector('button[aria-label="Close"]');
        assert.true(!!close, 'close button rendered');

        close.onclick();
        assert.strictEqual(document.getElementById('ordo-survey-prompt'), null);
        assert.strictEqual(env.fetchCalls.length, callsBefore, 'no submit fired on a plain close');
    });

    QUnit.test('a second popup while one is showing replaces it, not stacks on top', async function (assert) {
        const env = loadTracker({ 'data-poll-enabled': '1' });
        const document = env.dom.window.document;

        env.setPollResponse({ popup: { headline: 'First' } });
        env.intervals[0].fn();
        await flush();
        assert.true(document.getElementById('ordo-popup-banner').textContent.indexOf('First') !== -1);

        env.setPollResponse({ popup: { headline: 'Second' } });
        env.intervals[0].fn();
        await flush();

        assert.strictEqual(document.querySelectorAll('#ordo-popup-banner').length, 1, 'still only one banner');
        assert.true(document.getElementById('ordo-popup-banner').textContent.indexOf('Second') !== -1);
        assert.true(document.getElementById('ordo-popup-banner').textContent.indexOf('First') === -1, 'old one gone');
    });

    QUnit.test('a notification no longer in the poll response is removed on the next tick', async function (assert) {
        const env = loadTracker({ 'data-poll-enabled': '1' });
        const document = env.dom.window.document;

        env.setPollResponse({ notifications: [{ id: 5, headline: 'Back in stock' }] });
        env.intervals[0].fn();
        await flush();
        assert.true(!!document.getElementById('ordo-notification-5'), 'rendered on the first tick');

        env.setPollResponse({ notifications: [] });
        env.intervals[0].fn();
        await flush();

        assert.strictEqual(document.getElementById('ordo-notification-5'), null, 'removed once absent from the server list');
    });

    QUnit.test('clicking a survey score button submits the response and removes the prompt', async function (assert) {
        const env = loadTracker({ 'data-poll-enabled': '1' });
        const document = env.dom.window.document;

        env.setPollResponse({ survey: { id: 9, question: 'How likely…?' } });
        env.intervals[0].fn();
        await flush();

        const widget = document.getElementById('ordo-survey-prompt');
        const scoreButton = Array.prototype.find.call(
            widget.querySelectorAll('button'),
            function (button) { return button.textContent === '7'; }
        );
        assert.true(!!scoreButton, 'score button 7 rendered');

        scoreButton.onclick();

        assert.strictEqual(document.getElementById('ordo-survey-prompt'), null, 'removed immediately');
        const submitCall = env.fetchCalls[env.fetchCalls.length - 1];
        assert.strictEqual(submitCall.url, '/ordo/track/submitsurveyresponse');
        assert.strictEqual(new URLSearchParams(submitCall.options.body).get('survey_id'), '9');
        assert.strictEqual(new URLSearchParams(submitCall.options.body).get('score'), '7');
    });
});

QUnit.module('Ordo_Automation/js/tracker (visitor id fallback)', function () {
    QUnit.test('generates a UUID-shaped id even without window.crypto.randomUUID', function (assert) {
        const env = loadTracker({}, { noRandomUUID: true });

        // The first track() call (the automatic page_view on load) reads/creates the cookie.
        const match = /ordo_visitor_id=([^;]+)/.exec(env.dom.window.document.cookie);
        assert.true(!!match, 'visitor id cookie was set');
        assert.true(
            /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(decodeURIComponent(match[1])),
            'looks like a real v4 UUID: ' + match[1]
        );
    });
});

QUnit.module('Ordo_Automation/js/tracker (push subscription)', function () {
    const fakeSubscription = {
        endpoint: 'https://push.example.test/abc123',
        getKey: function (name) {
            // Real values, not both-zero/empty - exercises the real base64url byte -> char path
            // (urlBase64ToUint8Array/arrayBufferToBase64Url) rather than an edge case.
            const bytes = name === 'p256dh' ? [4, 18, 52, 86, 120, 154, 188, 222] : [1, 2, 3, 4, 5];
            return new Uint8Array(bytes).buffer;
        }
    };

    QUnit.test('rejects when data-push-enabled is not "1"', async function (assert) {
        const env = loadTracker({}, { pushSubscription: fakeSubscription });

        try {
            await env.dom.window.ordoSubscribeToPush();
            assert.true(false, 'should have rejected');
        } catch (e) {
            assert.strictEqual(e.message, 'Push notifications are disabled.');
        }
    });

    QUnit.test('rejects when the browser has no serviceWorker/PushManager support', async function (assert) {
        const env = loadTracker({ 'data-push-enabled': '1' });

        try {
            await env.dom.window.ordoSubscribeToPush();
            assert.true(false, 'should have rejected');
        } catch (e) {
            assert.strictEqual(e.message, 'Push notifications are not supported in this browser.');
        }
    });

    QUnit.test('registers, waits for an active worker, subscribes, and reports the real subscription', async function (assert) {
        const env = loadTracker(
            { 'data-push-enabled': '1', 'data-push-vapid-public-key': 'BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB' },
            { pushSubscription: fakeSubscription }
        );

        const result = await env.dom.window.ordoSubscribeToPush();

        assert.strictEqual(result, fakeSubscription, 'resolves with the real subscription object');

        const registerCall = env.fetchCalls[env.fetchCalls.length - 1];
        assert.strictEqual(registerCall.url, '/ordo/track/registerpushsubscription');
        const body = new URLSearchParams(registerCall.options.body);
        assert.strictEqual(body.get('endpoint'), 'https://push.example.test/abc123');
        // Base64url: no '+', '/' or '=' padding.
        assert.true(/^[A-Za-z0-9_-]+$/.test(body.get('p256dh')), 'p256dh is base64url: ' + body.get('p256dh'));
        assert.true(/^[A-Za-z0-9_-]+$/.test(body.get('auth')), 'auth is base64url: ' + body.get('auth'));
    });
});

QUnit.module('Ordo_Automation/js/tracker (price watch)', function () {
    QUnit.test('posts product_id/watch_type and resolves with the parsed JSON response', async function (assert) {
        const env = loadTracker();

        env.setPollResponse({ watch_id: 42 });
        const result = await env.dom.window.ordoSubscribeToPriceWatch(123, 'price_drop');

        const call = env.fetchCalls[env.fetchCalls.length - 1];
        assert.strictEqual(call.url, '/ordo/track/registerpricewatch');
        const body = new URLSearchParams(call.options.body);
        assert.strictEqual(body.get('product_id'), '123');
        assert.strictEqual(body.get('watch_type'), 'price_drop');
        assert.strictEqual(body.has('email'), false, 'no email param when none is given');
        assert.deepEqual(result, { watch_id: 42 });
    });

    QUnit.test('includes the email param for a signed-out visitor who gave one', async function (assert) {
        const env = loadTracker();

        env.setPollResponse({ watch_id: 42 });
        await env.dom.window.ordoSubscribeToPriceWatch(123, 'back_in_stock', 'jan@example.test');

        const call = env.fetchCalls[env.fetchCalls.length - 1];
        const body = new URLSearchParams(call.options.body);
        assert.strictEqual(body.get('watch_type'), 'back_in_stock');
        assert.strictEqual(body.get('email'), 'jan@example.test');
    });
});

QUnit.module('Ordo_Automation/js/tracker (GTM bridge)', function () {
    QUnit.test('does not touch window.dataLayer when data-gtm-bridge-enabled is not "1"', function (assert) {
        const env = loadTracker();

        assert.strictEqual(env.dom.window.dataLayer, undefined);
    });

    QUnit.test('the automatic page_view on load is mirrored to dataLayer when enabled', function (assert) {
        const env = loadTracker({ 'data-gtm-bridge-enabled': '1' });

        assert.deepEqual(env.dom.window.dataLayer, [{ event: 'ordo_page_view', ordoEventKey: null }]);
    });

    QUnit.test('a later window.ordoTrack() call with an event key is appended, not replacing earlier pushes', function (assert) {
        const env = loadTracker({ 'data-gtm-bridge-enabled': '1' });

        env.dom.window.ordoTrack('product_view', '24-MB01');

        assert.deepEqual(env.dom.window.dataLayer, [
            { event: 'ordo_page_view', ordoEventKey: null },
            { event: 'ordo_product_view', ordoEventKey: '24-MB01' }
        ]);
    });

    QUnit.test('reuses the same dataLayer array across calls instead of replacing it', function (assert) {
        const env = loadTracker({ 'data-gtm-bridge-enabled': '1' });
        const afterLoad = env.dom.window.dataLayer;

        env.dom.window.ordoTrack('checkout_start');

        assert.strictEqual(env.dom.window.dataLayer, afterLoad, 'same array instance, not replaced');
        assert.deepEqual(env.dom.window.dataLayer, [
            { event: 'ordo_page_view', ordoEventKey: null },
            { event: 'ordo_checkout_start', ordoEventKey: null }
        ]);
    });
});

QUnit.module('Ordo_Automation/js/tracker (exit intent)', function () {
    function dispatchMouseOut(document, attrs) {
        const event = new document.defaultView.MouseEvent('mouseout', { bubbles: true, cancelable: true });
        Object.keys(attrs).forEach(function (name) {
            Object.defineProperty(event, name, { value: attrs[name] });
        });
        document.dispatchEvent(event);
    }

    QUnit.test('does not attach a listener when data-exit-intent-enabled is not "1"', function (assert) {
        const env = loadTracker();
        const document = env.dom.window.document;
        const callsBefore = env.fetchCalls.length;

        dispatchMouseOut(document, { relatedTarget: null, clientY: 0 });

        assert.strictEqual(env.fetchCalls.length, callsBefore, 'no exit_intent event tracked');
    });

    QUnit.test('mouse leaving upward with no relatedTarget fires exit_intent once', function (assert) {
        const env = loadTracker({ 'data-exit-intent-enabled': '1' });
        const document = env.dom.window.document;
        const callsBefore = env.fetchCalls.length;

        dispatchMouseOut(document, { relatedTarget: null, clientY: 0 });

        assert.strictEqual(env.fetchCalls.length, callsBefore + 1);
        const call = env.fetchCalls[env.fetchCalls.length - 1];
        assert.strictEqual(call.url, '/ordo/track/event');
        assert.strictEqual(new URLSearchParams(call.options.body).get('event_type'), 'exit_intent');

        // A second exit upward in the same page load is not tracked again.
        dispatchMouseOut(document, { relatedTarget: null, clientY: 0 });
        assert.strictEqual(env.fetchCalls.length, callsBefore + 1, 'fires at most once per page load');
    });

    QUnit.test('a mouseout with a relatedTarget (moved onto a child element) is ignored', function (assert) {
        const env = loadTracker({ 'data-exit-intent-enabled': '1' });
        const document = env.dom.window.document;
        const callsBefore = env.fetchCalls.length;

        dispatchMouseOut(document, { relatedTarget: document.body, clientY: 0 });

        assert.strictEqual(env.fetchCalls.length, callsBefore, 'still inside the document, not an exit');
    });

    QUnit.test('a mouseout leaving downward (positive clientY) is ignored', function (assert) {
        const env = loadTracker({ 'data-exit-intent-enabled': '1' });
        const document = env.dom.window.document;
        const callsBefore = env.fetchCalls.length;

        dispatchMouseOut(document, { relatedTarget: null, clientY: 650 });

        assert.strictEqual(env.fetchCalls.length, callsBefore, 'exiting downward is not exit intent');
    });
});

QUnit.module('Ordo_Automation/js/tracker (social proof)', function () {
    function makeContainer(document, id) {
        const container = document.createElement('div');
        container.id = id;
        container.style.display = 'none';
        document.body.appendChild(container);
        return container;
    }

    QUnit.test('does nothing when data-social-proof-enabled is not "1"', async function (assert) {
        const env = loadTracker();
        const document = env.dom.window.document;
        const container = makeContainer(document, 'ordo-social-proof');
        const callsBefore = env.fetchCalls.length;

        env.dom.window.ordoRenderSocialProof('24-MB01', '#ordo-social-proof');
        await flush();

        assert.strictEqual(env.fetchCalls.length, callsBefore, 'no request made');
        assert.strictEqual(container.style.display, 'none', 'container left untouched');
    });

    QUnit.test('does nothing when the container selector matches nothing', async function (assert) {
        const env = loadTracker({ 'data-social-proof-enabled': '1' });
        const callsBefore = env.fetchCalls.length;

        env.dom.window.ordoRenderSocialProof('24-MB01', '#does-not-exist');
        await flush();

        assert.strictEqual(env.fetchCalls.length, callsBefore, 'no request made');
    });

    QUnit.test('does nothing when no sku is given', async function (assert) {
        const env = loadTracker({ 'data-social-proof-enabled': '1' });
        const callsBefore = env.fetchCalls.length;

        env.dom.window.ordoRenderSocialProof('', '#ordo-social-proof');
        await flush();

        assert.strictEqual(env.fetchCalls.length, callsBefore, 'no request made');
    });

    QUnit.test('fills and reveals the container when the server returns a message', async function (assert) {
        const env = loadTracker({ 'data-social-proof-enabled': '1' });
        const document = env.dom.window.document;
        const container = makeContainer(document, 'ordo-social-proof');

        env.setPollResponse({ message: '12 people viewed this today' });
        env.dom.window.ordoRenderSocialProof('24-MB01', '#ordo-social-proof');
        await flush();

        const call = env.fetchCalls[env.fetchCalls.length - 1];
        assert.strictEqual(call.url, '/ordo/track/socialproof?sku=24-MB01');
        assert.deepEqual(call.options, { credentials: 'same-origin' });
        assert.strictEqual(container.textContent, '12 people viewed this today');
        assert.strictEqual(container.style.display, '');
    });

    QUnit.test('leaves the container hidden when the server returns no message', async function (assert) {
        const env = loadTracker({ 'data-social-proof-enabled': '1' });
        const document = env.dom.window.document;
        const container = makeContainer(document, 'ordo-social-proof');

        env.setPollResponse({ message: null });
        env.dom.window.ordoRenderSocialProof('24-MB01', '#ordo-social-proof');
        await flush();

        assert.strictEqual(container.textContent, '');
        assert.strictEqual(container.style.display, 'none');
    });
});

QUnit.module('Ordo_Automation/js/tracker (low stock)', function () {
    function makeContainer(document, id) {
        const container = document.createElement('div');
        container.id = id;
        container.style.display = 'none';
        document.body.appendChild(container);
        return container;
    }

    QUnit.test('does nothing when data-low-stock-enabled is not "1"', async function (assert) {
        const env = loadTracker();
        const document = env.dom.window.document;
        const container = makeContainer(document, 'ordo-low-stock');
        const callsBefore = env.fetchCalls.length;

        env.dom.window.ordoRenderLowStock('24-MB01', '#ordo-low-stock');
        await flush();

        assert.strictEqual(env.fetchCalls.length, callsBefore, 'no request made');
        assert.strictEqual(container.style.display, 'none', 'container left untouched');
    });

    QUnit.test('does nothing when the container selector matches nothing', async function (assert) {
        const env = loadTracker({ 'data-low-stock-enabled': '1' });
        const callsBefore = env.fetchCalls.length;

        env.dom.window.ordoRenderLowStock('24-MB01', '#does-not-exist');
        await flush();

        assert.strictEqual(env.fetchCalls.length, callsBefore, 'no request made');
    });

    QUnit.test('does nothing when no sku is given', async function (assert) {
        const env = loadTracker({ 'data-low-stock-enabled': '1' });
        const callsBefore = env.fetchCalls.length;

        env.dom.window.ordoRenderLowStock('', '#ordo-low-stock');
        await flush();

        assert.strictEqual(env.fetchCalls.length, callsBefore, 'no request made');
    });

    QUnit.test('fills and reveals the container when the server returns a message', async function (assert) {
        const env = loadTracker({ 'data-low-stock-enabled': '1' });
        const document = env.dom.window.document;
        const container = makeContainer(document, 'ordo-low-stock');

        env.setPollResponse({ message: 'Only 3 left in stock' });
        env.dom.window.ordoRenderLowStock('24-MB01', '#ordo-low-stock');
        await flush();

        const call = env.fetchCalls[env.fetchCalls.length - 1];
        assert.strictEqual(call.url, '/ordo/track/lowstock?sku=24-MB01');
        assert.deepEqual(call.options, { credentials: 'same-origin' });
        assert.strictEqual(container.textContent, 'Only 3 left in stock');
        assert.strictEqual(container.style.display, '');
    });

    QUnit.test('leaves the container hidden when the server returns no message', async function (assert) {
        const env = loadTracker({ 'data-low-stock-enabled': '1' });
        const document = env.dom.window.document;
        const container = makeContainer(document, 'ordo-low-stock');

        env.setPollResponse({ message: null });
        env.dom.window.ordoRenderLowStock('24-MB01', '#ordo-low-stock');
        await flush();

        assert.strictEqual(container.textContent, '');
        assert.strictEqual(container.style.display, 'none');
    });
});

QUnit.module('Ordo_Automation/js/tracker (email capture)', function () {
    function submitForm(document, email) {
        const banner = document.getElementById('ordo-email-capture-banner');
        const input = banner.querySelector('input[type="email"]');
        input.value = email;

        const form = banner.querySelector('form');
        const event = new document.defaultView.Event('submit', { bubbles: true, cancelable: true });
        form.dispatchEvent(event);
    }

    QUnit.test('does not register a timer when data-email-capture-enabled is not "1"', function (assert) {
        const env = loadTracker();

        assert.strictEqual(env.timeouts.length, 0);
    });

    QUnit.test('registers a timer using the configured delay when enabled', function (assert) {
        const env = loadTracker({ 'data-email-capture-enabled': '1', 'data-email-capture-delay': '15' });

        assert.strictEqual(env.timeouts.length, 1);
        assert.strictEqual(env.timeouts[0].ms, 15000);
    });

    QUnit.test('falls back to 8 seconds when the delay attribute is missing or invalid', function (assert) {
        const env = loadTracker({ 'data-email-capture-enabled': '1' });

        assert.strictEqual(env.timeouts[0].ms, 8000);
    });

    QUnit.test('registers no timer at all when the dismissed cookie is already set from a previous page load', function (assert) {
        const env = loadTracker(
            { 'data-email-capture-enabled': '1' },
            { preCookie: 'ordo_email_capture_dismissed=1' }
        );

        assert.strictEqual(env.timeouts.length, 0);
    });

    QUnit.test('renders nothing when the timer fires after the visitor already dismissed it', function (assert) {
        const env = loadTracker({ 'data-email-capture-enabled': '1' });
        const document = env.dom.window.document;

        env.timeouts[0].fn();
        document.getElementById('ordo-email-capture-banner').querySelector('button[aria-label="Close"]').click();

        // Same page load, timer firing a second time (e.g. a stray duplicate call) must not
        // re-render once the visitor has already dismissed it.
        env.timeouts[0].fn();

        assert.notOk(document.getElementById('ordo-email-capture-banner'));
    });

    QUnit.test('renders the popup with the configured copy when the timer fires', function (assert) {
        const env = loadTracker({
            'data-email-capture-enabled': '1',
            'data-email-capture-headline': 'Get 10% off',
            'data-email-capture-body': 'Sign up now',
            'data-email-capture-cta-label': 'Join'
        });
        const document = env.dom.window.document;

        env.timeouts[0].fn();

        const banner = document.getElementById('ordo-email-capture-banner');
        assert.ok(banner, 'banner rendered');
        assert.ok(banner.textContent.indexOf('Get 10% off') !== -1);
        assert.ok(banner.textContent.indexOf('Sign up now') !== -1);
        assert.strictEqual(banner.querySelector('button[type="submit"]').textContent, 'Join');
    });

    QUnit.test('does not render twice if the timer somehow fires again', function (assert) {
        const env = loadTracker({ 'data-email-capture-enabled': '1' });
        const document = env.dom.window.document;

        env.timeouts[0].fn();
        env.timeouts[0].fn();

        assert.strictEqual(document.querySelectorAll('#ordo-email-capture-banner').length, 1);
    });

    QUnit.test('submitting a valid email posts it and removes the banner', async function (assert) {
        const env = loadTracker({ 'data-email-capture-enabled': '1' });
        const document = env.dom.window.document;

        env.timeouts[0].fn();
        env.setPollResponse({ ok: true });
        submitForm(document, 'jane@example.com');
        await flush();

        const call = env.fetchCalls[env.fetchCalls.length - 1];
        assert.strictEqual(call.url, '/ordo/track/emailcapture');
        assert.strictEqual(call.options.credentials, 'same-origin');
        assert.strictEqual(new URLSearchParams(call.options.body).get('email'), 'jane@example.com');
        assert.notOk(document.getElementById('ordo-email-capture-banner'), 'banner removed on success');
        assert.ok(document.cookie.indexOf('ordo_email_capture_dismissed=1') !== -1, 'dismissed cookie set');
    });

    QUnit.test('shows an error and keeps the banner when the server rejects the email', async function (assert) {
        const env = loadTracker({ 'data-email-capture-enabled': '1' });
        const document = env.dom.window.document;

        env.timeouts[0].fn();
        env.setPollResponse({ ok: false, reason: 'invalid_payload' });
        submitForm(document, 'not-an-email');
        await flush();

        const banner = document.getElementById('ordo-email-capture-banner');
        assert.ok(banner, 'banner stays');
        assert.ok(banner.textContent.indexOf('valid email') !== -1);
    });

    QUnit.test('the close button dismisses without submitting', function (assert) {
        const env = loadTracker({ 'data-email-capture-enabled': '1' });
        const document = env.dom.window.document;

        env.timeouts[0].fn();
        const callsBefore = env.fetchCalls.length;
        document.getElementById('ordo-email-capture-banner').querySelector('button[aria-label="Close"]').click();

        assert.strictEqual(env.fetchCalls.length, callsBefore, 'no request made');
        assert.notOk(document.getElementById('ordo-email-capture-banner'));
        assert.ok(document.cookie.indexOf('ordo_email_capture_dismissed=1') !== -1, 'dismissed cookie set');
    });
});
