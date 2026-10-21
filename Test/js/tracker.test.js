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
 * @return {Object} dom, recorded fetch calls/timers, and a setter for the next poll response
 */
function loadTracker(scriptAttrs) {
    const { dom } = createDomEnv();
    const document = dom.window.document;

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

    QUnit.test('no polling starts when no feature is enabled on the script tag', function (assert) {
        const env = loadTracker();

        assert.deepEqual(env.timeouts, []);
        assert.deepEqual(env.intervals, []);
    });

    QUnit.test('each enabled feature polls at its own default interval, first poll 2s after load', function (assert) {
        const env = loadTracker({
            'data-popup-enabled': '1',
            'data-notification-enabled': '1',
            'data-nps-survey-enabled': '1'
        });

        assert.deepEqual(env.timeouts.map(function (t) { return t.ms; }), [2000, 2000, 2000]);
        assert.deepEqual(env.intervals.map(function (i) { return i.ms; }), [15000, 20000, 25000]);
    });

    QUnit.test('a data-*-poll-interval attribute overrides the default; an invalid one falls back', function (assert) {
        const env = loadTracker({
            'data-popup-enabled': '1',
            'data-popup-poll-interval': '7',
            'data-notification-enabled': '1',
            'data-notification-poll-interval': '0',
            'data-nps-survey-enabled': '1',
            'data-nps-survey-poll-interval': 'soon'
        });

        assert.deepEqual(env.intervals.map(function (i) { return i.ms; }), [7000, 20000, 25000]);
    });

    QUnit.test('a feature whose enabled attribute is not "1" does not poll even with others enabled', function (assert) {
        const env = loadTracker({
            'data-popup-enabled': '0',
            'data-notification-enabled': '1'
        });

        assert.strictEqual(env.intervals.length, 1);
        assert.strictEqual(env.intervals[0].ms, 20000);
    });

    QUnit.test('a popup poll tick asks the popup endpoint with the visitor id and renders the banner', async function (assert) {
        const env = loadTracker({ 'data-popup-enabled': '1' });

        env.setPollResponse({ popup: { headline: 'Hi there', body: 'A deal for you' } });
        env.intervals[0].fn();
        await flush();

        assert.strictEqual(env.fetchCalls.length, 2, 'page_view + one poll');
        const pollUrl = env.fetchCalls[1].url;
        assert.true(pollUrl.indexOf('/ordo/track/popup?visitor_id=') === 0, 'polls ' + pollUrl);
        assert.deepEqual(env.fetchCalls[1].options, { credentials: 'same-origin' });

        const banner = env.dom.window.document.getElementById('ordo-popup-banner');
        assert.true(!!banner, 'banner rendered');
        assert.true(banner.textContent.indexOf('Hi there') !== -1);
    });

    QUnit.test('a poll tick whose response has no payload under its key renders nothing', async function (assert) {
        const env = loadTracker({ 'data-popup-enabled': '1' });

        env.setPollResponse({ somethingElse: true });
        env.intervals[0].fn();
        await flush();

        assert.strictEqual(env.dom.window.document.getElementById('ordo-popup-banner'), null);
    });

    QUnit.test('a notification poll tick renders cards from the notifications key', async function (assert) {
        const env = loadTracker({ 'data-notification-enabled': '1' });

        env.setPollResponse({ notifications: [{ id: 5, headline: 'Back in stock' }] });
        env.intervals[0].fn();
        await flush();

        assert.true(env.fetchCalls[1].url.indexOf('/ordo/track/notification?visitor_id=') === 0);
        const card = env.dom.window.document.getElementById('ordo-notification-5');
        assert.true(!!card, 'card rendered');
        assert.true(card.textContent.indexOf('Back in stock') !== -1);
    });

    QUnit.test('a survey poll tick renders the prompt from the survey key', async function (assert) {
        const env = loadTracker({ 'data-nps-survey-enabled': '1' });

        env.setPollResponse({ survey: { id: 9, question: 'How likely…?' } });
        env.intervals[0].fn();
        await flush();

        assert.true(env.fetchCalls[1].url.indexOf('/ordo/track/survey?visitor_id=') === 0);
        const widget = env.dom.window.document.getElementById('ordo-survey-prompt');
        assert.true(!!widget, 'prompt rendered');
        assert.true(widget.textContent.indexOf('How likely…?') !== -1);
    });

    QUnit.test('a poll tick in a hidden tab skips the fetch; the next visible tick fetches again', async function (assert) {
        const env = loadTracker({ 'data-popup-enabled': '1' });
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
        assert.true(env.fetchCalls[1].url.indexOf('/ordo/track/popup?visitor_id=') === 0);
    });

    QUnit.test('a tick still fetches when the browser has no Page Visibility API at all', async function (assert) {
        const env = loadTracker({ 'data-popup-enabled': '1' });

        // typeof document.hidden === 'undefined' — the pre-visibility-API browser case.
        setHidden(env.dom.window.document, undefined);
        env.intervals[0].fn();
        await flush();

        assert.strictEqual(env.fetchCalls.length, 2, 'polled unconditionally, the pre-existing behavior');
    });

    QUnit.test('the popup close button removes the banner', async function (assert) {
        const env = loadTracker({ 'data-popup-enabled': '1' });
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

    QUnit.test('the notification dismiss button removes the card and reports the dismissal', async function (assert) {
        const env = loadTracker({ 'data-notification-enabled': '1' });
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

    QUnit.test('the survey close button hides the prompt without submitting a response', async function (assert) {
        const env = loadTracker({ 'data-nps-survey-enabled': '1' });
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
});
