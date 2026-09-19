'use strict';

const QUnit = require('qunit');
const { createServiceWorkerEnv, loadServiceWorker } = require('./support/sw-env');

const MODULE_PATH = 'view/frontend/web/js/push-sw.js';

function stubFetch(responseInit) {
    const calls = [];

    global.fetch = function () {
        calls.push(Array.from(arguments));

        return Promise.resolve(responseInit || { ok: true });
    };

    return calls;
}

QUnit.module('Ordo_Automation/js/push-sw', function () {
    QUnit.test('arrayBufferToBase64Url() encodes bytes as URL-safe base64 with no padding', function (assert) {
        createServiceWorkerEnv(); // self.addEventListener(...) needs a real `self` to call at load time
        const api = loadServiceWorker(MODULE_PATH);

        // 0xfb, 0xff, 0xbf -> "+/+/" in plain base64 (with trailing padding depending on length) -
        // exercises both substitutions (+/- and //_) plus padding stripping in one fixture.
        const buffer = new Uint8Array([0xfb, 0xff, 0xbf]).buffer;

        assert.strictEqual(api.arrayBufferToBase64Url(buffer), '-_-_');
    });

    QUnit.test('a push event with a valid JSON payload shows a notification with its title/body/url', async function (assert) {
        const env = createServiceWorkerEnv();

        loadServiceWorker(MODULE_PATH);

        const shown = [];

        env.registration.showNotification = function (title, options) {
            shown.push({ title: title, options: options });
            return Promise.resolve();
        };

        let waited;
        const event = {
            data: { json: function () { return { title: 'Order shipped', body: 'On its way!', url: '/orders/42' }; } },
            waitUntil: function (promise) { waited = promise; }
        };

        env.handlers.push(event);
        await waited;

        assert.deepEqual(shown, [{
            title: 'Order shipped',
            options: { body: 'On its way!', data: { url: '/orders/42' } }
        }]);
    });

    QUnit.test('a push event with no payload shows a generic notification instead of throwing', async function (assert) {
        const env = createServiceWorkerEnv();

        loadServiceWorker(MODULE_PATH);

        const shown = [];
        env.registration.showNotification = function (title, options) {
            shown.push({ title: title, options: options });
            return Promise.resolve();
        };

        let waited;
        const event = {
            data: null,
            waitUntil: function (promise) { waited = promise; }
        };

        env.handlers.push(event);
        await waited;

        assert.deepEqual(shown, [{ title: 'Notification', options: { body: '', data: { url: '/' } } }]);
    });

    QUnit.test('a push event whose payload fails to parse as JSON falls back to a generic notification', async function (assert) {
        const env = createServiceWorkerEnv();

        loadServiceWorker(MODULE_PATH);

        const shown = [];
        env.registration.showNotification = function (title, options) {
            shown.push({ title: title, options: options });
            return Promise.resolve();
        };

        let waited;
        const event = {
            data: { json: function () { throw new Error('not json'); } },
            waitUntil: function (promise) { waited = promise; }
        };

        env.handlers.push(event);
        await waited;

        assert.deepEqual(shown, [{ title: 'Notification', options: { body: '', data: { url: '/' } } }]);
    });

    QUnit.test('clicking a notification closes it and focuses an already-open matching tab', async function (assert) {
        const env = createServiceWorkerEnv();

        loadServiceWorker(MODULE_PATH);

        let focused = false;
        const matchingClient = { url: '/orders/42', focus: function () { focused = true; } };

        env.clients.matchAll = function () { return Promise.resolve([matchingClient]); };

        let closed = false;
        let waited;
        const event = {
            notification: { close: function () { closed = true; }, data: { url: '/orders/42' } },
            waitUntil: function (promise) { waited = promise; }
        };

        env.handlers.notificationclick(event);
        await waited;

        assert.true(closed);
        assert.true(focused);
    });

    QUnit.test('clicking a notification opens a new window when no open tab matches its url', async function (assert) {
        const env = createServiceWorkerEnv();

        loadServiceWorker(MODULE_PATH);

        let openedUrl = null;

        env.clients.matchAll = function () { return Promise.resolve([{ url: '/somewhere-else', focus: function () {} }]); };
        env.clients.openWindow = function (url) { openedUrl = url; return Promise.resolve(); };

        let waited;
        const event = {
            notification: { close: function () {}, data: { url: '/orders/42' } },
            waitUntil: function (promise) { waited = promise; }
        };

        env.handlers.notificationclick(event);
        await waited;

        assert.strictEqual(openedUrl, '/orders/42');
    });

    QUnit.test('clicking a notification with no open tab and no openWindow support does not throw', async function (assert) {
        const env = createServiceWorkerEnv();

        loadServiceWorker(MODULE_PATH);
        // env.clients.openWindow is intentionally left undefined here.

        let waited;
        const event = {
            notification: { close: function () {}, data: null },
            waitUntil: function (promise) { waited = promise; }
        };

        env.handlers.notificationclick(event);
        await waited;

        assert.true(true, 'resolved without throwing');
    });

    QUnit.test('a pushsubscriptionchange event with a new subscription registers it directly', async function (assert) {
        const env = createServiceWorkerEnv();

        loadServiceWorker(MODULE_PATH);

        const calls = stubFetch({ ok: true });

        let waited;
        const event = {
            newSubscription: {
                endpoint: 'https://push.example.com/subscription/new',
                getKey: function (name) { return new Uint8Array(name === 'p256dh' ? [1, 2] : [3, 4]).buffer; }
            },
            oldSubscription: null,
            waitUntil: function (promise) { waited = promise; }
        };

        env.handlers.pushsubscriptionchange(event);
        await waited;

        assert.strictEqual(calls.length, 1);
        assert.strictEqual(calls[0][0], '/ordo/track/registerpushsubscription');
        assert.strictEqual(calls[0][1].method, 'POST');

        const body = new URLSearchParams(calls[0][1].body);
        assert.strictEqual(body.get('endpoint'), 'https://push.example.com/subscription/new');
    });

    QUnit.test('a pushsubscriptionchange event with no new subscription re-subscribes using the old options', async function (assert) {
        const env = createServiceWorkerEnv();

        loadServiceWorker(MODULE_PATH);

        let capturedOptions = null;
        env.registration.pushManager.subscribe = function (options) {
            capturedOptions = options;
            return Promise.resolve({
                endpoint: 'https://push.example.com/subscription/resubscribed',
                getKey: function () { return new Uint8Array([9]).buffer; }
            });
        };
        const calls = stubFetch({ ok: true });

        let waited;
        const event = {
            newSubscription: null,
            oldSubscription: { options: { userVisibleOnly: true } },
            waitUntil: function (promise) { waited = promise; }
        };

        env.handlers.pushsubscriptionchange(event);
        await waited;

        assert.deepEqual(capturedOptions, { userVisibleOnly: true });
        assert.strictEqual(calls.length, 1);
        const body = new URLSearchParams(calls[0][1].body);
        assert.strictEqual(body.get('endpoint'), 'https://push.example.com/subscription/resubscribed');
    });

    QUnit.test('a pushsubscriptionchange event swallows a failed re-registration instead of rejecting', async function (assert) {
        const env = createServiceWorkerEnv();

        loadServiceWorker(MODULE_PATH);

        global.fetch = function () {
            return Promise.reject(new Error('network down'));
        };

        let waited;
        const event = {
            newSubscription: {
                endpoint: 'https://push.example.com/subscription/new',
                getKey: function () { return new Uint8Array([1]).buffer; }
            },
            oldSubscription: null,
            waitUntil: function (promise) { waited = promise; }
        };

        env.handlers.pushsubscriptionchange(event);
        await waited;

        assert.true(true, 'resolved without throwing despite the failed fetch');
    });
});
