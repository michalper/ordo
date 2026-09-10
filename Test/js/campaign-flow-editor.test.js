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
});
