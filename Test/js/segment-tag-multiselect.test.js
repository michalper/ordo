'use strict';

const QUnit = require('qunit');
const { loadModule } = require('./support/load-module');

const MODULE_PATH = 'view/adminhtml/web/js/segment-tag-multiselect.js';

/**
 * Stubs global.fetch for one call and returns the args it was called with, so a test can assert
 * the request URL/params without a real network round trip. Same helper shape as
 * Test/js/segment-sku-autocomplete.test.js's own.
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

function flushPromises() {
    return new Promise(function (resolve) {
        setTimeout(resolve, 0);
    });
}

function wait(ms) {
    return new Promise(function (resolve) {
        setTimeout(resolve, ms);
    });
}

QUnit.module('Ordo_Automation/js/segment-tag-multiselect', function () {
    QUnit.test('isTagsField() matches a conditions or actions row\'s "tags" field, nothing else', function (assert) {
        const api = loadModule(MODULE_PATH);

        assert.true(api.isTagsField('conditions[conditions][2][tags]'));
        assert.true(api.isTagsField('conditions[conditions][0][tags]'));
        assert.true(api.isTagsField('actions[actions][3][tags]'));
        assert.false(api.isTagsField('conditions[conditions][2][sku]'), 'a different field name');
        assert.false(api.isTagsField('actions[actions][2][template]'), 'a different field name on actions');
        assert.false(api.isTagsField('conditions[2][tags]'), 'missing the doubled dataScope segment');
        assert.false(api.isTagsField(''));
    });

    QUnit.test('scan() enhances a matching input already present at load, hiding it and mounting the chip editor', function (assert) {
        loadModule(
            MODULE_PATH,
            '<input name="conditions[conditions][0][tags]" value=\'["vip","loyal"]\'>'
        );

        const $input = global.$('input[name="conditions[conditions][0][tags]"]');

        assert.true($input.hasClass('ordo-tag-chip-enhanced'));
        assert.strictEqual($input.css('display'), 'none', 'raw input hidden once enhanced');
        assert.strictEqual(global.$('.ordo-tag-chip-editor-mount').length, 1, 'widget mount point inserted');

        const chips = global.$('.ordo-tag-chip').map(function () {
            return global.$(this).text();
        }).get();

        assert.deepEqual(chips, ['vip×', 'loyal×'], 'initial tags parsed from the JSON value');
    });

    QUnit.test('an input whose name does not match is left alone', function (assert) {
        loadModule(MODULE_PATH, '<input name="conditions[conditions][0][sku]" value="24-MB01">');

        const $input = global.$('input[name="conditions[conditions][0][sku]"]');

        assert.false($input.hasClass('ordo-tag-chip-enhanced'));
        assert.strictEqual(global.$('.ordo-tag-chip-editor-mount').length, 0);
    });

    QUnit.test('a malformed JSON value starts the editor with no tags instead of failing to render', function (assert) {
        const originalWarn = console.warn;
        const warnings = [];

        console.warn = function () {
            warnings.push(Array.from(arguments));
        };

        try {
            loadModule(MODULE_PATH, '<input name="conditions[conditions][0][tags]" value="not json">');

            assert.strictEqual(global.$('.ordo-tag-chip-editor-mount').length, 1, 'widget still mounted');
            assert.strictEqual(global.$('.ordo-tag-chip').length, 0, 'no tags to show');
            assert.strictEqual(warnings.length, 1, 'the corrupted value is still logged, not silently swallowed');
        } finally {
            console.warn = originalWarn;
        }
    });

    QUnit.test('a blank value is treated the same as no tags, with no warning', function (assert) {
        const originalWarn = console.warn;
        const warnings = [];

        console.warn = function () {
            warnings.push(Array.from(arguments));
        };

        try {
            loadModule(MODULE_PATH, '<input name="conditions[conditions][0][tags]" value="">');

            assert.strictEqual(global.$('.ordo-tag-chip').length, 0);
            assert.strictEqual(warnings.length, 0);
        } finally {
            console.warn = originalWarn;
        }
    });

    QUnit.test('a JSON value that is not an array (e.g. a legacy object) also starts with no tags', function (assert) {
        loadModule(MODULE_PATH, '<input name="conditions[conditions][0][tags]" value=\'{"foo":"bar"}\'>');

        assert.strictEqual(global.$('.ordo-tag-chip').length, 0);
    });

    QUnit.test('enhance() run twice on the same input mounts the widget only once', function (assert) {
        const api = loadModule(MODULE_PATH, '<input name="conditions[conditions][0][tags]" value="[]">');
        const input = global.$('input[name="conditions[conditions][0][tags]"]').get(0);

        api.enhance(input);
        api.enhance(input);

        assert.strictEqual(global.$('.ordo-tag-chip-editor-mount').length, 1);
    });

    QUnit.test('a matching input added to the page later is picked up by the MutationObserver, with no manual scan() call', async function (assert) {
        loadModule(MODULE_PATH);

        assert.strictEqual(global.$('.ordo-tag-chip-editor-mount').length, 0, 'nothing to enhance yet');

        global.$('body').append('<input name="actions[actions][0][tags]" value="[]">');

        await wait(10);

        assert.true(
            global.$('input[name="actions[actions][0][tags]"]').hasClass('ordo-tag-chip-enhanced'),
            'observer-triggered scan() enhanced the new input'
        );
    });

    QUnit.test('searchTags() (via the mounted chip editor\'s own search box) queries tag/search and returns matching tags', async function (assert) {
        loadModule(MODULE_PATH, '<input name="conditions[conditions][0][tags]" value="[]">');

        const calls = stubFetch({ ok: true, json: () => Promise.resolve({ items: [{ tag: 'vip' }, { tag: 'loyal' }] }) });

        global.$('.ordo-tag-chip-input').val('vi').trigger('input');
        await wait(250);
        await flushPromises();

        assert.strictEqual(calls.length, 1);
        assert.ok(String(calls[0][0]).includes('tag/search?term=vi'), 'built the right search URL: ' + calls[0][0]);

        const rows = global.$('.ordo-tag-chip-suggest-row').map(function () {
            return global.$(this).text();
        }).get();

        assert.deepEqual(rows, ['vip', 'loyal']);
    });

    QUnit.test('searchTags() resolves to an empty list instead of throwing on a network failure', async function (assert) {
        loadModule(MODULE_PATH, '<input name="conditions[conditions][0][tags]" value="[]">');

        global.fetch = function () {
            return Promise.reject(new Error('network down'));
        };

        global.$('.ordo-tag-chip-input').val('vi').trigger('input');
        await wait(250);
        await flushPromises();

        assert.strictEqual(global.$('.ordo-tag-chip-suggest').length, 0, 'no dropdown, and nothing thrown');
    });

    QUnit.test('searchTags() falls back to no results on a non-ok response', async function (assert) {
        loadModule(MODULE_PATH, '<input name="conditions[conditions][0][tags]" value="[]">');

        stubFetch({ ok: false });

        global.$('.ordo-tag-chip-input').val('vi').trigger('input');
        await wait(250);
        await flushPromises();

        assert.strictEqual(global.$('.ordo-tag-chip-suggest-row').length, 0);
    });
});
