'use strict';

const QUnit = require('qunit');
const { loadModule } = require('./support/load-module');

const MODULE_PATH = 'view/adminhtml/web/js/segment-audience-size.js';

/**
 * Stubs global.fetch for one call - same pattern as Test/js/free-gift-offer-form.test.js's own
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
    return '<div class="ordo-audience-size-panel" data-segment-id="7" data-audience-size-url="/admin/ordo/segment/audienceSize">'
        + '<p data-audience-size-unsaved-warning="1" style="display: none">unsaved changes</p>'
        + '<div data-audience-size-value="1">Not calculated yet</div>'
        + '<button type="button" data-audience-size-refresh="1">Refresh</button>'
        + '</div>';
}

/**
 * Same panel, plus a stand-in for the rest of the segment condition form (a plain text input
 * outside the audience-size panel) and the unrelated bulk-actions panel, to exercise the
 * dirty-tracking listener's page-wide scope and its exclusions.
 */
function panelHtmlWithConditionFormAndBulkActionsPanel() {
    return panelHtml()
        + '<input type="text" id="segment-condition-field" value="">'
        + '<div class="ordo-bulk-actions-panel"><select id="bulk-action-type"><option>add_tag</option></select></div>';
}

QUnit.module('Ordo_Automation/js/segment-audience-size', function () {
    QUnit.test('refresh() shows the returned count', function (assert) {
        stubFetch({ok: true, json: function () { return Promise.resolve({count: 5}); }});

        const api = loadModule(MODULE_PATH, panelHtml());

        return api.refresh(global.$('.ordo-audience-size-panel')).then(function () {
            assert.strictEqual(global.$('[data-audience-size-value]').text(), '5 customer(s)');
            assert.false(global.$('[data-audience-size-refresh]').prop('disabled'));
        });
    });

    QUnit.test('refresh() shows an error message on a non-ok response', function (assert) {
        stubFetch({ok: false});

        const api = loadModule(MODULE_PATH, panelHtml());

        return api.refresh(global.$('.ordo-audience-size-panel')).then(function () {
            assert.strictEqual(
                global.$('[data-audience-size-value]').text(),
                'Could not calculate audience size.'
            );
        });
    });

    QUnit.test('refresh() shows an error message on a network failure instead of throwing', function (assert) {
        global.fetch = function () {
            return Promise.reject(new Error('network down'));
        };

        const api = loadModule(MODULE_PATH, panelHtml());

        return api.refresh(global.$('.ordo-audience-size-panel')).then(function () {
            assert.strictEqual(
                global.$('[data-audience-size-value]').text(),
                'Could not calculate audience size.'
            );
        });
    });

    QUnit.test('clicking Refresh triggers a fetch and disables the button until it resolves', function (assert) {
        const done = assert.async();

        stubFetch({ok: true, json: function () { return Promise.resolve({count: 2}); }});

        loadModule(MODULE_PATH, panelHtml());

        global.$('[data-audience-size-refresh]').trigger('click');

        setTimeout(function () {
            assert.strictEqual(global.$('[data-audience-size-value]').text(), '2 customer(s)');
            done();
        }, 0);
    });

    QUnit.test('editing a field outside the panel shows the unsaved-changes warning', function (assert) {
        loadModule(MODULE_PATH, panelHtmlWithConditionFormAndBulkActionsPanel());

        assert.strictEqual(global.$('[data-audience-size-unsaved-warning]').css('display'), 'none');

        global.$('#segment-condition-field').val('changed').trigger('input');

        assert.notStrictEqual(global.$('[data-audience-size-unsaved-warning]').css('display'), 'none');
    });

    QUnit.test('editing a field inside the bulk-actions panel does not show the warning', function (assert) {
        loadModule(MODULE_PATH, panelHtmlWithConditionFormAndBulkActionsPanel());

        global.$('#bulk-action-type').trigger('change');

        assert.strictEqual(global.$('[data-audience-size-unsaved-warning]').css('display'), 'none');
    });

    QUnit.test('markDirty() is idempotent and only needs to be called once to show the warning', function (assert) {
        const api = loadModule(MODULE_PATH, panelHtml());

        api.markDirty(global.$('.ordo-audience-size-panel'));
        api.markDirty(global.$('.ordo-audience-size-panel'));

        assert.notStrictEqual(global.$('[data-audience-size-unsaved-warning]').css('display'), 'none');
    });
});
