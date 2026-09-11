'use strict';

const QUnit = require('qunit');
const { loadModule } = require('./support/load-module');

const MODULE_PATH = 'view/adminhtml/web/js/segment-overlap.js';

/**
 * Stubs global.fetch for one call - same pattern as Test/js/segment-audience-size.test.js's own
 * stubFetch().
 *
 * @param {Object} responseInit {ok, json: () => Promise}
 */
function stubFetch(responseInit) {
    global.fetch = function () {
        return Promise.resolve(responseInit);
    };
}

function panelHtml() {
    return '<div class="ordo-segment-overlap" data-overlap-compute-url="/admin/ordo/segment/overlapCompute">'
        + '<select data-overlap-segment="a"><option value="">-- Select --</option><option value="1">A</option></select>'
        + '<select data-overlap-segment="b"><option value="">-- Select --</option><option value="2">B</option></select>'
        + '<button type="button" data-overlap-compute="1">Compare</button>'
        + '<div data-overlap-result style="display: none">'
        + '<span data-overlap-size-a></span><span data-overlap-size-b></span>'
        + '<span data-overlap-intersection></span><span data-overlap-unique-a></span><span data-overlap-unique-b></span>'
        + '</div>'
        + '<div data-overlap-error style="display: none"></div>'
        + '</div>';
}

QUnit.module('Ordo_Automation/js/segment-overlap', function () {
    QUnit.test('compute() renders all five counts on a successful response', function (assert) {
        stubFetch({
            ok: true,
            json: function () {
                return Promise.resolve({size_a: 10, size_b: 8, intersection: 3, unique_a: 7, unique_b: 5});
            }
        });

        const api = loadModule(MODULE_PATH, panelHtml());
        global.$('[data-overlap-segment="a"]').val('1');
        global.$('[data-overlap-segment="b"]').val('2');

        return api.compute(global.$('.ordo-segment-overlap')).then(function () {
            assert.strictEqual(global.$('[data-overlap-size-a]').text(), '10');
            assert.strictEqual(global.$('[data-overlap-size-b]').text(), '8');
            assert.strictEqual(global.$('[data-overlap-intersection]').text(), '3');
            assert.strictEqual(global.$('[data-overlap-unique-a]').text(), '7');
            assert.strictEqual(global.$('[data-overlap-unique-b]').text(), '5');
            assert.notStrictEqual(global.$('[data-overlap-result]').css('display'), 'none');
            assert.strictEqual(global.$('[data-overlap-error]').css('display'), 'none');
        });
    });

    QUnit.test('compute() shows a validation error instead of fetching when a segment is unpicked', function (assert) {
        const api = loadModule(MODULE_PATH, panelHtml());
        global.$('[data-overlap-segment="a"]').val('1');
        global.$('[data-overlap-segment="b"]').val('');

        return api.compute(global.$('.ordo-segment-overlap')).then(function () {
            assert.strictEqual(global.$('[data-overlap-error]').text(), 'Pick two segments first.');
            assert.notStrictEqual(global.$('[data-overlap-error]').css('display'), 'none');
        });
    });

    QUnit.test('compute() shows the server-provided error message from the response body', function (assert) {
        stubFetch({ok: true, json: function () { return Promise.resolve({error: 'Pick two different segments.'}); }});

        const api = loadModule(MODULE_PATH, panelHtml());
        global.$('[data-overlap-segment="a"]').val('1');
        global.$('[data-overlap-segment="b"]').val('2');

        return api.compute(global.$('.ordo-segment-overlap')).then(function () {
            assert.strictEqual(global.$('[data-overlap-error]').text(), 'Pick two different segments.');
        });
    });

    QUnit.test('compute() shows a generic error message on a non-ok response', function (assert) {
        stubFetch({ok: false});

        const api = loadModule(MODULE_PATH, panelHtml());
        global.$('[data-overlap-segment="a"]').val('1');
        global.$('[data-overlap-segment="b"]').val('2');

        return api.compute(global.$('.ordo-segment-overlap')).then(function () {
            assert.strictEqual(global.$('[data-overlap-error]').text(), 'Could not compute overlap.');
        });
    });

    QUnit.test('compute() shows a generic error message on a network failure instead of throwing', function (assert) {
        global.fetch = function () {
            return Promise.reject(new Error('network down'));
        };

        const api = loadModule(MODULE_PATH, panelHtml());
        global.$('[data-overlap-segment="a"]').val('1');
        global.$('[data-overlap-segment="b"]').val('2');

        return api.compute(global.$('.ordo-segment-overlap')).then(function () {
            assert.strictEqual(global.$('[data-overlap-error]').text(), 'Could not compute overlap.');
        });
    });

    QUnit.test('clicking Compare triggers a fetch and disables the button until it resolves', function (assert) {
        const done = assert.async();

        stubFetch({
            ok: true,
            json: function () {
                return Promise.resolve({size_a: 1, size_b: 1, intersection: 1, unique_a: 0, unique_b: 0});
            }
        });

        loadModule(MODULE_PATH, panelHtml());
        global.$('[data-overlap-segment="a"]').val('1');
        global.$('[data-overlap-segment="b"]').val('2');

        global.$('[data-overlap-compute]').trigger('click');

        setTimeout(function () {
            assert.strictEqual(global.$('[data-overlap-intersection]').text(), '1');
            assert.false(global.$('[data-overlap-compute]').prop('disabled'));
            done();
        }, 0);
    });
});
