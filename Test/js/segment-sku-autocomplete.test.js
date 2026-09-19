'use strict';

const QUnit = require('qunit');
const { loadModule } = require('./support/load-module');

const MODULE_PATH = 'view/adminhtml/web/js/segment-sku-autocomplete.js';

/**
 * Stubs global.fetch for one call and returns the args it was called with, so a test can assert
 * the request URL/params without a real network round trip.
 *
 * @param {Object} responseInit {ok, json: () => Promise}
 */
function stubFetch(responseInit) {
    const calls = [];

    global.fetch = function () {
        calls.push(Array.from(arguments));

        return Promise.resolve(responseInit);
    };

    return calls;
}

/**
 * Waits for one macrotask tick, by which point Node has already drained every microtask queued
 * so far (fetch()'s/searchProducts()'s own .then() chain included) - simpler than counting exactly
 * how many .then() hops the real request path involves.
 */
function flushPromises() {
    return new Promise(function (resolve) {
        setTimeout(resolve, 0);
    });
}

QUnit.module('Ordo_Automation/js/segment-sku-autocomplete', function () {
    QUnit.test('isSkuField() matches a top-level condition row\'s sku field, nothing else', function (assert) {
        const api = loadModule(MODULE_PATH);

        assert.true(api.isSkuField(global.$('<input name="conditions[conditions][2][sku]">')));
        assert.true(api.isSkuField(global.$('<input name="conditions[conditions][0][sku]">')));
        assert.false(api.isSkuField(global.$('<input name="conditions[conditions][2][tag]">')));
        assert.false(api.isSkuField(global.$('<input name="conditions[conditions][2][category_id]">')));
        assert.false(api.isSkuField(global.$('<input>')));
    });

    QUnit.test('searchProducts() resolves the response\'s items on a 200', async function (assert) {
        const api = loadModule(MODULE_PATH);

        stubFetch({
            ok: true,
            json: () => Promise.resolve({ items: [{ sku: '24-MB01', name: 'Blue Shirt' }] })
        });

        const items = await api.searchProducts('shirt');

        assert.deepEqual(items, [{ sku: '24-MB01', name: 'Blue Shirt' }]);
    });

    QUnit.test('searchProducts() resolves to an empty array on a non-ok response', async function (assert) {
        const api = loadModule(MODULE_PATH);

        stubFetch({ ok: false, json: () => Promise.resolve({}) });

        assert.deepEqual(await api.searchProducts('shirt'), []);
    });

    QUnit.test('searchProducts() resolves to an empty array when the response has no items key', async function (assert) {
        const api = loadModule(MODULE_PATH);

        stubFetch({ ok: true, json: () => Promise.resolve({}) });

        assert.deepEqual(await api.searchProducts('shirt'), []);
    });

    QUnit.test('searchProducts() resolves to an empty array instead of rejecting on a network error', async function (assert) {
        const api = loadModule(MODULE_PATH);

        global.fetch = function () {
            return Promise.reject(new Error('network down'));
        };

        assert.deepEqual(await api.searchProducts('shirt'), []);
    });

    QUnit.test('searchProducts() URL-encodes the search term', async function (assert) {
        const api = loadModule(MODULE_PATH);

        const calls = stubFetch({ ok: true, json: () => Promise.resolve({ items: [] }) });

        await api.searchProducts('a b&c');

        assert.true(calls[0][0].includes('term=a%20b%26c') || calls[0][0].includes('term=a+b%26c'));
    });

    QUnit.test('renderDropdown() appends one row per item, labeled "sku — name"', function (assert) {
        const api = loadModule(MODULE_PATH, '<input id="sku-field">');
        const $input = global.$('#sku-field');

        api.renderDropdown($input, [
            { sku: '24-MB01', name: 'Blue Shirt' },
            { sku: '24-MB02', name: 'Red Shirt' }
        ]);

        const $rows = global.$('.ordo-sku-suggest-row');

        assert.strictEqual($rows.length, 2);
        assert.strictEqual(global.$($rows[0]).text(), '24-MB01 — Blue Shirt');
        assert.strictEqual(global.$($rows[1]).text(), '24-MB02 — Red Shirt');
    });

    QUnit.test('renderDropdown() with an empty list closes any existing dropdown and adds nothing', function (assert) {
        const api = loadModule(MODULE_PATH, '<input id="sku-field">');
        const $input = global.$('#sku-field');

        api.renderDropdown($input, [{ sku: '24-MB01', name: 'Blue Shirt' }]);
        assert.strictEqual(global.$('.ordo-sku-suggest').length, 1);

        api.renderDropdown($input, []);
        assert.strictEqual(global.$('.ordo-sku-suggest').length, 0);
    });

    QUnit.test('a dropdown row\'s mousedown fills the input with its SKU and closes the dropdown', function (assert) {
        const api = loadModule(MODULE_PATH, '<input id="sku-field">');
        const $input = global.$('#sku-field');

        api.renderDropdown($input, [{ sku: '24-MB01', name: 'Blue Shirt' }]);
        global.$('.ordo-sku-suggest-row').trigger('mousedown');

        assert.strictEqual($input.val(), '24-MB01');
        assert.strictEqual(global.$('.ordo-sku-suggest').length, 0);
    });

    QUnit.test('closeDropdown() removes an existing dropdown and is a no-op when there is none', function (assert) {
        const api = loadModule(MODULE_PATH, '<input id="sku-field">');
        const $input = global.$('#sku-field');

        api.renderDropdown($input, [{ sku: '24-MB01', name: 'Blue Shirt' }]);
        api.closeDropdown();
        assert.strictEqual(global.$('.ordo-sku-suggest').length, 0);

        api.closeDropdown();
        assert.strictEqual(global.$('.ordo-sku-suggest').length, 0);
    });

    QUnit.test('typing into a matching sku field renders a dropdown of results while still focused', async function (assert) {
        loadModule(MODULE_PATH, '<input name="conditions[conditions][0][sku]">');
        const $input = global.$('input[name="conditions[conditions][0][sku]"]');

        stubFetch({ ok: true, json: () => Promise.resolve({ items: [{ sku: '24-MB01', name: 'Blue Shirt' }] }) });

        $input[0].focus();
        $input.val('shirt').trigger('input');
        await flushPromises();

        assert.strictEqual(global.$('.ordo-sku-suggest-row').length, 1);
    });

    QUnit.test('a search response is discarded once the input is no longer focused', async function (assert) {
        loadModule(MODULE_PATH, '<input name="conditions[conditions][0][sku]">');
        const $input = global.$('input[name="conditions[conditions][0][sku]"]');

        stubFetch({ ok: true, json: () => Promise.resolve({ items: [{ sku: '24-MB01', name: 'Blue Shirt' }] }) });

        $input[0].focus();
        $input.val('shirt').trigger('input');
        $input[0].blur();
        await flushPromises();

        assert.strictEqual(global.$('.ordo-sku-suggest-row').length, 0);
    });

    QUnit.test('typing into a non-sku input never triggers a search', async function (assert) {
        loadModule(MODULE_PATH, '<input name="conditions[conditions][0][tag]">');
        const $input = global.$('input[name="conditions[conditions][0][tag]"]');

        const calls = stubFetch({ ok: true, json: () => Promise.resolve({ items: [] }) });

        $input.val('shirt').trigger('input');
        await flushPromises();

        assert.strictEqual(calls.length, 0);
    });

    QUnit.test('a search term under 2 characters closes any open dropdown without searching', async function (assert) {
        loadModule(MODULE_PATH, '<input name="conditions[conditions][0][sku]">');
        const $input = global.$('input[name="conditions[conditions][0][sku]"]');

        global.$('body').append('<div class="ordo-sku-suggest"></div>');
        const calls = stubFetch({ ok: true, json: () => Promise.resolve({ items: [] }) });

        $input.val('a').trigger('input');
        await flushPromises();

        assert.strictEqual(calls.length, 0);
        assert.strictEqual(global.$('.ordo-sku-suggest').length, 0);
    });

    QUnit.test('losing focus schedules the dropdown to close after the mousedown grace period', function (assert) {
        loadModule(MODULE_PATH, '<input name="conditions[conditions][0][sku]">');
        const $input = global.$('input[name="conditions[conditions][0][sku]"]');
        const originalSetTimeout = global.setTimeout;
        let capturedDelay = null;
        let fire = null;

        // Same setTimeout-stubbing technique as free-gift-offer-form.test.js's sleep() test -
        // asserts the contract (a 150ms grace period is scheduled) without depending on real
        // elapsed time.
        global.setTimeout = function (callback, delay) {
            capturedDelay = delay;
            fire = callback;
            return 0;
        };

        try {
            global.$('body').append('<div class="ordo-sku-suggest"></div>');

            $input.trigger('focusout');

            assert.strictEqual(capturedDelay, 150);
            assert.strictEqual(global.$('.ordo-sku-suggest').length, 1, 'still open until the timer fires');

            fire();

            assert.strictEqual(global.$('.ordo-sku-suggest').length, 0);
        } finally {
            global.setTimeout = originalSetTimeout;
        }
    });
});
