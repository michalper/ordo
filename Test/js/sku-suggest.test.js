'use strict';

const QUnit = require('qunit');
const { loadModule } = require('./support/load-module');

const MODULE_PATH = 'view/adminhtml/web/js/sku-suggest.js';

/**
 * Waits for one macrotask tick, by which point Node has drained every microtask queued so far -
 * the search handler's own resolution included. Same helper, same reasoning, as
 * segment-sku-autocomplete.test.js's own flushPromises().
 */
function flushPromises() {
    return new Promise(function (resolve) {
        setTimeout(resolve, 0);
    });
}

/**
 * A recording stand-in for one caller's four handlers. `search` resolves to `items` and never
 * rejects, matching what attach() documents it requires of a caller.
 *
 * @param {Object} [overrides] replaces any of the four handlers (e.g. a narrower isField)
 * @param {Array} [items] what search() resolves to
 * @return {Object} the handlers object, with a `calls` log hung off it
 */
function recordingHandlers(overrides, items) {
    const calls = { search: [], render: [], close: 0 };
    const handlers = Object.assign({
        isField: function ($input) {
            return $input.attr('name') === 'sku';
        },
        search: function (term) {
            calls.search.push(term);

            return Promise.resolve(items || []);
        },
        render: function ($input, rendered) {
            calls.render.push(rendered);
        },
        close: function () {
            calls.close++;
        }
    }, overrides || {});

    handlers.calls = calls;

    return handlers;
}

QUnit.module('Ordo_Automation/js/sku-suggest', function () {
    QUnit.test('typing into a claimed field searches the trimmed term and renders the result', async function (assert) {
        const api = loadModule(MODULE_PATH, '<input name="sku">');
        const handlers = recordingHandlers(null, [{ sku: '24-MB01' }]);

        api.attach(handlers);

        const $input = global.$('input[name="sku"]');

        $input[0].focus();
        $input.val('  shirt  ').trigger('input');
        await flushPromises();

        assert.deepEqual(handlers.calls.search, ['shirt'], 'searched the trimmed term');
        assert.deepEqual(handlers.calls.render, [[{ sku: '24-MB01' }]]);
        assert.strictEqual(handlers.calls.close, 0);
    });

    QUnit.test('a term under 2 characters closes the dropdown instead of searching', async function (assert) {
        const api = loadModule(MODULE_PATH, '<input name="sku">');
        const handlers = recordingHandlers();

        api.attach(handlers);

        const $input = global.$('input[name="sku"]');

        $input[0].focus();
        $input.val('a').trigger('input');
        await flushPromises();

        assert.deepEqual(handlers.calls.search, []);
        assert.strictEqual(handlers.calls.close, 1);
    });

    QUnit.test('an empty field closes the dropdown rather than searching for nothing', async function (assert) {
        const api = loadModule(MODULE_PATH, '<input name="sku">');
        const handlers = recordingHandlers();

        api.attach(handlers);

        const $input = global.$('input[name="sku"]');

        $input[0].focus();
        $input.val('').trigger('input');
        await flushPromises();

        assert.deepEqual(handlers.calls.search, []);
        assert.strictEqual(handlers.calls.close, 1);
    });

    QUnit.test('a field the caller does not claim is ignored entirely - no search, no close', async function (assert) {
        const api = loadModule(MODULE_PATH, '<input name="qty">');
        const handlers = recordingHandlers();

        api.attach(handlers);

        const $input = global.$('input[name="qty"]');

        $input[0].focus();
        $input.val('shirt').trigger('input');
        await flushPromises();

        assert.deepEqual(handlers.calls.search, []);
        assert.strictEqual(handlers.calls.close, 0);
    });

    QUnit.test('a result is discarded when the field is no longer focused by the time it arrives', async function (assert) {
        const api = loadModule(MODULE_PATH, '<input name="sku">');
        const handlers = recordingHandlers(null, [{ sku: '24-MB01' }]);

        api.attach(handlers);

        const $input = global.$('input[name="sku"]');

        $input[0].focus();
        $input.val('shirt').trigger('input');
        $input[0].blur();
        await flushPromises();

        assert.deepEqual(handlers.calls.search, ['shirt'], 'the search still went out');
        assert.deepEqual(handlers.calls.render, [], 'but nothing was rendered against a blurred field');
    });

    QUnit.test('the delegate picks up a field added to the page after attach()', async function (assert) {
        const api = loadModule(MODULE_PATH);
        const handlers = recordingHandlers(null, [{ sku: '24-MB01' }]);

        api.attach(handlers);

        global.$('body').append('<input name="sku">');

        const $input = global.$('input[name="sku"]');

        $input[0].focus();
        $input.val('shirt').trigger('input');
        await flushPromises();

        assert.deepEqual(handlers.calls.render, [[{ sku: '24-MB01' }]]);
    });

    QUnit.test('two callers on one document each only ever see their own fields', async function (assert) {
        const api = loadModule(MODULE_PATH, '<input name="sku"><input name="other-sku">');
        const first = recordingHandlers();
        const second = recordingHandlers({
            isField: function ($input) {
                return $input.attr('name') === 'other-sku';
            }
        });

        api.attach(first);
        api.attach(second);

        const $other = global.$('input[name="other-sku"]');

        $other[0].focus();
        $other.val('shirt').trigger('input');
        await flushPromises();

        assert.deepEqual(first.calls.search, [], 'the first caller never saw the second\'s field');
        assert.deepEqual(second.calls.search, ['shirt']);
    });

    QUnit.test('attach() hands back the debounced suggest, callable directly', async function (assert) {
        const api = loadModule(MODULE_PATH, '<input name="sku">');
        const handlers = recordingHandlers(null, [{ sku: '24-MB01' }]);

        const suggest = api.attach(handlers);
        const $input = global.$('input[name="sku"]');

        assert.strictEqual(typeof suggest, 'function');

        $input[0].focus();
        $input.val('shirt');
        suggest($input);
        await flushPromises();

        assert.deepEqual(handlers.calls.render, [[{ sku: '24-MB01' }]]);
    });
});
