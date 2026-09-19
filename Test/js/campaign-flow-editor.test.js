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

    QUnit.test('paletteItemMatchesQuery() matches on label substring, case-insensitively', function (assert) {
        const initCampaignFlowEditor = loadModule(MODULE_PATH);

        assert.true(initCampaignFlowEditor.paletteItemMatchesQuery('Order total at least', 'order_total_gte', 'TOTAL'));
        assert.false(initCampaignFlowEditor.paletteItemMatchesQuery('Order total at least', 'order_total_gte', 'zzz'));
    });

    QUnit.test('paletteItemMatchesQuery() matches on the raw type when the label does not', function (assert) {
        const initCampaignFlowEditor = loadModule(MODULE_PATH);

        assert.true(initCampaignFlowEditor.paletteItemMatchesQuery('Order total at least', 'order_total_gte', 'gte'));
    });

    QUnit.test('paletteItemMatchesQuery() treats a blank or whitespace-only query as matching everything', function (assert) {
        const initCampaignFlowEditor = loadModule(MODULE_PATH);

        assert.true(initCampaignFlowEditor.paletteItemMatchesQuery('Order total at least', 'order_total_gte', ''));
        assert.true(initCampaignFlowEditor.paletteItemMatchesQuery('Order total at least', 'order_total_gte', '   '));
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

/**
 * testSendChannelForActionType()/buildTestSendPayload() back the inline "Send test" button on
 * send_email/send_sms/send_whatsapp action nodes - pure top-level helpers, same testing
 * convention as unionNodeOutputConnections()/paletteItemMatchesQuery() above.
 */
QUnit.module('Ordo_Automation/js/campaign-flow-editor inline test-send', function () {
    QUnit.test('testSendChannelForActionType() maps every testable action type to its channel', function (assert) {
        const initCampaignFlowEditor = loadModule(MODULE_PATH);

        assert.strictEqual(initCampaignFlowEditor.testSendChannelForActionType('send_email'), 'email');
        assert.strictEqual(initCampaignFlowEditor.testSendChannelForActionType('send_sms'), 'sms');
        assert.strictEqual(initCampaignFlowEditor.testSendChannelForActionType('send_whatsapp'), 'whatsapp');
    });

    QUnit.test('testSendChannelForActionType() returns null for a non-testable/unknown action type', function (assert) {
        const initCampaignFlowEditor = loadModule(MODULE_PATH);

        assert.strictEqual(initCampaignFlowEditor.testSendChannelForActionType('send_push'), null);
        assert.strictEqual(initCampaignFlowEditor.testSendChannelForActionType('add_tag'), null);
        assert.strictEqual(initCampaignFlowEditor.testSendChannelForActionType(''), null);
    });

    QUnit.test('buildTestSendPayload() builds an email/sms payload from the "message" field', function (assert) {
        const initCampaignFlowEditor = loadModule(MODULE_PATH);

        assert.deepEqual(
            initCampaignFlowEditor.buildTestSendPayload('email', 'jan@example.com', { message: 'Hello' }),
            { channel: 'email', to: 'jan@example.com', message: 'Hello' }
        );
        assert.deepEqual(
            initCampaignFlowEditor.buildTestSendPayload('sms', '+15551234567', { message: 'Hi' }),
            { channel: 'sms', to: '+15551234567', message: 'Hi' }
        );
    });

    QUnit.test('buildTestSendPayload() builds a whatsapp payload from template_id/params, not message', function (assert) {
        const initCampaignFlowEditor = loadModule(MODULE_PATH);

        assert.deepEqual(
            initCampaignFlowEditor.buildTestSendPayload(
                'whatsapp',
                '+15551234567',
                { template_id: '3', params: 'John,ORD-1', message: 'ignored' }
            ),
            { channel: 'whatsapp', to: '+15551234567', template_id: '3', params: 'John,ORD-1' }
        );
    });

    QUnit.test('buildTestSendPayload() defaults missing fields to an empty string', function (assert) {
        const initCampaignFlowEditor = loadModule(MODULE_PATH);

        assert.deepEqual(
            initCampaignFlowEditor.buildTestSendPayload('email', 'jan@example.com', {}),
            { channel: 'email', to: 'jan@example.com', message: '' }
        );
        assert.deepEqual(
            initCampaignFlowEditor.buildTestSendPayload('whatsapp', '+15551234567', {}),
            { channel: 'whatsapp', to: '+15551234567', template_id: '', params: '' }
        );
    });
});

/**
 * Regression test: applyPaletteGroupSearch()/applyPaletteItemSearch() sit OUTSIDE the
 * define(['jquery'], ...) module closure (like every other top-level helper in this file), so
 * neither has a jQuery binding of its own - applyPaletteGroupSearch's own `$(this)` call threw
 * "TypeError: $ is not a function" in a real browser every single time a merchant typed into the
 * palette search box, confirmed live. This test's jsdom harness sets a global `$` (see
 * dom-env.js), which is exactly why that bug was invisible to every earlier test here - $ needs
 * to be passed in explicitly now (this test calls the function the same way the real caller
 * does post-fix, not relying on any global).
 */
QUnit.module('Ordo_Automation/js/campaign-flow-editor palette search', function () {
    QUnit.test('applyPaletteGroupSearch() shows only matching items and keeps the group open', function (assert) {
        const initCampaignFlowEditor = loadModule(
            MODULE_PATH,
            '<div class="ordo-flow-palette-group">'
                + '<div class="ordo-flow-palette-item" data-flow-type="send_email">Send Email</div>'
                + '<div class="ordo-flow-palette-item" data-flow-type="add_tag">Add Tag</div>'
                + '</div>'
        );
        const $group = global.$('.ordo-flow-palette-group');

        initCampaignFlowEditor.applyPaletteGroupSearch(global.$, $group, 'email', true);

        assert.notStrictEqual($group.find('[data-flow-type="send_email"]').css('display'), 'none');
        assert.strictEqual($group.find('[data-flow-type="add_tag"]').css('display'), 'none');
        assert.notStrictEqual($group.css('display'), 'none', 'the group itself stays open when it has a match');
    });

    QUnit.test('applyPaletteGroupSearch() hides the whole group when nothing inside it matches', function (assert) {
        const initCampaignFlowEditor = loadModule(
            MODULE_PATH,
            '<div class="ordo-flow-palette-group">'
                + '<div class="ordo-flow-palette-item" data-flow-type="add_tag">Add Tag</div>'
                + '</div>'
        );
        const $group = global.$('.ordo-flow-palette-group');

        initCampaignFlowEditor.applyPaletteGroupSearch(global.$, $group, 'nothing-matches-this', true);

        assert.strictEqual($group.css('display'), 'none');
    });

    QUnit.test('applyPaletteGroupSearch() shows every item again once the query is cleared', function (assert) {
        const initCampaignFlowEditor = loadModule(
            MODULE_PATH,
            '<div class="ordo-flow-palette-group">'
                + '<div class="ordo-flow-palette-item" data-flow-type="add_tag">Add Tag</div>'
                + '</div>'
        );
        const $group = global.$('.ordo-flow-palette-group');

        initCampaignFlowEditor.applyPaletteGroupSearch(global.$, $group, '', false);

        assert.notStrictEqual($group.css('display'), 'none');
        assert.notStrictEqual($group.find('[data-flow-type="add_tag"]').css('display'), 'none');
    });
});

/**
 * Drives initCampaignFlowEditor() itself end to end against the REAL vendored Drawflow library
 * (Test/js/support/amd-shim.js's own 'drawflow' case - not a stub), instead of only ever
 * exercising the pure helpers pulled out of it. window.ordoFlowTestHook.buildChain() is this
 * module's own MFTF test seam (see its docblock) - a real drag-and-drop is impossible to
 * simulate faithfully in jsdom (native HTML5 DataTransfer), so this uses the exact same
 * node-building primitives real drag-and-drop calls, just invoked directly.
 */
QUnit.module('Ordo_Automation/js/campaign-flow-editor initCampaignFlowEditor()', function () {
    const TYPES_CONFIG = {
        triggers: ['order_placed', 'tag_added'],
        conditions: ['tag', 'order_total_gte'],
        actions: ['add_tag', 'send_email', 'split'],
        labels: {
            trigger: { order_placed: 'Order Placed', tag_added: 'Tag Added' },
            condition: { tag: 'Has Tag', order_total_gte: 'Order Total At Least' },
            action: { add_tag: 'Add Tag', send_email: 'Send Email', split: 'Split Test' }
        },
        fields: {
            condition: {
                tag: [{ name: 'tag', label: 'Tag' }],
                order_total_gte: [{ name: 'amount', label: 'Amount' }]
            },
            action: {
                add_tag: [{ name: 'tag', label: 'Tag' }],
                send_email: [{ name: 'template', label: 'Template' }, { name: 'message', label: 'Message' }],
                split: [{ name: 'variants', label: 'Variants', type: 'variant_list' }]
            }
        }
    };

    function wrapperHtml() {
        return '<div class="ordo-flow-wrapper" data-flow-test-send-url="/ordo/templatetestsend/send">'
            + '<div class="ordo-flow-toolbar">'
            + '<button data-flow-action="undo"></button>'
            + '<button data-flow-action="redo"></button>'
            + '<button data-flow-action="apply">Apply</button>'
            + '</div>'
            + '<div id="canvas"></div>'
            + '</div>';
    }

    function initEditor(typesConfig, flowData) {
        const initCampaignFlowEditor = loadModule(MODULE_PATH, wrapperHtml());
        const container = global.document.getElementById('canvas');

        initCampaignFlowEditor(container, flowData || null, 'ordo_campaign_form.campaign_form_data_source', typesConfig || TYPES_CONFIG);

        return container;
    }

    function stubImmediateTimeout() {
        const original = global.setTimeout;

        global.setTimeout = function (callback) {
            callback();
            return 0;
        };

        return function restore() {
            global.setTimeout = original;
        };
    }

    QUnit.test('smoke test: constructs a real Drawflow instance and builds a chain via the test hook', function (assert) {
        initEditor();

        const nodeIds = global.window.ordoFlowTestHook.buildChain([
            { kind: 'trigger', type: 'order_placed' },
            { kind: 'condition', type: 'tag', fields: { tag: 'vip' } },
            { kind: 'action', type: 'add_tag', fields: { tag: 'thanked' } }
        ]);

        assert.strictEqual(nodeIds.length, 3);
        assert.strictEqual(global.$('.drawflow-node').length, 3);
    });

    QUnit.test('buildChain() fans multiple consecutive triggers into the same next node', function (assert) {
        initEditor();

        const nodeIds = global.window.ordoFlowTestHook.buildChain([
            { kind: 'trigger', type: 'order_placed' },
            { kind: 'trigger', type: 'tag_added' },
            { kind: 'action', type: 'add_tag', fields: { tag: 'thanked' } }
        ]);

        const exported = JSON.parse(JSON.stringify(global.$('#canvas').data('drawflow') || {}));
        // Both triggers' output_1 must connect to the action node - read back via the DOM's own
        // rendered connections instead (data-drawflow isn't how this library stores its model).
        assert.strictEqual(global.$('.drawflow .connection').length, 2);
        assert.strictEqual(nodeIds.length, 3);
    });

    // ------------------------------------------------------------------
    // renderFields(): per-type field rendering branches
    // ------------------------------------------------------------------

    QUnit.test('a mapped type renders one labeled input per field descriptor, pre-filled from its fields', function (assert) {
        initEditor();

        global.window.ordoFlowTestHook.buildChain([
            { kind: 'action', type: 'send_email', fields: { template: 'welcome', message: 'Hi there' } }
        ]);

        const $fields = global.$('.ordo-flow-fields');

        assert.strictEqual($fields.find('input[data-field="template"]').val(), 'welcome');
        assert.strictEqual($fields.find('input[data-field="message"]').val(), 'Hi there');
    });

    QUnit.test('a field descriptor with an "options" map renders a <select> instead of a text input', function (assert) {
        const typesConfig = JSON.parse(JSON.stringify(TYPES_CONFIG));

        typesConfig.actions.push('add_dynamic_content');
        typesConfig.labels.action.add_dynamic_content = 'Add Dynamic Content';
        typesConfig.fields.action.add_dynamic_content = [
            { name: 'content_block_id', label: 'Content Block', options: { 1: 'Block A', 2: 'Block B' } }
        ];
        initEditor(typesConfig);

        global.window.ordoFlowTestHook.buildChain([{ kind: 'action', type: 'add_dynamic_content' }]);

        const $select = global.$('.ordo-flow-fields select[data-field="content_block_id"]');

        assert.strictEqual($select.length, 1);
        assert.strictEqual($select.find('option').length, 2);
        assert.strictEqual($select.find('option').eq(0).text(), 'Block A');
    });

    QUnit.test('a field descriptor with a "notice" renders a hint under the input', function (assert) {
        const typesConfig = JSON.parse(JSON.stringify(TYPES_CONFIG));

        typesConfig.fields.action.send_email = [
            { name: 'message', label: 'Message', notice: 'Keep it short.' }
        ];
        initEditor(typesConfig);

        global.window.ordoFlowTestHook.buildChain([{ kind: 'action', type: 'send_email' }]);

        assert.strictEqual(global.$('.ordo-flow-field-notice').text(), 'Keep it short.');
    });

    QUnit.test('a variant_list field renders the variant editor widget with an optional notice', function (assert) {
        const typesConfig = JSON.parse(JSON.stringify(TYPES_CONFIG));

        typesConfig.fields.action.split = [
            { name: 'variants', label: 'Variants', type: 'variant_list', notice: 'A/B test' }
        ];
        initEditor(typesConfig);

        global.window.ordoFlowTestHook.buildChain([{ kind: 'action', type: 'split' }]);

        assert.strictEqual(global.$('.ordo-flow-variant-editor').length, 1);
        assert.strictEqual(global.$('[data-variant-editor="variants"] ~ .ordo-flow-field-notice, .ordo-flow-field-notice').text(), 'A/B test');

        // The widget is real (renderVariantEditor(), not a placeholder) - "+ Add variant" works.
        global.$('.ordo-flow-variant-add').trigger('click');
        assert.strictEqual(global.$('.ordo-flow-variant-block').length, 1);
    });

    QUnit.test('an unmapped condition/action type falls back to a raw JSON params textarea', function (assert) {
        const typesConfig = JSON.parse(JSON.stringify(TYPES_CONFIG));

        typesConfig.actions.push('custom_unmapped_action');
        initEditor(typesConfig);

        global.window.ordoFlowTestHook.buildChain([{ kind: 'action', type: 'custom_unmapped_action' }]);

        assert.strictEqual(global.$('.ordo-flow-params-textarea').length, 1);
    });

    QUnit.test('an unmapped trigger type gets no fallback textarea at all', function (assert) {
        initEditor(); // tag_added has no entry in TYPES_CONFIG.fields

        global.window.ordoFlowTestHook.buildChain([{ kind: 'trigger', type: 'tag_added' }]);

        assert.strictEqual(global.$('.ordo-flow-params-textarea').length, 0);
    });

    QUnit.test('changing a node\'s type re-renders its fields and toggles the "Send test" button', function (assert) {
        initEditor();

        global.window.ordoFlowTestHook.buildChain([{ kind: 'action', type: 'add_tag', fields: { tag: 'vip' } }]);

        // jQuery's :visible relies on real layout (offsetWidth/Height), which jsdom never
        // provides - .toggle()'s own inline display style is what actually reflects the toggle.
        assert.strictEqual(global.$('.ordo-flow-test-send').css('display'), 'none', 'add_tag is not testable');

        global.$('.ordo-flow-type-select').val('send_email').trigger('change');

        assert.strictEqual(global.$('.ordo-flow-fields input[data-field="template"]').length, 1, 'fields re-rendered for the new type');
        assert.notStrictEqual(global.$('.ordo-flow-test-send').css('display'), 'none', 'send_email is testable');
    });

    // ------------------------------------------------------------------
    // Loading an existing (already-saved) flow: editor.import() + bindNode() for pre-rendered nodes
    // ------------------------------------------------------------------

    function existingConditionFlowData(dataParamsAttr) {
        return {
            drawflow: {
                Home: {
                    data: {
                        1: {
                            id: 1,
                            name: 'ordo-flow-condition',
                            data: {},
                            class: 'ordo-flow-condition',
                            html: '<div class="ordo-flow-node" data-kind="condition" data-params=\'' + dataParamsAttr + '\'>'
                                + '<div class="ordo-flow-node-head"><span>Condition</span>'
                                + '<button type="button" class="ordo-flow-duplicate">&#10697;</button>'
                                + '<button type="button" class="ordo-flow-delete">&times;</button></div>'
                                + '<select class="ordo-flow-type-select">'
                                + '<option value="tag" selected="selected">Has Tag</option>'
                                + '<option value="order_total_gte">Order Total At Least</option>'
                                + '</select>'
                                + '<div class="ordo-flow-fields"></div>'
                                + '</div>',
                            typenode: false,
                            inputs: { input_1: { connections: [] } },
                            outputs: { output_1: { connections: [] } },
                            pos_x: 60,
                            pos_y: 260
                        }
                    }
                }
            }
        };
    }

    QUnit.test('loading an existing flow imports it and binds fields from each node\'s saved data-params', function (assert) {
        initEditor(TYPES_CONFIG, existingConditionFlowData('{&quot;tag&quot;:&quot;vip&quot;}'));

        assert.strictEqual(global.$('.drawflow-node').length, 1);
        assert.strictEqual(global.$('.ordo-flow-fields input[data-field="tag"]').val(), 'vip');
    });

    QUnit.test('a node with corrupted saved data-params starts with blank fields instead of throwing', function (assert) {
        initEditor(TYPES_CONFIG, existingConditionFlowData('not valid json'));

        assert.strictEqual(global.$('.drawflow-node').length, 1);
        assert.strictEqual(global.$('.ordo-flow-fields input[data-field="tag"]').val(), '');
    });

    // ------------------------------------------------------------------
    // duplicateNode() / delete
    // ------------------------------------------------------------------

    QUnit.test('"Duplicate" copies a node\'s kind/type/current field values into a new, offset node', function (assert) {
        initEditor();

        global.window.ordoFlowTestHook.buildChain([{ kind: 'action', type: 'add_tag', fields: { tag: 'vip' } }]);
        global.$('.ordo-flow-fields input[data-field="tag"]').val('edited-live');

        global.$('.ordo-flow-duplicate').trigger('click');

        assert.strictEqual(global.$('.drawflow-node').length, 2);
        assert.strictEqual(global.$('.ordo-flow-fields input[data-field="tag"]').eq(1).val(), 'edited-live');
    });

    QUnit.test('"Duplicate" is a no-op when the node has no recognizable kind/type', function (assert) {
        initEditor();

        // A node with no [data-kind] inner element at all (malformed/foreign markup) - kind/type
        // both resolve to undefined, hitting duplicateNode()'s own early-return guard.
        global.$('#canvas').append(
            '<div id="node-999" class="drawflow-node"><button type="button" class="ordo-flow-duplicate"></button></div>'
        );

        global.$('#node-999 .ordo-flow-duplicate').trigger('click');

        assert.strictEqual(global.$('.drawflow-node').length, 1);
    });

    QUnit.test('"Remove" deletes the node from the canvas', function (assert) {
        initEditor();

        global.window.ordoFlowTestHook.buildChain([{ kind: 'action', type: 'add_tag' }]);
        assert.strictEqual(global.$('.drawflow-node').length, 1);

        global.$('.ordo-flow-delete').trigger('click');

        assert.strictEqual(global.$('.drawflow-node').length, 0);
    });

    // ------------------------------------------------------------------
    // Inline "Send test"
    // ------------------------------------------------------------------

    function stubFetch(responseInit) {
        const calls = [];

        global.fetch = function () {
            calls.push(Array.from(arguments));
            return Promise.resolve(responseInit);
        };

        return calls;
    }

    // A REAL setTimeout(0), not stubImmediateTimeout()'s synchronous one - sendInlineTest()'s own
    // fetch().then().then().catch().finally() chain needs actual microtask draining to complete,
    // which only a genuine macrotask boundary guarantees (same technique as
    // Test/js/free-gift-offer-form.test.js's own flushPromises()).
    function flushPromises() {
        return new Promise(function (resolve) {
            setTimeout(resolve, 0);
        });
    }

    QUnit.test('"Send test" does nothing when the action type isn\'t testable', function (assert) {
        initEditor();
        global.window.ordoFlowTestHook.buildChain([{ kind: 'action', type: 'add_tag' }]);
        const calls = stubFetch({ ok: true, json: () => Promise.resolve({ success: true }) });
        global.window.prompt = function () { return 'jan@example.com'; };

        global.$('.ordo-flow-test-send').trigger('click');

        assert.strictEqual(calls.length, 0);
    });

    QUnit.test('"Send test" does nothing when the prompt is cancelled', function (assert) {
        initEditor();
        global.window.ordoFlowTestHook.buildChain([{ kind: 'action', type: 'send_email', fields: { message: 'Hi' } }]);
        const calls = stubFetch({ ok: true, json: () => Promise.resolve({ success: true }) });
        global.window.prompt = function () { return null; };

        global.$('.ordo-flow-test-send').trigger('click');

        assert.strictEqual(calls.length, 0);
    });

    QUnit.test('"Send test" posts the built payload and renders a success message', async function (assert) {
        initEditor();
        global.window.ordoFlowTestHook.buildChain([{ kind: 'action', type: 'send_email', fields: { message: 'Hi' } }]);
        const calls = stubFetch({ ok: true, json: () => Promise.resolve({ success: true, message: 'Sent!' }) });
        global.window.prompt = function () { return 'jan@example.com'; };

        global.$('.ordo-flow-test-send').trigger('click');
        await flushPromises();

        assert.strictEqual(calls.length, 1);
        assert.strictEqual(calls[0][1].method, 'POST');
        const body = new URLSearchParams(calls[0][1].body);
        assert.strictEqual(body.get('channel'), 'email');
        assert.strictEqual(body.get('to'), 'jan@example.com');
        assert.strictEqual(global.$('.ordo-flow-test-send-result').text(), 'Sent!');
        assert.true(global.$('.ordo-flow-test-send-result').hasClass('ordo-flow-test-send-result-success'));
        assert.strictEqual(global.$('.ordo-flow-test-send').prop('disabled'), false);
    });

    QUnit.test('"Send test" renders the server-provided error message on a failed send', async function (assert) {
        initEditor();
        global.window.ordoFlowTestHook.buildChain([{ kind: 'action', type: 'send_email', fields: { message: 'Hi' } }]);
        stubFetch({ ok: true, json: () => Promise.resolve({ success: false, message: 'Bad template' }) });
        global.window.prompt = function () { return 'jan@example.com'; };

        global.$('.ordo-flow-test-send').trigger('click');
        await flushPromises();

        assert.strictEqual(global.$('.ordo-flow-test-send-result').text(), 'Bad template');
        assert.true(global.$('.ordo-flow-test-send-result').hasClass('ordo-flow-test-send-result-error'));
    });

    QUnit.test('"Send test" shows a generic error message on a non-ok response with no body', async function (assert) {
        initEditor();
        global.window.ordoFlowTestHook.buildChain([{ kind: 'action', type: 'send_email', fields: { message: 'Hi' } }]);
        stubFetch({ ok: false });
        global.window.prompt = function () { return 'jan@example.com'; };

        global.$('.ordo-flow-test-send').trigger('click');
        await flushPromises();

        assert.strictEqual(global.$('.ordo-flow-test-send-result').text(), 'The test send failed.');
    });

    QUnit.test('"Send test" shows a generic error message on a network failure instead of throwing', async function (assert) {
        initEditor();
        global.window.ordoFlowTestHook.buildChain([{ kind: 'action', type: 'send_email', fields: { message: 'Hi' } }]);
        global.fetch = function () { return Promise.reject(new Error('network down')); };
        global.window.prompt = function () { return 'jan@example.com'; };

        global.$('.ordo-flow-test-send').trigger('click');
        await flushPromises();

        assert.strictEqual(global.$('.ordo-flow-test-send-result').text(), 'The test send failed.');
        assert.true(global.$('.ordo-flow-test-send-result').hasClass('ordo-flow-test-send-result-error'));
    });

    QUnit.test('"Send test" builds a whatsapp payload from template_id/params, not message', async function (assert) {
        const typesConfig = JSON.parse(JSON.stringify(TYPES_CONFIG));

        typesConfig.actions.push('send_whatsapp');
        typesConfig.labels.action.send_whatsapp = 'Send WhatsApp';
        typesConfig.fields.action.send_whatsapp = [
            { name: 'template_id', label: 'Template' }, { name: 'params', label: 'Params' }
        ];
        initEditor(typesConfig);
        global.window.ordoFlowTestHook.buildChain([
            { kind: 'action', type: 'send_whatsapp', fields: { template_id: '3', params: 'John' } }
        ]);
        const calls = stubFetch({ ok: true, json: () => Promise.resolve({ success: true, message: 'Sent!' }) });
        global.window.prompt = function () { return '+15551234567'; };

        global.$('.ordo-flow-test-send').trigger('click');
        await flushPromises();

        const body = new URLSearchParams(calls[0][1].body);
        assert.strictEqual(body.get('channel'), 'whatsapp');
        assert.strictEqual(body.get('template_id'), '3');
        assert.strictEqual(body.get('params'), 'John');
    });

    // ------------------------------------------------------------------
    // Palette search input (delegated to document)
    // ------------------------------------------------------------------

    QUnit.test('typing in the palette search box filters items and groups exactly like applyPaletteGroupSearch()', function (assert) {
        initEditor();
        global.$('#canvas').closest('.ordo-flow-wrapper').append(
            '<div class="ordo-flow-palette">'
                + '<input type="text" class="ordo-flow-palette-search">'
                + '<div class="ordo-flow-palette-group">'
                + '<div class="ordo-flow-palette-item" data-flow-type="add_tag">Add Tag</div>'
                + '<div class="ordo-flow-palette-item" data-flow-type="send_email">Send Email</div>'
                + '</div>'
                + '</div>'
        );

        global.$('.ordo-flow-palette-search').val('email').trigger('input');

        assert.strictEqual(global.$('[data-flow-type="add_tag"]').css('display'), 'none');
        assert.notStrictEqual(global.$('[data-flow-type="send_email"]').css('display'), 'none');
    });

    // ------------------------------------------------------------------
    // Palette drag-and-drop
    // ------------------------------------------------------------------

    function fakeDataTransfer(initialPayload) {
        var stored = initialPayload || null;

        return {
            setData: function (format, value) { stored = value; },
            getData: function () { return stored; },
            effectAllowed: null,
            dropEffect: null
        };
    }

    QUnit.test('dragstart on a palette chip sets the kind/type as the drag payload', function (assert) {
        initEditor();
        global.$(global.document.body).append(
            '<div class="ordo-flow-palette-item" data-flow-kind="action" data-flow-type="add_tag">Add Tag</div>'
        );
        const dataTransfer = fakeDataTransfer();

        global.$('.ordo-flow-palette-item').trigger(
            global.$.Event('dragstart', { originalEvent: { dataTransfer: dataTransfer } })
        );

        assert.deepEqual(JSON.parse(dataTransfer.getData()), { kind: 'action', type: 'add_tag' });
        assert.strictEqual(dataTransfer.effectAllowed, 'copy');
    });

    QUnit.test('dragover over the canvas allows the drop', function (assert) {
        initEditor();
        const dataTransfer = fakeDataTransfer();
        let prevented = false;

        global.$('#canvas').trigger(global.$.Event('dragover', {
            originalEvent: { dataTransfer: dataTransfer, preventDefault: function () { prevented = true; } },
            preventDefault: function () { prevented = true; }
        }));

        assert.strictEqual(dataTransfer.dropEffect, 'copy');
        assert.true(prevented);
    });

    QUnit.test('dropping a palette payload on the canvas adds a node of that kind/type', function (assert) {
        initEditor();
        const dataTransfer = fakeDataTransfer(JSON.stringify({ kind: 'action', type: 'add_tag' }));

        global.$('#canvas').trigger(global.$.Event('drop', {
            originalEvent: { dataTransfer: dataTransfer, clientX: 100, clientY: 100 },
            preventDefault: function () {}
        }));

        assert.strictEqual(global.$('.drawflow-node').length, 1);
        assert.strictEqual(global.$('.ordo-flow-type-select').val(), 'add_tag');
    });

    QUnit.test('dropping malformed JSON is silently ignored instead of throwing', function (assert) {
        initEditor();
        const dataTransfer = fakeDataTransfer('not valid json');

        global.$('#canvas').trigger(global.$.Event('drop', {
            originalEvent: { dataTransfer: dataTransfer, clientX: 0, clientY: 0 },
            preventDefault: function () {}
        }));

        assert.strictEqual(global.$('.drawflow-node').length, 0);
    });

    QUnit.test('dropping a payload with no kind is silently ignored', function (assert) {
        initEditor();
        const dataTransfer = fakeDataTransfer(JSON.stringify({ foo: 'bar' }));

        global.$('#canvas').trigger(global.$.Event('drop', {
            originalEvent: { dataTransfer: dataTransfer, clientX: 0, clientY: 0 },
            preventDefault: function () {}
        }));

        assert.strictEqual(global.$('.drawflow-node').length, 0);
    });

    // ------------------------------------------------------------------
    // Flow templates
    // ------------------------------------------------------------------

    QUnit.test('clicking a flow template chip builds and wires its whole node chain', function (assert) {
        initEditor();
        global.$(global.document.body).append(
            '<details class="ordo-flow-templates" open><button data-flow-template="abandoned_cart"></button></details>'
        );

        global.$('[data-flow-template="abandoned_cart"]').trigger('click');

        assert.strictEqual(global.$('.drawflow-node').length, 2);
        assert.strictEqual(global.$('.drawflow .connection').length, 1);
        assert.strictEqual(global.$('.ordo-flow-templates').attr('open'), undefined, 'the details element closes itself');
    });

    QUnit.test('an unknown template key is a no-op', function (assert) {
        initEditor();
        global.$(global.document.body).append('<button data-flow-template="does-not-exist"></button>');

        global.$('[data-flow-template="does-not-exist"]').trigger('click');

        assert.strictEqual(global.$('.drawflow-node').length, 0);
    });

    // ------------------------------------------------------------------
    // validateFlow() + the Apply button
    // ------------------------------------------------------------------

    QUnit.test('Apply reports an error and highlights nothing when the canvas is completely empty', function (assert) {
        initEditor();

        global.$('[data-flow-action="apply"]').trigger('click');

        const $error = global.$('.ordo-flow-error');
        assert.notStrictEqual($error.css('display'), 'none');
        assert.true($error.text().includes('at least one Trigger'));
        assert.true($error.text().includes('at least one Action'));
    });

    QUnit.test('Apply reports an error when the flow has a trigger but no action', function (assert) {
        initEditor();
        global.window.ordoFlowTestHook.buildChain([{ kind: 'trigger', type: 'order_placed' }]);

        global.$('[data-flow-action="apply"]').trigger('click');

        assert.true(global.$('.ordo-flow-error').text().includes('at least one Action'));
    });

    QUnit.test('Apply highlights a condition node with no outgoing connection as a dead end', function (assert) {
        initEditor();
        const nodeIds = global.window.ordoFlowTestHook.buildChain([
            { kind: 'trigger', type: 'order_placed' },
            { kind: 'condition', type: 'tag', fields: { tag: 'vip' } }
        ]);

        global.$('[data-flow-action="apply"]').trigger('click');

        const $error = global.$('.ordo-flow-error');
        assert.notStrictEqual($error.css('display'), 'none');
        assert.true($error.text().includes('disconnected or a condition'));
        assert.true(global.$('#node-' + nodeIds[1]).hasClass('ordo-flow-node-error'));
    });

    QUnit.test('Apply highlights two trigger chains that never connect to each other', function (assert) {
        initEditor();
        global.window.ordoFlowTestHook.buildChain([
            { kind: 'trigger', type: 'order_placed' },
            { kind: 'action', type: 'add_tag' }
        ]);
        global.window.ordoFlowTestHook.buildChain([
            { kind: 'trigger', type: 'tag_added' },
            { kind: 'action', type: 'add_tag' }
        ]);

        global.$('[data-flow-action="apply"]').trigger('click');

        assert.true(global.$('.ordo-flow-error').text().includes('more than one Trigger chain'));
        assert.strictEqual(global.$('.ordo-flow-node-error').length, 2, 'the whole second, disconnected chain is flagged');
    });

    QUnit.test('Apply flags a totally isolated action node with no trigger and no connections at all', function (assert) {
        initEditor();
        global.window.ordoFlowTestHook.buildChain([
            { kind: 'trigger', type: 'order_placed' },
            { kind: 'action', type: 'add_tag' }
        ]);
        // A second, SEPARATE buildChain() call for a single action with no trigger of its own and
        // no connection to anything - unlike the "two trigger chains" case above (each node there
        // is still reachable from ITS OWN trigger via the BFS, just not from the campaign's
        // primary one), this one is never reachable from ANY trigger at all.
        const loneIds = global.window.ordoFlowTestHook.buildChain([
            { kind: 'action', type: 'send_email', fields: { message: 'orphan' } }
        ]);

        global.$('[data-flow-action="apply"]').trigger('click');

        assert.true(global.$('#node-' + loneIds[0]).hasClass('ordo-flow-node-error'));
    });

    function danglingConnectionFlowData() {
        return {
            drawflow: {
                Home: {
                    data: {
                        1: {
                            id: 1,
                            name: 'ordo-flow-trigger',
                            data: {},
                            class: 'ordo-flow-trigger',
                            html: '<div class="ordo-flow-node" data-kind="trigger" data-params="{}">'
                                + '<div class="ordo-flow-node-head"><span>Trigger</span></div>'
                                + '<select class="ordo-flow-type-select">'
                                + '<option value="order_placed" selected="selected">Order Placed</option>'
                                + '</select>'
                                + '<div class="ordo-flow-fields"></div></div>',
                            typenode: false,
                            inputs: {},
                            // Points at node id '999', which has no entry in `data` at all - the
                            // shape validateFlow()'s own reachability BFS defends against with its
                            // `if (!currentNode) continue;` guard (real Drawflow keeps connections
                            // in sync on removeNodeId() in normal use; this simulates whatever
                            // edge case that guard was written for, e.g. a hand-edited/corrupted
                            // saved flow).
                            outputs: { output_1: { connections: [{ node: '999', output: 'input_1' }] } },
                            pos_x: 60,
                            pos_y: 60
                        }
                    }
                }
            }
        };
    }

    QUnit.test('Apply tolerates a dangling connection to a node id that no longer exists', function (assert) {
        initEditor(TYPES_CONFIG, danglingConnectionFlowData());

        assert.strictEqual(global.$('.drawflow-node').length, 1);

        global.$('[data-flow-action="apply"]').trigger('click');

        // Doesn't throw walking the dangling reference; still correctly reports the flow as
        // incomplete (no action anywhere) rather than crashing on it.
        assert.true(global.$('.ordo-flow-error').text().includes('at least one Action'));
    });

    QUnit.test('a valid flow clears any previous error and saves through the registered form provider', function (assert) {
        const providerCalls = [];

        global.__uiRegistryProviders = {
            'ordo_campaign_form.campaign_form_data_source': {
                set: function (path, value) { providerCalls.push([path, value]); },
                save: function () { providerCalls.push(['save']); }
            }
        };

        try {
            initEditor();
            // First produce a real error, then fix it - proves clearFlowError() actually runs on
            // the successful second Apply, not just that no error was ever shown.
            global.$('[data-flow-action="apply"]').trigger('click');
            assert.notStrictEqual(global.$('.ordo-flow-error').css('display'), 'none');

            global.window.ordoFlowTestHook.buildChain([
                { kind: 'trigger', type: 'order_placed' },
                { kind: 'condition', type: 'tag', fields: { tag: 'vip' } },
                { kind: 'action', type: 'add_tag', fields: { tag: 'thanked' } }
            ]);

            global.$('[data-flow-action="apply"]').trigger('click');

            assert.strictEqual(global.$('.ordo-flow-error').css('display'), 'none');
            assert.strictEqual(global.$('.drawflow-node.ordo-flow-node-error').length, 0);
            assert.strictEqual(global.$('[data-flow-action="apply"]').text(), 'Saving…');

            const setTriggers = providerCalls.find(function (call) { return call[0] === 'data.triggers'; });
            const setConditions = providerCalls.find(function (call) { return call[0] === 'data.conditions'; });
            const setActions = providerCalls.find(function (call) { return call[0] === 'data.actions'; });

            assert.deepEqual(setTriggers[1], { triggers: [{ trigger_event: 'order_placed' }] });
            assert.deepEqual(setConditions[1], { conditions: [{ type: 'tag', tag: 'vip' }] });
            assert.deepEqual(setActions[1], { actions: [{ type: 'add_tag', tag: 'thanked', delay_minutes: '0' }] });
            assert.deepEqual(providerCalls[providerCalls.length - 1], ['save']);
        } finally {
            delete global.__uiRegistryProviders;
        }
    });

    // ------------------------------------------------------------------
    // Undo / redo (toolbar buttons + keyboard shortcuts)
    // ------------------------------------------------------------------

    QUnit.test('undo/redo buttons start disabled and enable once there is history to move through', function (assert) {
        initEditor();

        assert.strictEqual(global.$('[data-flow-action="undo"]').prop('disabled'), true);
        assert.strictEqual(global.$('[data-flow-action="redo"]').prop('disabled'), true);

        const restore = stubImmediateTimeout();
        try {
            global.window.ordoFlowTestHook.buildChain([{ kind: 'action', type: 'add_tag' }]);
        } finally {
            restore();
        }

        assert.strictEqual(global.$('[data-flow-action="undo"]').prop('disabled'), false);
        assert.strictEqual(global.$('[data-flow-action="redo"]').prop('disabled'), true);
    });

    QUnit.test('clicking Undo then Redo restores the canvas to each snapshot', function (assert) {
        initEditor();
        const restore = stubImmediateTimeout();

        try {
            global.window.ordoFlowTestHook.buildChain([{ kind: 'action', type: 'add_tag' }]);
            assert.strictEqual(global.$('.drawflow-node').length, 1);

            global.window.ordoFlowTestHook.buildChain([{ kind: 'action', type: 'send_email' }]);
            assert.strictEqual(global.$('.drawflow-node').length, 2);

            global.$('[data-flow-action="undo"]').trigger('click');
            assert.strictEqual(global.$('.drawflow-node').length, 1, 'back to one node after undo');

            global.$('[data-flow-action="redo"]').trigger('click');
            assert.strictEqual(global.$('.drawflow-node').length, 2, 'back to two nodes after redo');
        } finally {
            restore();
        }
    });

    QUnit.test('Ctrl+Z / Ctrl+Shift+Z inside the flow wrapper undo/redo the same as the toolbar buttons', function (assert) {
        initEditor();
        const restore = stubImmediateTimeout();

        try {
            global.window.ordoFlowTestHook.buildChain([{ kind: 'action', type: 'add_tag' }]);
            global.window.ordoFlowTestHook.buildChain([{ kind: 'action', type: 'send_email' }]);

            global.$(global.document).trigger(global.$.Event('keydown', {
                ctrlKey: true, key: 'z', target: global.$('#canvas')[0]
            }));
            assert.strictEqual(global.$('.drawflow-node').length, 1, 'Ctrl+Z undoes');

            global.$(global.document).trigger(global.$.Event('keydown', {
                ctrlKey: true, shiftKey: true, key: 'z', target: global.$('#canvas')[0]
            }));
            assert.strictEqual(global.$('.drawflow-node').length, 2, 'Ctrl+Shift+Z redoes');
        } finally {
            restore();
        }
    });

    QUnit.test('Ctrl+Z outside the flow wrapper is ignored', function (assert) {
        initEditor();
        const restore = stubImmediateTimeout();

        try {
            global.window.ordoFlowTestHook.buildChain([{ kind: 'action', type: 'add_tag' }]);
            global.window.ordoFlowTestHook.buildChain([{ kind: 'action', type: 'send_email' }]);

            global.$(global.document.body).append('<input id="unrelated-field">');
            global.$(global.document).trigger(global.$.Event('keydown', {
                ctrlKey: true, key: 'z', target: global.$('#unrelated-field')[0]
            }));

            assert.strictEqual(global.$('.drawflow-node').length, 2, 'nothing was undone');
        } finally {
            restore();
        }
    });

    QUnit.test('a plain "z" keypress with no modifier key is ignored', function (assert) {
        initEditor();
        const restore = stubImmediateTimeout();

        try {
            global.window.ordoFlowTestHook.buildChain([{ kind: 'action', type: 'add_tag' }]);
            global.window.ordoFlowTestHook.buildChain([{ kind: 'action', type: 'send_email' }]);

            global.$(global.document).trigger(global.$.Event('keydown', {
                key: 'z', target: global.$('#canvas')[0]
            }));

            assert.strictEqual(global.$('.drawflow-node').length, 2);
        } finally {
            restore();
        }
    });

    QUnit.test('clicking Undo with nothing in history yet is a no-op', function (assert) {
        initEditor();

        // The button starts real-DOM `disabled`, and jQuery's trigger('click') on a form control
        // dispatches via the native click() method - same as a real browser, jsdom refuses to fire
        // it at all while disabled, so the delegated handler (and undo()'s own historyIndex guard)
        // would never run. Force-enabling first is what lets this test actually exercise the
        // guard itself, rather than only ever proving the disabled attribute prevents a click.
        global.$('[data-flow-action="undo"]').prop('disabled', false).trigger('click');

        assert.strictEqual(global.$('.drawflow-node').length, 0);
    });

    QUnit.test('clicking Redo already at the newest snapshot is a no-op', function (assert) {
        initEditor();
        const restore = stubImmediateTimeout();

        try {
            global.window.ordoFlowTestHook.buildChain([{ kind: 'action', type: 'add_tag' }]);

            // See the Undo test above for why this needs a force-enable first.
            global.$('[data-flow-action="redo"]').prop('disabled', false).trigger('click');

            assert.strictEqual(global.$('.drawflow-node').length, 1);
        } finally {
            restore();
        }
    });

    QUnit.test('history is capped at 50 entries, so undoing past the limit stops at the oldest surviving snapshot', function (assert) {
        initEditor();
        const restore = stubImmediateTimeout();

        try {
            // 56 total pushes get made (the initial empty-canvas snapshot, plus one per node add
            // below): with a 50-entry cap, the oldest 6 (the empty canvas plus the first 5 node
            // adds) get trimmed off, so the earliest state still reachable via undo is 6 nodes.
            for (let i = 0; i < 55; i++) {
                global.window.ordoFlowTestHook.buildChain([{ kind: 'action', type: 'add_tag' }]);
            }
            assert.strictEqual(global.$('.drawflow-node').length, 55, 'sanity check: all 55 nodes were added');

            for (let i = 0; i < 49; i++) {
                global.$('[data-flow-action="undo"]').trigger('click');
            }

            assert.strictEqual(
                global.$('.drawflow-node').length,
                6,
                'undo stops at the oldest surviving (trimmed) snapshot instead of the true first state'
            );
        } finally {
            restore();
        }
    });

    // ------------------------------------------------------------------
    // collectRows()
    // ------------------------------------------------------------------

    QUnit.test('Apply skips a row whose type select has no value instead of collecting a typeless row', function (assert) {
        const providerCalls = [];

        global.__uiRegistryProviders = {
            'ordo_campaign_form.campaign_form_data_source': {
                set: function (path, value) { providerCalls.push([path, value]); },
                save: function () {}
            }
        };

        try {
            initEditor();
            global.window.ordoFlowTestHook.buildChain([
                { kind: 'trigger', type: 'order_placed' },
                { kind: 'action', type: 'add_tag', fields: { tag: 'vip' } },
                { kind: 'action', type: 'send_email', fields: { message: 'Hi' } }
            ]);
            // Simulates a select left blank (e.g. every <option> removed server-side for a type
            // that no longer exists) - collectRows() must skip it rather than collect a
            // meaningless {type: ''} row.
            global.$('.ordo-flow-type-select').eq(1).val('');

            global.$('[data-flow-action="apply"]').trigger('click');

            const setActions = providerCalls.find(function (call) { return call[0] === 'data.actions'; });
            assert.strictEqual(setActions[1].actions.length, 1);
            assert.strictEqual(setActions[1].actions[0].type, 'send_email');
        } finally {
            delete global.__uiRegistryProviders;
        }
    });
});
