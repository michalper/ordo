'use strict';

const QUnit = require('qunit');
const { loadModule } = require('./support/load-module');

const MODULE_PATH = 'view/adminhtml/web/js/segment-group-modal.js';

QUnit.module('Ordo_Automation/js/segment-group-modal', function () {
    QUnit.test('readTypeOptions() excludes "group" and preserves each option\'s label text', function (assert) {
        const api = loadModule(
            MODULE_PATH,
            '<div data-index="conditions"><select>'
            + '<option value="tag">Has Tag</option>'
            + '<option value="group">Group (nested AND/OR)</option>'
            + '</select></div>'
        );

        assert.deepEqual(api.readTypeOptions(), [{ value: 'tag', label: 'Has Tag' }]);
    });

    QUnit.test('renderValueField() renders a labeled text input for a type with a dedicated field', function (assert) {
        const api = loadModule(MODULE_PATH);
        const $wrap = global.$('<div></div>');

        api.renderValueField($wrap, 'score_at_least', { threshold: '999' });

        assert.strictEqual($wrap.find('label').text(), 'Minimum score');
        assert.strictEqual($wrap.find('input').val(), '999');
        assert.strictEqual($wrap.data('valueKey'), 'threshold');
    });

    QUnit.test('renderValueField() falls back to a JSON textarea for a type with no dedicated field', function (assert) {
        const api = loadModule(MODULE_PATH);
        const $wrap = global.$('<div></div>');

        // No real ConditionPool type reaches this branch anymore - every registered type has a
        // dedicated field (text, or select for in_segment/not_in_segment/loyalty_tier_at_least).
        // Exercised here with a made-up type so the defensive fallback path itself stays covered.
        api.renderValueField($wrap, 'some_future_condition_type', { anything: 3 });

        assert.strictEqual($wrap.find('label').text(), 'Advanced (JSON)');
        assert.strictEqual($wrap.find('textarea').val(), '{"anything":3}');
        assert.strictEqual($wrap.data('valueKey'), null);
    });

    QUnit.test('renderValueField() leaves the JSON textarea blank for an empty params object', function (assert) {
        const api = loadModule(MODULE_PATH);
        const $wrap = global.$('<div></div>');

        api.renderValueField($wrap, 'some_future_condition_type', {});

        assert.strictEqual($wrap.find('textarea').val(), '');
    });

    QUnit.test('renderValueField() renders a select cloning options from the outer form\'s dedicated select', function (assert) {
        const api = loadModule(
            MODULE_PATH,
            '<div data-index="segment_id"><select>'
            + '<option value="1">VIP customers</option>'
            + '<option value="2">Churn risk</option>'
            + '</select></div>'
        );
        const $wrap = global.$('<div></div>');

        api.renderValueField($wrap, 'in_segment', { segment_id: '2' });

        assert.strictEqual($wrap.find('label').text(), 'Segment');
        assert.strictEqual($wrap.find('select option').length, 2);
        assert.strictEqual($wrap.find('select').val(), '2');
        assert.strictEqual($wrap.data('valueKey'), 'segment_id');
    });

    QUnit.test('appendInlineRow() + readRows() round-trips a dedicated-field condition', function (assert) {
        const api = loadModule(MODULE_PATH);
        const $rows = global.$('<div></div>');

        api.appendInlineRow(
            $rows,
            [{ value: 'score_at_least', label: 'Score At Least' }],
            { type: 'score_at_least', params: { threshold: '42' } },
            function () {}
        );

        assert.deepEqual(api.readRows($rows), [{ type: 'score_at_least', params: { threshold: '42' } }]);
    });

    QUnit.test('appendInlineRow() + readRows() round-trips a select-type condition', function (assert) {
        const api = loadModule(
            MODULE_PATH,
            '<div data-index="segment_id"><select>'
            + '<option value="7">Big Spenders</option>'
            + '</select></div>'
        );
        const $rows = global.$('<div></div>');

        api.appendInlineRow(
            $rows,
            [{ value: 'in_segment', label: 'In Segment' }],
            { type: 'in_segment', params: { segment_id: '7' } },
            function () {}
        );

        assert.deepEqual(api.readRows($rows), [{ type: 'in_segment', params: { segment_id: '7' } }]);
    });

    QUnit.test('readRows() drops an empty dedicated value instead of writing an empty-string param', function (assert) {
        const api = loadModule(MODULE_PATH);
        const $rows = global.$('<div></div>');

        api.appendInlineRow(
            $rows,
            [{ value: 'tag', label: 'Has Tag' }],
            { type: 'tag', params: { tag: 'vip' } },
            function () {}
        );
        $rows.find('input').val('');

        assert.deepEqual(api.readRows($rows), [{ type: 'tag', params: {} }]);
    });

    QUnit.test('readRows() tolerates invalid JSON in the fallback textarea instead of throwing', function (assert) {
        const api = loadModule(MODULE_PATH);
        const $rows = global.$('<div></div>');

        api.appendInlineRow(
            $rows,
            [{ value: 'some_future_condition_type', label: 'Some Future Condition Type' }],
            { type: 'some_future_condition_type', params: {} },
            function () {}
        );
        $rows.find('textarea').val('{not valid json');

        assert.deepEqual(api.readRows($rows), [{ type: 'some_future_condition_type', params: {} }]);
    });

    QUnit.test('readRows() visibly marks invalid JSON instead of failing silently', function (assert) {
        const api = loadModule(MODULE_PATH);
        const $rows = global.$('<div></div>');

        // in_segment now renders a <select> (see the "select cloning" test above) - this exercises
        // the still-real JSON-fallback path via a type with no dedicated field at all.
        api.appendInlineRow(
            $rows,
            [{ value: 'some_future_condition_type', label: 'Some Future Condition Type' }],
            { type: 'some_future_condition_type', params: {} },
            function () {}
        );
        $rows.find('textarea').val('{not valid json');
        api.readRows($rows);

        assert.strictEqual($rows.find('textarea').hasClass('ordo-group-json-invalid'), true);
        assert.strictEqual($rows.find('.ordo-group-json-error-message').length, 1);
    });

    QUnit.test('readRows() clears the invalid marker once the JSON is fixed', function (assert) {
        const api = loadModule(MODULE_PATH);
        const $rows = global.$('<div></div>');

        api.appendInlineRow(
            $rows,
            [{ value: 'some_future_condition_type', label: 'Some Future Condition Type' }],
            { type: 'some_future_condition_type', params: {} },
            function () {}
        );
        $rows.find('textarea').val('{not valid json');
        api.readRows($rows);
        $rows.find('textarea').val('{"anything": 3}');
        api.readRows($rows);

        assert.strictEqual($rows.find('textarea').hasClass('ordo-group-json-invalid'), false);
        assert.strictEqual($rows.find('.ordo-group-json-error-message').length, 0);
    });

    QUnit.test('appendInlineRow()\'s delete button removes the row and calls sync', function (assert) {
        const api = loadModule(MODULE_PATH);
        const $rows = global.$('<div></div>');
        let syncCalls = 0;

        api.appendInlineRow(
            $rows,
            [{ value: 'tag', label: 'Has Tag' }],
            { type: 'tag', params: { tag: 'vip' } },
            function () { syncCalls++; }
        );
        assert.strictEqual($rows.find('.ordo-group-row').length, 1);

        $rows.find('.ordo-group-row-delete').trigger('click');

        assert.strictEqual($rows.find('.ordo-group-row').length, 0, 'the row is removed from the DOM');
        assert.strictEqual(syncCalls, 1, 'sync is called exactly once');
    });

    QUnit.test('appendInlineRow() calls sync and re-renders the value field when the type changes', function (assert) {
        const api = loadModule(MODULE_PATH);
        const $rows = global.$('<div></div>');
        let syncCalls = 0;

        api.appendInlineRow(
            $rows,
            [
                { value: 'tag', label: 'Has Tag' },
                { value: 'score_at_least', label: 'Score At Least' }
            ],
            { type: 'tag', params: { tag: 'vip' } },
            function () { syncCalls++; }
        );

        $rows.find('select').val('score_at_least').trigger('change');

        assert.strictEqual($rows.find('.ordo-group-value label').text(), 'Minimum score');
        assert.strictEqual(syncCalls, 1);
    });

    QUnit.test('refreshGroupRows() builds an inline panel for a "group" row and is idempotent', function (assert) {
        const api = loadModule(
            MODULE_PATH,
            '<table data-index="conditions"><tbody><tr class="data-row">'
            + '<td><div data-index="type"><select>'
            + '<option value="tag">Has Tag</option>'
            + '<option value="group" selected>Group (nested AND/OR)</option>'
            + '</select></div></td>'
            + '<td><div data-index="group_logic"></div></td>'
            + '<td><textarea name="conditions[0][group_conditions_json]">'
            + '[{"type":"tag","params":{"tag":"vip"}}]</textarea></td>'
            + '</tr></tbody></table>'
        );

        api.refreshGroupRows();

        const $panels = global.$('.ordo-group-inline');

        assert.strictEqual($panels.length, 1, 'builds exactly one inline panel');
        assert.strictEqual($panels.find('.ordo-group-row').length, 1, 'renders the one existing condition inline');

        api.refreshGroupRows();

        assert.strictEqual(global.$('.ordo-group-inline').length, 1, 'does not duplicate the panel on a second scan');
    });

    QUnit.test('refreshGroupRows() removes the inline panel once the row is no longer type "group"', function (assert) {
        const api = loadModule(
            MODULE_PATH,
            '<table data-index="conditions"><tbody><tr class="data-row">'
            + '<td><div data-index="type"><select>'
            + '<option value="tag" selected>Has Tag</option>'
            + '<option value="group">Group (nested AND/OR)</option>'
            + '</select></div></td>'
            + '<td><div data-index="group_logic"></div></td>'
            + '<td><textarea name="conditions[0][group_conditions_json]">[]</textarea></td>'
            + '</tr></tbody></table>'
        );

        global.$('[data-index="type"] select').val('group');
        api.refreshGroupRows();
        assert.strictEqual(global.$('.ordo-group-inline').length, 1);

        global.$('[data-index="type"] select').val('tag');
        api.refreshGroupRows();
        assert.strictEqual(global.$('.ordo-group-inline').length, 0, 'panel removed once the row is no longer a group');
    });

    QUnit.test('refreshGroupRows() shows a visible message and starts empty when the saved group JSON is corrupted', function (assert) {
        const api = loadModule(
            MODULE_PATH,
            '<table data-index="conditions"><tbody><tr class="data-row">'
            + '<td><div data-index="type"><select>'
            + '<option value="group" selected>Group (nested AND/OR)</option>'
            + '</select></div></td>'
            + '<td><div data-index="group_logic"></div></td>'
            + '<td><textarea name="conditions[0][group_conditions_json]">{not valid json</textarea></td>'
            + '</tr></tbody></table>'
        );

        api.refreshGroupRows();

        assert.strictEqual(global.$('.ordo-group-row').length, 0, 'starts with no rows rather than throwing');
        assert.strictEqual(global.$('.ordo-group-json-error-message').length, 1, 'shows a visible corruption notice');
    });
});
