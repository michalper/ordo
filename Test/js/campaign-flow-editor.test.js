'use strict';

const QUnit = require('qunit');
const { loadModule } = require('./support/load-module');

const MODULE_PATH = 'view/adminhtml/web/js/campaign-flow-editor.js';

/**
 * unionNodeOutputConnections()/findDisconnectedNodeIds() are pure top-level helpers (no DOM,
 * jQuery, or Drawflow dependency of their own - see the module's own comment on why they were
 * pulled out of buildConnectivityGroups()/validateFlow() in the first place), exposed as
 * properties on the exported initCampaignFlowEditor function purely for this test to reach them.
 */
QUnit.module('Ordo_Automation/js/campaign-flow-editor', function () {
    QUnit.test('unionNodeOutputConnections() unions the source node with every connected target', function (assert) {
        const initCampaignFlowEditor = loadModule(MODULE_PATH);
        const calls = [];
        const union = function (a, b) {
            calls.push([a, b]);
        };
        const node = {
            outputs: {
                output_1: { connections: [{ node: '2' }, { node: '3' }] },
                output_2: { connections: [{ node: '4' }] }
            }
        };

        initCampaignFlowEditor.unionNodeOutputConnections('1', node, union);

        assert.deepEqual(calls, [['1', '2'], ['1', '3'], ['1', '4']]);
    });

    QUnit.test('unionNodeOutputConnections() is a no-op for a node with no outputs', function (assert) {
        const initCampaignFlowEditor = loadModule(MODULE_PATH);
        const calls = [];

        initCampaignFlowEditor.unionNodeOutputConnections('1', {}, function () {
            calls.push(true);
        });

        assert.deepEqual(calls, []);
    });

    QUnit.test('findDisconnectedNodeIds() returns every id whose group root differs from the primary root', function (assert) {
        const initCampaignFlowEditor = loadModule(MODULE_PATH);
        const roots = { 1: 'A', 2: 'A', 3: 'B' };
        const groups = { find: function (id) { return roots[id]; } };

        assert.deepEqual(
            initCampaignFlowEditor.findDisconnectedNodeIds({ 1: {}, 2: {}, 3: {} }, groups, 'A'),
            ['3']
        );
    });

    QUnit.test('findDisconnectedNodeIds() returns an empty array when every node shares the primary root', function (assert) {
        const initCampaignFlowEditor = loadModule(MODULE_PATH);
        const groups = { find: function () { return 'A'; } };

        assert.deepEqual(initCampaignFlowEditor.findDisconnectedNodeIds({ 1: {}, 2: {} }, groups, 'A'), []);
    });

    QUnit.test('cloneSplitVariant() fills in defaults for a bare/malformed raw entry', function (assert) {
        const initCampaignFlowEditor = loadModule(MODULE_PATH);

        assert.deepEqual(initCampaignFlowEditor.cloneSplitVariant(null), { key: '', weight: 0, actions: [] });
        assert.deepEqual(initCampaignFlowEditor.cloneSplitVariant({}), { key: '', weight: 0, actions: [] });
    });

    QUnit.test('cloneSplitVariant() preserves a well-formed raw entry including its nested actions', function (assert) {
        const initCampaignFlowEditor = loadModule(MODULE_PATH);
        const raw = { key: 'a', weight: 50, actions: [{ type: 'add_tag', params: { tag: 'vip' } }] };

        assert.deepEqual(initCampaignFlowEditor.cloneSplitVariant(raw), raw);
    });

    QUnit.test('cloneSplitVariant() defaults a malformed nested action to a blank type/empty params', function (assert) {
        const initCampaignFlowEditor = loadModule(MODULE_PATH);
        const raw = { key: 'a', weight: 50, actions: [null, { type: 'add_tag' }] };

        assert.deepEqual(
            initCampaignFlowEditor.cloneSplitVariant(raw),
            { key: 'a', weight: 50, actions: [{ type: '', params: {} }, { type: 'add_tag', params: {} }] }
        );
    });

    QUnit.test('buildSplitVariantActionTypeOptionsHtml() excludes "split" itself and marks the selected type', function (assert) {
        const initCampaignFlowEditor = loadModule(MODULE_PATH);
        const html = initCampaignFlowEditor.buildSplitVariantActionTypeOptionsHtml(
            ['add_tag', 'send_email', 'split'],
            { add_tag: 'Add Tag', send_email: 'Send Email' },
            'send_email',
            function (raw) { return raw; }
        );

        assert.notOk(html.includes('value="split"'));
        assert.ok(html.includes('<option value="add_tag">Add Tag</option>'));
        assert.ok(html.includes('<option value="send_email" selected="selected">Send Email</option>'));
    });

    QUnit.test('buildSplitVariantActionTypeOptionsHtml() falls back to the raw type key when no label is known', function (assert) {
        const initCampaignFlowEditor = loadModule(MODULE_PATH);
        const html = initCampaignFlowEditor.buildSplitVariantActionTypeOptionsHtml(
            ['custom_action'],
            {},
            '',
            function (raw) { return raw; }
        );

        assert.ok(html.includes('<option value="custom_action">custom_action</option>'));
    });

    /**
     * renderVariantEditor() is the interactive widget campaign-flow-editor.js's renderFields()
     * builds for a `split` action's own `variants` field - exposed on the exported function
     * (same pattern as the pure helpers above) so it can be driven directly against a real
     * jsdom container, without needing a working Drawflow instance (Test/js/support's stub
     * Drawflow can't stand in for the real one initCampaignFlowEditor() itself constructs).
     */
    QUnit.module('renderVariantEditor()', function () {
        const TYPES_CONFIG = {
            actions: ['add_tag', 'send_email', 'split'],
            labels: { action: { add_tag: 'Add Tag', send_email: 'Send Email' } }
        };

        QUnit.test('renders one hidden input and no variant blocks for an empty initial list', function (assert) {
            const initCampaignFlowEditor = loadModule(MODULE_PATH);
            const $container = global.$('<div></div>');

            initCampaignFlowEditor.renderVariantEditor($container, 'variants', [], TYPES_CONFIG);

            assert.strictEqual($container.find('.ordo-flow-variant-block').length, 0);
            assert.strictEqual($container.find('input[data-field="variants"]').val(), '[]');
        });

        QUnit.test('pre-fills one block per initial variant, with its key/weight and its own actions', function (assert) {
            const initCampaignFlowEditor = loadModule(MODULE_PATH);
            const $container = global.$('<div></div>');
            const initial = [
                { key: 'a', weight: 50, actions: [{ type: 'add_tag', params: { tag: 'vip' } }] },
                { key: 'b', weight: 50, actions: [] }
            ];

            initCampaignFlowEditor.renderVariantEditor($container, 'variants', initial, TYPES_CONFIG);

            const $blocks = $container.find('.ordo-flow-variant-block');
            assert.strictEqual($blocks.length, 2);
            assert.strictEqual($blocks.eq(0).find('.ordo-flow-variant-key').val(), 'a');
            assert.strictEqual($blocks.eq(0).find('.ordo-flow-variant-weight').val(), '50');
            assert.strictEqual($blocks.eq(0).find('.ordo-flow-variant-action-row').length, 1);
            assert.strictEqual($blocks.eq(0).find('.ordo-flow-variant-action-type').val(), 'add_tag');
            assert.strictEqual($blocks.eq(0).find('.ordo-flow-variant-action-params').val(), '{"tag":"vip"}');
            assert.strictEqual($blocks.eq(1).find('.ordo-flow-variant-action-row').length, 0);
        });

        QUnit.test('"+ Add variant" appends a blank variant block and updates the hidden input', function (assert) {
            const initCampaignFlowEditor = loadModule(MODULE_PATH);
            const $container = global.$('<div></div>');

            initCampaignFlowEditor.renderVariantEditor($container, 'variants', [], TYPES_CONFIG);
            $container.find('.ordo-flow-variant-add').trigger('click');

            assert.strictEqual($container.find('.ordo-flow-variant-block').length, 1);
            assert.deepEqual(
                JSON.parse($container.find('input[data-field="variants"]').val()),
                [{ key: '', weight: 0, actions: [] }]
            );
        });

        QUnit.test('editing the key/weight inputs updates the hidden input', function (assert) {
            const initCampaignFlowEditor = loadModule(MODULE_PATH);
            const $container = global.$('<div></div>');

            initCampaignFlowEditor.renderVariantEditor($container, 'variants', [{ key: '', weight: 0, actions: [] }], TYPES_CONFIG);
            $container.find('.ordo-flow-variant-key').val('a').trigger('input');
            $container.find('.ordo-flow-variant-weight').val('75').trigger('input');

            assert.deepEqual(
                JSON.parse($container.find('input[data-field="variants"]').val()),
                [{ key: 'a', weight: 75, actions: [] }]
            );
        });

        QUnit.test('"Remove variant" removes that block and re-syncs the hidden input', function (assert) {
            const initCampaignFlowEditor = loadModule(MODULE_PATH);
            const $container = global.$('<div></div>');
            const initial = [{ key: 'a', weight: 50, actions: [] }, { key: 'b', weight: 50, actions: [] }];

            initCampaignFlowEditor.renderVariantEditor($container, 'variants', initial, TYPES_CONFIG);
            $container.find('.ordo-flow-variant-block').eq(0).find('.ordo-flow-variant-remove').trigger('click');

            assert.strictEqual($container.find('.ordo-flow-variant-block').length, 1);
            assert.strictEqual($container.find('.ordo-flow-variant-key').val(), 'b');
        });

        QUnit.test('"+ Add action" appends a blank action row (defaulting to the first non-split type) to that variant', function (assert) {
            const initCampaignFlowEditor = loadModule(MODULE_PATH);
            const $container = global.$('<div></div>');

            initCampaignFlowEditor.renderVariantEditor($container, 'variants', [{ key: 'a', weight: 100, actions: [] }], TYPES_CONFIG);
            $container.find('.ordo-flow-variant-action-add').trigger('click');

            assert.strictEqual($container.find('.ordo-flow-variant-action-row').length, 1);
            assert.strictEqual($container.find('.ordo-flow-variant-action-type').val(), 'add_tag');
            assert.deepEqual(
                JSON.parse($container.find('input[data-field="variants"]').val())[0].actions,
                [{ type: 'add_tag', params: {} }]
            );
        });

        QUnit.test('changing an action\'s type updates the hidden input', function (assert) {
            const initCampaignFlowEditor = loadModule(MODULE_PATH);
            const $container = global.$('<div></div>');
            const initial = [{ key: 'a', weight: 100, actions: [{ type: 'add_tag', params: {} }] }];

            initCampaignFlowEditor.renderVariantEditor($container, 'variants', initial, TYPES_CONFIG);
            $container.find('.ordo-flow-variant-action-type').val('send_email').trigger('change');

            assert.strictEqual(
                JSON.parse($container.find('input[data-field="variants"]').val())[0].actions[0].type,
                'send_email'
            );
        });

        QUnit.test('editing an action\'s params textarea with valid JSON updates the hidden input', function (assert) {
            const initCampaignFlowEditor = loadModule(MODULE_PATH);
            const $container = global.$('<div></div>');
            const initial = [{ key: 'a', weight: 100, actions: [{ type: 'add_tag', params: {} }] }];

            initCampaignFlowEditor.renderVariantEditor($container, 'variants', initial, TYPES_CONFIG);
            $container.find('.ordo-flow-variant-action-params').val('{"tag":"vip"}').trigger('input');

            assert.deepEqual(
                JSON.parse($container.find('input[data-field="variants"]').val())[0].actions[0].params,
                { tag: 'vip' }
            );
        });

        QUnit.test('editing an action\'s params textarea with invalid JSON leaves the last-known-good params in place', function (assert) {
            const initCampaignFlowEditor = loadModule(MODULE_PATH);
            const $container = global.$('<div></div>');
            const initial = [{ key: 'a', weight: 100, actions: [{ type: 'add_tag', params: { tag: 'vip' } }] }];

            initCampaignFlowEditor.renderVariantEditor($container, 'variants', initial, TYPES_CONFIG);
            $container.find('.ordo-flow-variant-action-params').val('{not valid json').trigger('input');

            assert.deepEqual(
                JSON.parse($container.find('input[data-field="variants"]').val())[0].actions[0].params,
                { tag: 'vip' }
            );
        });

        QUnit.test('"Remove action" removes that action row from its variant and re-syncs the hidden input', function (assert) {
            const initCampaignFlowEditor = loadModule(MODULE_PATH);
            const $container = global.$('<div></div>');
            const initial = [{
                key: 'a',
                weight: 100,
                actions: [{ type: 'add_tag', params: {} }, { type: 'send_email', params: {} }]
            }];

            initCampaignFlowEditor.renderVariantEditor($container, 'variants', initial, TYPES_CONFIG);
            $container.find('.ordo-flow-variant-action-row').eq(0).find('.ordo-flow-variant-action-remove').trigger('click');

            assert.strictEqual($container.find('.ordo-flow-variant-action-row').length, 1);
            assert.strictEqual($container.find('.ordo-flow-variant-action-type').val(), 'send_email');
        });
    });
});
