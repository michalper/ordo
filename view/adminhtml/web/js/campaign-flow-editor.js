/**
 * Editable Drawflow (https://github.com/jerosoler/Drawflow) canvas for the campaign edit page.
 * Nodes carry a type <select> plus labeled inputs for whatever fields that type actually has
 * (typesConfig.fields, built server-side in Block\Adminhtml\Campaign\Edit\Flow::getFieldsConfig()
 * — the same mapping ordo_campaign_form.xml's switcherConfig encodes) — no raw JSON textarea for
 * a mapped type, since someone who doesn't know what JSON is should never have to see one just
 * to fill in a tag or an amount. "Apply flow to form" reads the whole graph back out and hands
 * it to the SAME provider component the native dynamicRows form already submits through — this
 * module never talks to ordo/campaign/save itself, it only fills in provider.data.conditions/
 * actions before calling the provider's own, unmodified save().
 */
/**
 * Union all of one node's outgoing connections into the shared connectivity groups -
 * buildConnectivityGroups()'s own per-node work, pulled out to a top-level function (rather than
 * nested two forEach()s deep inside buildConnectivityGroups' own forEach) purely to keep the
 * function-nesting depth within Sonar's 5-level limit; no behavior change.
 *
 * @param {String} id
 * @param {Object} node
 * @param {function(String, String): void} union
 */
function unionNodeOutputConnections(id, node, union) {
    Object.keys(node.outputs || {}).forEach(function (outputKey) {
        node.outputs[outputKey].connections.forEach(function (connection) {
            union(id, connection.node);
        });
    });
}

/**
 * Every node id whose connectivity group root differs from the primary (first trigger's) root -
 * validateFlow()'s own disconnected-chain check, pulled out to a top-level function for the same
 * nesting-depth reason as unionNodeOutputConnections() above.
 *
 * @param {Object} exported
 * @param {{find: function(String): String}} groups
 * @param {String} primaryRoot
 * @return {String[]}
 */
function findDisconnectedNodeIds(exported, groups, primaryRoot) {
    return Object.keys(exported).filter(function (id) {
        return groups.find(id) !== primaryRoot;
    });
}

/**
 * Normalizes one raw variant entry (from a split action's own `params.variants`, or a freshly
 * added blank one) into the shape the variant editor widget always works with - pulled out to a
 * top-level pure function (no jQuery/DOM) so it's independently testable, same reasoning as
 * unionNodeOutputConnections()/findDisconnectedNodeIds() above.
 *
 * @param {Object} raw
 * @return {{key: String, weight: Number, actions: Array<{type: String, params: Object}>}}
 */
function cloneSplitVariant(raw) {
    var source = raw && typeof raw === 'object' ? raw : {};

    return {
        key: typeof source.key === 'string' ? source.key : '',
        weight: typeof source.weight === 'number' ? source.weight : 0,
        actions: Array.isArray(source.actions) ? source.actions.map(cloneSplitVariantAction) : []
    };
}

/**
 * @param {Object} raw
 * @return {{type: String, params: Object}}
 */
function cloneSplitVariantAction(raw) {
    var source = raw && typeof raw === 'object' ? raw : {};

    return {
        type: typeof source.type === 'string' ? source.type : '',
        params: source.params && typeof source.params === 'object' ? source.params : {}
    };
}

/**
 * <option> markup for a variant's own nested action-type <select> - every real action type
 * except 'split' itself (a split inside a split isn't supported - CampaignDispatcher::runSplit()
 * builds its variant actions as synthetic, non-persisted rows with no entity_id of their own, so
 * there's nothing a nested split could key its own variant assignment off).
 *
 * @param {Array<String>} actionTypes
 * @param {Object<String, String>} actionLabels
 * @param {String} selectedType
 * @param {function(String): String} escapeHtmlFn
 * @return {String}
 */
function buildSplitVariantActionTypeOptionsHtml(actionTypes, actionLabels, selectedType, escapeHtmlFn) {
    return (actionTypes || [])
        .filter(function (type) {
            return type !== 'split';
        })
        .map(function (type) {
            var selectedAttr = type === selectedType ? ' selected="selected"' : '';
            return '<option value="' + type + '"' + selectedAttr + '>' +
                escapeHtmlFn(actionLabels[type] || type) + '</option>';
        })
        .join('');
}

/**
 * Applies one buildChain() node's `fields` onto its own data-field inputs - pulled out of
 * buildChain()'s per-node forEach() (itself inside window.ordoFlowTestHook's own function, inside
 * the build() IIFE) purely to keep that callback's nesting depth within the linter's limit, same
 * reasoning as unionNodeOutputConnections()/findDisconnectedNodeIds() above; no behavior change.
 *
 * @param {jQuery} $node
 * @param {Object<String, String>} fields
 */
function applyBuildChainNodeFields($node, fields) {
    Object.keys(fields || {}).forEach(function (fieldName) {
        $node.find('[data-field="' + fieldName + '"]').val(fields[fieldName]).trigger('change');
    });
}

/**
 * Fans every pending trigger id into nodeId (output_1 -> input_1 on each) - pulled out of
 * buildChain()'s per-node forEach() for the same nesting-depth reason as
 * applyBuildChainNodeFields() above; no behavior change.
 *
 * @param {Object} editor Drawflow instance
 * @param {Array<String>} pendingTriggerIds
 * @param {String} nodeId
 */
function connectPendingTriggers(editor, pendingTriggerIds, nodeId) {
    pendingTriggerIds.forEach(function (triggerId) {
        editor.addConnection(triggerId, nodeId, 'output_1', 'input_1');
    });
}

define([
    'jquery',
    'uiRegistry',
    'drawflow',
    'domReady!'
], function ($, registry, Drawflow) {
    'use strict';

    /**
     * @param {String} raw
     * @return {String}
     */
    function escapeHtml(raw) {
        return $('<div>').text(raw).html();
    }

    /**
     * One variant's own action row inside the variant editor widget - a type <select> (every
     * real action type except 'split') plus a JSON textarea for that action's params.
     * Deliberately the "advanced JSON" shape (not the full per-type dedicated-field rendering
     * renderFields() itself gives top-level condition/action nodes) - reusing that here would
     * mean these nested inputs also carry `data-field` attributes, which collectNodeFields()'s
     * `$node.find('[data-field]')` (a DEEP descendant search) would then incorrectly fold into
     * the SPLIT action's own row alongside its real fields. Keeping nested-action state out of
     * `[data-field]` entirely and only ever serializing it into the split's own single hidden
     * `data-field="variants"` input (see renderVariantEditor() below) avoids that collision by
     * construction.
     *
     * Module-level (a sibling of initCampaignFlowEditor, not nested inside it) rather than a
     * closure over a live `typesConfig` - takes it as an explicit parameter instead, same
     * reasoning as buildSplitVariantActionTypeOptionsHtml() above: independently testable
     * without needing a real Drawflow instance (initCampaignFlowEditor()'s own body constructs
     * one for real, which Test/js/support's stub Drawflow can't stand in for).
     *
     * @param {Object} action {type, params}
     * @param {Object} typesConfig
     * @param {function(): void} onChange
     * @param {function(): void} onRemove
     * @return {jQuery}
     */
    function renderVariantActionRow(action, typesConfig, onChange, onRemove) {
        var $typeSelect = $('<select class="ordo-flow-variant-action-type"></select>')
                .html(buildSplitVariantActionTypeOptionsHtml(
                    typesConfig.actions,
                    typesConfig.labels.action || {},
                    action.type,
                    escapeHtml
                ))
                .on('change', function () {
                    action.type = $(this).val();
                    onChange();
                }),
            $paramsTextarea = $('<textarea class="ordo-flow-variant-action-params" placeholder="Params (JSON)"></textarea>')
                .val(Object.keys(action.params).length ? JSON.stringify(action.params) : '')
                .on('input', function () {
                    var raw = $(this).val().trim();
                    try {
                        // Only a successfully-parsed object replaces the in-memory params -
                        // invalid/mid-edit JSON is left as whatever it last validly was, rather
                        // than corrupting the model collectRows()/save() reads from.
                        action.params = raw ? JSON.parse(raw) : {};
                    } catch (e) {
                        return;
                    }
                    onChange();
                }),
            $removeButton = $('<button type="button" class="ordo-flow-variant-action-remove" title="Remove action">&times;</button>')
                .on('click', onRemove);

        return $('<div class="ordo-flow-variant-action-row"></div>')
            .append($typeSelect, $paramsTextarea, $removeButton);
    }

    /**
     * One variant block: key/weight inputs, its own action list, and "add action"/"remove
     * variant" controls. Module-level for the same reason as renderVariantActionRow() above.
     *
     * @param {Object} variant {key, weight, actions}
     * @param {Object} typesConfig
     * @param {function(): void} onChange re-serializes the whole editor's hidden input
     * @param {function(): void} onRemove re-renders the whole editor after this variant is
     *   spliced out of the parent `variants` array
     * @return {jQuery}
     */
    function renderVariantBlock(variant, typesConfig, onChange, onRemove) {
        var $keyInput = $('<input type="text" class="ordo-flow-variant-key" placeholder="Variant key, e.g. a">')
                .val(variant.key)
                .on('input', function () {
                    variant.key = $(this).val();
                    onChange();
                }),
            $weightInput = $('<input type="number" min="0" class="ordo-flow-variant-weight" placeholder="Weight">')
                .val(variant.weight)
                .on('input', function () {
                    variant.weight = Number($(this).val()) || 0;
                    onChange();
                }),
            $removeVariantButton = $(
                '<button type="button" class="ordo-flow-variant-remove">' + escapeHtml('Remove variant') + '</button>'
            ).on('click', onRemove),
            $actionsList = $('<div class="ordo-flow-variant-actions"></div>'),
            $addActionButton = $(
                '<button type="button" class="ordo-flow-variant-action-add">' + escapeHtml('+ Add action') + '</button>'
            );

        function renderActions() {
            $actionsList.empty();
            variant.actions.forEach(function (action, actionIndex) {
                $actionsList.append(renderVariantActionRow(action, typesConfig, onChange, function () {
                    variant.actions.splice(actionIndex, 1);
                    onChange();
                    renderActions();
                }));
            });
        }

        renderActions();
        $addActionButton.on('click', function () {
            variant.actions.push({ type: (typesConfig.actions || []).filter(function (t) {
                return t !== 'split';
            })[0] || '', params: {} });
            onChange();
            renderActions();
        });

        return $('<div class="ordo-flow-variant-block"></div>').append(
            $('<div class="ordo-flow-variant-head"></div>').append(
                '<label>' + escapeHtml('Key') + '</label>', $keyInput,
                '<label>' + escapeHtml('Weight') + '</label>', $weightInput,
                $removeVariantButton
            ),
            $actionsList,
            $addActionButton
        );
    }

    /**
     * The whole widget for a `variant_list`-type field (currently only the `split` action's own
     * `variants` field) - keeps an in-memory `variants` array as the single source of truth,
     * re-rendered on every structural change (add/remove variant or action), and serializes into
     * one hidden `data-field="<fieldName>"` input on every change so collectRows() picks it up
     * exactly like any other field, no different handling needed there. Module-level for the
     * same reason as renderVariantActionRow()/renderVariantBlock() above - exposed on the
     * exported function (see the bottom of this file) so Test/js/campaign-flow-editor.test.js
     * can drive the whole interactive widget against a real jsdom container without needing a
     * working Drawflow instance.
     *
     * @param {jQuery} $container
     * @param {String} fieldName
     * @param {Array} initialVariants
     * @param {Object} typesConfig
     */
    function renderVariantEditor($container, fieldName, initialVariants, typesConfig) {
        var variants = (Array.isArray(initialVariants) ? initialVariants : []).map(cloneSplitVariant),
            $hidden = $('<input type="hidden" class="ordo-flow-field-input" data-field="' + fieldName + '">'),
            $items = $('<div class="ordo-flow-variant-items"></div>'),
            $addVariantButton = $(
                '<button type="button" class="ordo-flow-variant-add">' + escapeHtml('+ Add variant') + '</button>'
            );

        function sync() {
            $hidden.val(JSON.stringify(variants));
        }

        function renderAll() {
            $items.empty();
            variants.forEach(function (variant, variantIndex) {
                $items.append(renderVariantBlock(variant, typesConfig, sync, function () {
                    variants.splice(variantIndex, 1);
                    sync();
                    renderAll();
                }));
            });
            sync();
        }

        $addVariantButton.on('click', function () {
            variants.push({ key: '', weight: 0, actions: [] });
            renderAll();
        });

        $container.empty().append($hidden, $items, $addVariantButton);
        renderAll();
    }

    /**
     * @param {HTMLElement} container
     * @param {Object} flowData
     * @param {String} formProviderName
     * @param {Object} typesConfig
     */
    var initCampaignFlowEditor = function initCampaignFlowEditor(container, flowData, formProviderName, typesConfig) {
        (function build() {
            var editor = new Drawflow(container);

            editor.reroute = true;
            // Drawflow's default curvature (0.5) offsets each connection's bezier control
            // points by a fixed proportion of the horizontal distance between nodes -  at
            // normal spacing this looks fine, but when two nodes are placed close together
            // (a small campaign with little room to spread out) the same proportion still
            // pushes the control points out just as far, producing a large looping S-curve
            // instead of a clean short line. Reported directly as looking bad in that case.
            // A smaller curvature keeps the same gentle curve at normal spacing while staying
            // tight at short distances.
            editor.curvature = 0.26;
            editor.reroute_curvature = 0.26;
            editor.reroute_curvature_start_end = 0.26;
            editor.editor_mode = 'edit';
            editor.start();

            // Drawflow's connection paths have no direction indicator of their own — one shared
            // <marker> covers every connection via CSS `marker-end: url(#ordo-flow-arrowhead)`
            // (see flow.css), so this only needs to exist once in the document, not per edge.
            (function injectArrowheadMarker() {
                var svgNs = 'http://www.w3.org/2000/svg',
                    svg = document.createElementNS(svgNs, 'svg'),
                    marker = document.createElementNS(svgNs, 'marker'),
                    path = document.createElementNS(svgNs, 'path');

                svg.setAttribute('width', '0');
                svg.setAttribute('height', '0');
                svg.style.position = 'absolute';

                marker.setAttribute('id', 'ordo-flow-arrowhead');
                marker.setAttribute('viewBox', '0 0 10 10');
                marker.setAttribute('refX', '8');
                marker.setAttribute('refY', '5');
                // Absolute pixel size, not a multiple of the path's own stroke-width (the
                // default markerUnits) - Drawflow's connection paths don't set a consistent
                // stroke-width, so the marker was rendering at wildly different, tiny sizes
                // depending on the connection, which looked like a detached, misplaced arrow
                // rather than one cleanly capping the line.
                marker.setAttribute('markerUnits', 'userSpaceOnUse');
                marker.setAttribute('markerWidth', '10');
                marker.setAttribute('markerHeight', '10');
                marker.setAttribute('orient', 'auto');

                path.setAttribute('d', 'M0,0 L10,5 L0,10 z');
                path.setAttribute('fill', '#8493a0');

                marker.appendChild(path);
                svg.innerHTML = '<defs></defs>';
                svg.querySelector('defs').appendChild(marker);
                container.appendChild(svg);
            }());

            /**
             * @param {String} kind 'condition' | 'action'
             * @param {String} type
             * @return {Array} field descriptors: [{name, label}]
             */
            function fieldsFor(kind, type) {
                return typesConfig.fields[kind]?.[type] || [];
            }

            /**
             * Builds the <option> markup for a select-type field descriptor — split out of
             * renderFields() purely to keep that function's own callback nesting within the
             * linter's max-nesting-depth limit.
             *
             * @param {Object} field
             * @param {String} value
             * @return {String}
             */
            function buildSelectOptionsHtml(field, value) {
                var html = '';

                Object.keys(field.options).forEach(function (optionValue) {
                    var selectedAttr = String(value) === String(optionValue) ? ' selected="selected"' : '';

                    html += '<option value="' + $('<div>').text(optionValue).html() + '"' + selectedAttr + '>' +
                        $('<div>').text(field.options[optionValue]).html() + '</option>';
                });

                return html;
            }

            /**
             * Renders labeled inputs for the node's current type into its `.ordo-flow-fields`
             * container, pre-filled from `params` where a value exists. A type with no mapped
             * fields (a custom condition/action a store added, not one of the six this module
             * knows about) falls back to a single raw JSON field — that's the exception, not
             * the default.
             *
             * @param {jQuery} $node
             * @param {String} kind
             * @param {String} type
             * @param {Object} params
             */
            function renderFields($node, kind, type, params) {
                var $fields = $node.find('.ordo-flow-fields'),
                    descriptors = fieldsFor(kind, type),
                    html = '';

                $fields.empty();

                if (descriptors.length) {
                    descriptors.forEach(function (field) {
                        var value = params?.[field.name] || '';

                        html += '<label class="ordo-flow-field-label">' + field.label + '</label>';

                        // A 'variant_list'-typed field (currently only split's own "variants")
                        // needs live DOM/event wiring a plain HTML string can't carry - rendered
                        // as an empty placeholder here and actually built by renderVariantEditor()
                        // in a post-processing pass right after $fields.html(html) below, same
                        // two-step reasoning applies as for every other field: build the string
                        // first, then find-and-enhance the pieces that need real behavior.
                        if (field.type === 'variant_list') {
                            html += '<div class="ordo-flow-variant-editor" data-variant-editor="' +
                                field.name + '"></div>';
                            if (field.notice) {
                                html += '<span class="ordo-flow-field-notice">' +
                                    $('<div>').text(field.notice).html() + '</span>';
                            }
                            return;
                        }

                        // A field descriptor carrying a non-empty "options" map (e.g. the
                        // add_dynamic_content action's content_block_id, built server-side in
                        // Flow::getContentBlockOptions()) renders as a <select> instead of a
                        // free-text <input> — every other action type has no "options" key at
                        // all, so this branch never fires for them and their existing <input>
                        // behavior is unchanged.
                        if (field.options && Object.keys(field.options).length) {
                            html += '<select class="ordo-flow-field-input" data-field="' + field.name + '">' +
                                buildSelectOptionsHtml(field, value) +
                                '</select>';
                        } else {
                            html += '<input type="text" class="ordo-flow-field-input" data-field="' + field.name + '" value="' +
                                $('<div>').text(value).html() + '">';
                        }

                        // A field descriptor carrying a "notice" string (e.g. send_sms's TCPA/
                        // opt-out reminder) renders as a small hint under the input — every other
                        // action type's field descriptors have no "notice" key today, so this is
                        // purely additive, not a behavior change for them.
                        if (field.notice) {
                            html += '<span class="ordo-flow-field-notice">' + $('<div>').text(field.notice).html() + '</span>';
                        }
                    });
                } else if (kind !== 'trigger') {
                    // Every trigger type except scheduled_at/recurring_schedule has no params of
                    // its own — the type select's value IS the whole payload — so unlike
                    // condition/action, an unmapped trigger type gets nothing here rather than a
                    // JSON fallback textarea nobody would ever need to fill in.
                    html += '<label class="ordo-flow-field-label">Params (JSON) — advanced, no dedicated fields for this type</label>' +
                        '<textarea class="ordo-flow-params-textarea" data-field="params_json">' +
                        $('<div>').text(Object.keys(params ?? {}).length ? JSON.stringify(params) : '').html() +
                        '</textarea>';
                }

                $fields.html(html);

                // Second pass: enhance every 'variant_list' placeholder just inserted above with
                // its real, interactive widget - can't happen inline in the descriptors.forEach()
                // above since $fields.html(html) (the innerHTML replace right above this comment)
                // would otherwise immediately discard whatever DOM/handlers a widget attached
                // mid-loop.
                if (descriptors.length) {
                    descriptors.forEach(function (field) {
                        if (field.type !== 'variant_list') {
                            return;
                        }
                        renderVariantEditor(
                            $fields.find('[data-variant-editor="' + field.name + '"]'),
                            field.name,
                            params?.[field.name],
                            typesConfig
                        );
                    });
                }
            }

            /**
             * @param {jQuery} $node
             * @param {String} kind
             */
            function bindNode($node, kind) {
                var initialParams = {};

                try {
                    initialParams = JSON.parse($node.attr('data-params') || '{}');
                } catch (e) {
                    initialParams = {};
                }

                renderFields($node, kind, $node.find('.ordo-flow-type-select').val(), initialParams);

                $node.find('.ordo-flow-type-select').on('change', function () {
                    renderFields($node, kind, $(this).val(), {});
                });
            }

            if (flowData?.drawflow) {
                editor.import(flowData);
            }

            $(container).find('[data-kind]').each(function () {
                var $node = $(this);

                bindNode($node, $node.attr('data-kind'));
            });

            var KIND_LABELS = { trigger: 'Trigger', condition: 'Condition', action: 'Action' },
                KIND_TYPE_LISTS = { trigger: 'triggers', condition: 'conditions', action: 'actions' };

            /**
             * @param {String} kind 'trigger' | 'condition' | 'action'
             * @param {String} label
             * @param {Array} typeOptions
             * @param {String} [presetType] pre-select this type (dropped from the palette
             *   already carrying a specific type, e.g. "order_total_gte") instead of defaulting
             *   to whatever option happens to sort first
             * @return {String}
             */
            function buildNodeHtml(kind, label, typeOptions, presetType) {
                var typeLabels = typesConfig.labels[kind] || {},
                    optionsHtml = typeOptions.map(function (type) {
                        var selectedAttr = type === presetType ? ' selected="selected"' : '';
                        return '<option value="' + type + '"' + selectedAttr + '>' +
                            escapeHtml(typeLabels[type] || type) + '</option>';
                    }).join(''),
                    // delay_minutes only applies to action nodes, and only ever starts at 0 for
                    // a freshly-dropped node — matches the markup Flow::editableNodeHtml() (PHP)
                    // renders for an existing action loaded from the database, so collectRows()
                    // below can read data-field="delay_minutes" the same way regardless of
                    // whether the node came from the server or the palette.
                    delayHtml = kind === 'action'
                        ? '<label class="ordo-flow-field-label">Delay (minutes)</label>' +
                            '<input type="text" class="ordo-flow-field-input" data-field="delay_minutes" value="0">'
                        : '';

                // `data-kind` (not a class) is what collectRows() below matches on — Drawflow
                // already puts the 'ordo-flow-condition'/'ordo-flow-action'/'ordo-flow-trigger'
                // class on the OUTER .drawflow-node wrapper it builds around this HTML (see
                // addNode() below), so repeating that class on this inner div would make every
                // node match twice. Every kind (triggers included, for scheduled_at/
                // recurring_schedule's own fields) gets a `.ordo-flow-fields` container —
                // renderFields() just leaves it empty for a trigger type with no params.
                return '<div class="ordo-flow-node" data-kind="' + kind + '" data-params="{}">' +
                    '<div class="ordo-flow-node-head"><span>' + label + '</span>' +
                    '<button type="button" class="ordo-flow-delete" title="Remove">&times;</button></div>' +
                    '<select class="ordo-flow-type-select">' + optionsHtml + '</select>' +
                    delayHtml +
                    '<div class="ordo-flow-fields"></div>' +
                    '</div>';
            }

            /**
             * @param {String} kind
             * @param {String} [presetType] pre-select a specific type on the new node instead
             *   of leaving it at whatever sorts first — used when the node was dropped from a
             *   specific palette chip (e.g. dragging "order_total_gte" onto the canvas should
             *   produce a condition node already set to that type, not a generic blank one)
             * @param {Number} [posX] canvas-relative position; defaults to the old
             *   click-to-add placement (fixed x for triggers, randomized for everything else)
             *   when not dropped at a specific point
             * @param {Number} [posY]
             */
            function addNode(kind, presetType, posX, posY) {
                var label = KIND_LABELS[kind],
                    typeOptions = typesConfig[KIND_TYPE_LISTS[kind]],
                    html = buildNodeHtml(kind, label, typeOptions, presetType),
                    nodeId,
                    inputCount = kind === 'trigger' ? 0 : 1;

                // Math.random() here only jitters where a new node lands on the canvas so
                // stacked nodes don't overlap exactly — cosmetic layout, not a security or
                // cryptographic use, so a predictable PRNG is fine. NOSONAR: javascript:S2245
                if (posX === undefined) {
                    posX = kind === 'trigger' ? 60 : (60 + Math.random() * 400); // NOSONAR
                }
                if (posY === undefined) {
                    posY = 260 + Math.random() * 120; // NOSONAR
                }

                nodeId = editor.addNode(
                    'ordo-flow-' + kind,
                    inputCount,
                    1,
                    posX,
                    posY,
                    'ordo-flow-' + kind,
                    {},
                    html
                );

                bindNode($(container).find('#node-' + nodeId).find('[data-kind]'), kind);

                return nodeId;
            }

            /**
             * Converts a viewport drop point (event.clientX/Y) into Drawflow's internal canvas
             * coordinate space — Drawflow pans/zooms `editor.precanvas` independently of the
             * page, so a raw clientX/Y would place the new node wherever the canvas happened to
             * be scrolled/zoomed to, not under the cursor. Same formula Drawflow's own
             * drag-from-menu demo uses: undo the canvas's current zoom and offset.
             *
             * @param {Number} clientX
             * @param {Number} clientY
             * @return {{x: Number, y: Number}}
             */
            function toCanvasPosition(clientX, clientY) {
                var rect = editor.precanvas.getBoundingClientRect(),
                    zoom = editor.zoom || 1;

                return {
                    x: (clientX - rect.x) / zoom,
                    y: (clientY - rect.y) / zoom
                };
            }

            /**
             * Ready-made starting points for the most common repeatable automation scenarios,
             * so building one of these doesn't mean dragging/wiring every node by hand each
             * time (reported directly: "przygotowal gotowa baze szablonow alogrytmow w ktore
             * klikniesz i nie musisz ich sam ukladc"). Each is just a trigger -> (optional
             * condition) -> action chain using types every install of this module already has
             * (Model\Campaign\TypeLabels' own ACTION_LABELS/CONDITION_LABELS) - loading one only
             * saves the layout/wiring step, the merchant still fills in the actual field values
             * (email content, tag name, coupon rule, etc.) same as any hand-built node.
             *
             * @type {Object<String, {label: String, nodes: Array<{kind: String, type: String}>}>}
             */
            var FLOW_TEMPLATES = {
                abandoned_cart: {
                    label: 'Abandoned Cart Recovery',
                    nodes: [
                        { kind: 'trigger', type: 'cart_abandoned' },
                        { kind: 'action', type: 'send_email' }
                    ]
                },
                welcome_new_customer: {
                    label: 'Welcome New Customer',
                    nodes: [
                        { kind: 'trigger', type: 'customer_registered' },
                        { kind: 'action', type: 'send_email' }
                    ]
                },
                post_purchase_coupon: {
                    label: 'Post-Purchase Thank You + Coupon',
                    nodes: [
                        { kind: 'trigger', type: 'order_placed' },
                        { kind: 'action', type: 'send_email' },
                        { kind: 'action', type: 'generate_coupon' }
                    ]
                },
                tagged_customer_followup: {
                    label: 'Follow Up on Tagged Customers',
                    nodes: [
                        { kind: 'trigger', type: 'tag_added' },
                        { kind: 'condition', type: 'tag' },
                        { kind: 'action', type: 'send_email' }
                    ]
                }
            };

            /**
             * Finds where the next template's chain should start so it lands clear of every
             * node already on the canvas, instead of guessing a random spot that can overlap
             * existing nodes — reported directly, and reproducible: loading a second template
             * (or loading one after already building something by hand) stacked its nodes
             * right on top of what was already there.
             *
             * @return {Number}
             */
            function getNextTemplateStartX() {
                var maxRight = 0;

                $(container).find('.drawflow-node').each(function () {
                    var left = Number.parseFloat(this.style.left) || 0,
                        width = this.offsetWidth || 220;

                    maxRight = Math.max(maxRight, left + width);
                });

                return maxRight > 0 ? maxRight + 60 : 60;
            }

            /**
             * Adds one template's whole node chain to the canvas and wires it trigger ->
             * condition(s) -> action(s) in the order given, left to right, starting clear of
             * every existing node (see getNextTemplateStartX()) rather than at a random spot
             * that could overlap them. Does not touch or clear whatever is already on the
             * canvas - loading a template on top of existing work just adds its nodes
             * alongside, same as dragging each one in by hand would.
             *
             * A campaign's triggers/conditions/actions are one shared, flat list underneath
             * (Flow.php's own docblock: every trigger fans out to the SAME downstream chain -
             * "alternative starting points for one scenario, not separate scenarios"), not a
             * set of independent parallel flows. Loading a second template creates a second,
             * disconnected trigger -> action chain that LOOKS like its own separate scenario on
             * the canvas but isn't one once saved - every trigger that reaches this module's
             * campaign dispatcher would run every action reachable from any trigger, not just
             * the pair that were drawn side by side.
             *
             * This used to warn with a native confirm() before adding a second, disconnected
             * chain — reported directly as unreliable ("wyskakuje popup na mikrosekunde i
             * znika"), and the user's own suggested fix was simpler and more robust anyway: let
             * it be added, just don't let a flow shaped like that be saved. validateFlow()'s own
             * "triggers must share one chain" check (see checkTriggersShareOneChain()) now
             * catches this at Apply-time instead, the same way it already catches every other
             * incomplete-flow problem — one consistent validation path instead of a second,
             * separate warning mechanism.
             *
             * @param {String} templateKey
             */
            function applyTemplate(templateKey) {
                var template = FLOW_TEMPLATES[templateKey],
                    startX = getNextTemplateStartX(),
                    startY = 80,
                    previousNodeId = null;

                if (!template) {
                    return;
                }

                template.nodes.forEach(function (nodeSpec, index) {
                    var nodeId = addNode(nodeSpec.kind, nodeSpec.type, startX + index * 260, startY);

                    if (previousNodeId !== null) {
                        editor.addConnection(previousNodeId, nodeId, 'output_1', 'input_1');
                    }

                    previousNodeId = nodeId;
                });
            }

            $(document).on('click', '[data-flow-template]', function () {
                applyTemplate($(this).attr('data-flow-template'));
                $(this).closest('details.ordo-flow-templates').removeAttr('open');
            });

            /**
             * TEST-SUPPORT ONLY — not used by any real merchant-facing feature.
             *
             * MFTF's plain click/dragAndDrop actions can't reliably drive Drawflow's palette,
             * since dragging a chip onto the canvas depends on native HTML5 dragstart/drop
             * DataTransfer events (see the "Palette drag-and-drop" handlers above) that a
             * synthesized Selenium drag does not reproduce faithfully in every browser. Rather
             * than fight that, this exposes the same node-building/wiring/apply primitives the
             * palette itself calls (addNode(), editor.addConnection(), the Apply button's own
             * click handler) directly to the page's global scope, so a test can build a flow
             * graph with one <executeJS> call and then drive the exact same, unmodified Apply
             * button a real merchant would click — nothing about the save path is bypassed or
             * duplicated, only the mouse-drag step is replaced with a direct function call.
             *
             * window.ordoFlowTestHook.buildChain(nodeSpecs) adds each node in nodeSpecs in
             * order, left to right, and returns the array of created Drawflow node ids.
             *   nodeSpecs: Array<{kind: 'trigger'|'condition'|'action', type: String,
             *              fields?: Object<String, String>}>
             *   `fields` (optional) is applied as data-field="<key>" -> value on the node's own
             *   inputs right after creation — the same inputs collectRows() reads from when
             *   Apply is clicked, so this is exactly what a merchant typing into those same
             *   boxes by hand would produce, not a separate/parallel data path.
             *
             * Wiring: node[i] -> node[i+1] (output_1 -> input_1), same as applyTemplate() above,
             * EXCEPT a trigger node is never the connection's target - addNode() gives trigger
             * nodes zero inputs (they're entry points, nothing feeds into one), so connecting
             * into "trigger2's input_1" doesn't just no-op, it corrupts the graph and made
             * validateFlow() throw a raw JS TypeError on a real CI run instead of the friendly
             * "needs at least one Action" message a merchant would see for the same shape.
             * Consecutive trigger specs (multiple triggers, no condition/action between them)
             * are instead all queued and fanned in together to the next non-trigger node, once
             * one is added - matching validateFlow()'s own checkTriggersShareOneChain() rule
             * that every trigger is a valid, independent entry point into the SAME shared chain,
             * not a series feeding into each other.
             */
            window.ordoFlowTestHook = {
                buildChain: function (nodeSpecs) {
                    var startX = getNextTemplateStartX(),
                        startY = 80,
                        previousNodeId = null,
                        pendingTriggerIds = [],
                        nodeIds = [];

                    (nodeSpecs || []).forEach(function (nodeSpec, index) {
                        var nodeId = addNode(nodeSpec.kind, nodeSpec.type, startX + index * 260, startY),
                            $node = $(container).find('#node-' + nodeId);

                        applyBuildChainNodeFields($node, nodeSpec.fields);

                        if (nodeSpec.kind === 'trigger') {
                            pendingTriggerIds.push(nodeId);
                        } else {
                            if (pendingTriggerIds.length) {
                                connectPendingTriggers(editor, pendingTriggerIds, nodeId);
                                pendingTriggerIds = [];
                            } else if (previousNodeId !== null) {
                                editor.addConnection(previousNodeId, nodeId, 'output_1', 'input_1');
                            }

                            previousNodeId = nodeId;
                        }

                        nodeIds.push(nodeId);
                    });

                    return nodeIds;
                }
            };

            // Drawflow renders node HTML as-is; delete buttons are wired via event delegation
            // since nodes are added/removed dynamically after the container's own listeners are
            // bound once at init.
            $(container).on('click', '.ordo-flow-delete', function () {
                var nodeEl = $(this).closest('[id^="node-"]'),
                    nodeId = nodeEl.attr('id');

                if (nodeId) {
                    editor.removeNodeId(nodeId);
                }
            });

            // Palette drag-and-drop: each chip in the sidebar (flow.phtml) carries the exact
            // kind/type to create, set as the drag payload on dragstart. The canvas itself must
            // preventDefault() on dragover for a drop to be allowed to fire at all (native HTML5
            // drag-and-drop behavior, not a Drawflow API). Dropping instantiates a node with
            // that type pre-selected, positioned under the cursor via toCanvasPosition().
            $(document).on('dragstart', '.ordo-flow-palette-item', function (event) {
                var $item = $(this);

                event.originalEvent.dataTransfer.setData('text/plain', JSON.stringify({
                    kind: $item.attr('data-flow-kind'),
                    type: $item.attr('data-flow-type')
                }));
                event.originalEvent.dataTransfer.effectAllowed = 'copy';
            });

            $(container).on('dragover', function (event) {
                event.preventDefault();
                event.originalEvent.dataTransfer.dropEffect = 'copy';
            });

            $(container).on('drop', function (event) {
                var raw = event.originalEvent.dataTransfer.getData('text/plain'),
                    payload,
                    pos;

                event.preventDefault();

                try {
                    payload = JSON.parse(raw);
                } catch (e) {
                    return;
                }

                if (!payload?.kind) {
                    return;
                }

                pos = toCanvasPosition(event.originalEvent.clientX, event.originalEvent.clientY);
                addNode(payload.kind, payload.type, pos.x, pos.y);
            });

            /**
             * Reads every condition/action node currently on the canvas, in DOM order (Drawflow
             * assigns each new node the next integer id, so DOM order === creation/chain order —
             * this module does not attempt to infer a different order from the connections
             * themselves, since the backend model is a simple AND-conditions/sequential-actions
             * chain, not a general graph). Each row carries its dedicated fields directly
             * (tag/amount/rule_id/prefix/template/message) plus params_json as the fallback —
             * exactly the shape Controller\Adminhtml\Campaign\Save::normalizeRowParams() already
             * merges, same as a row posted by the native dynamicRows form.
             *
             * @param {String} kind
             * @return {Array}
             */
            /**
             * @param {jQuery} $field
             * @param {Object} row
             */
            function collectFieldValue($field, row) {
                row[$field.attr('data-field')] = $field.val();
            }

            /**
             * @param {jQuery} $node
             * @param {Object} row
             */
            function collectNodeFields($node, row) {
                $node.find('[data-field]').each(function () {
                    collectFieldValue($(this), row);
                });
            }

            function collectRows(kind) {
                var rows = [];

                $(container).find('[data-kind="' + kind + '"]').each(function () {
                    var $node = $(this),
                        type = $node.find('.ordo-flow-type-select').val(),
                        row;

                    if (!type) {
                        return;
                    }

                    // A trigger row's own type key is `trigger_event`, not `type` (matching
                    // Api\Data\CampaignTriggerInterface, unlike condition/action rows) — its
                    // scheduled_at/cron_expression fields (when present) still collect the same
                    // way via collectNodeFields() below.
                    if (kind === 'trigger') {
                        row = { trigger_event: type };
                        collectNodeFields($node, row);
                        rows.push(row);
                        return;
                    }

                    row = { type: type };

                    collectNodeFields($node, row);

                    rows.push(row);
                });

                return rows;
            }

            /**
             * A flow is only valid to save if it's an actually complete scenario, not just a
             * bag of nodes that happen to sit on the canvas:
             *  - at least one Trigger exists (nothing would ever start the scenario otherwise)
             *  - at least one Action exists (a flow with none does nothing when it fires)
             *  - every condition/action is reachable from SOME trigger (nothing floating,
             *    disconnected from every starting point) — a campaign can have several triggers
             *    (see CampaignTriggerInterface), each one is a valid entry point into the same
             *    chain, not a separate scenario
             *  - every condition leads somewhere (an output that connects to nothing is a dead
             *    end — the scenario never actually reaches an action through it)
             * Actions are allowed to be dead ends (they're the end of the scenario by design),
             * conditions are not.
             *
             * @return {{errors: Array<String>, badNodeIds: Array}}
             */
            /**
             * @param {Object} connection
             * @param {Object} reachable
             * @param {Array} queue
             */
            function markConnectionReachable(connection, reachable, queue) {
                if (!reachable[connection.node]) {
                    reachable[connection.node] = true;
                    queue.push(connection.node);
                }
            }

            /**
             * @param {String} outputKey
             * @param {Object} currentNode
             * @param {Object} reachable
             * @param {Array} queue
             */
            function expandReachableOutput(outputKey, currentNode, reachable, queue) {
                currentNode.outputs[outputKey].connections.forEach(function (connection) {
                    markConnectionReachable(connection, reachable, queue);
                });
            }

            /**
             * @param {Object} node
             * @return {Number}
             */
            function countNodeOutputs(node) {
                return Object.keys(node.outputs || {}).reduce(function (count, key) {
                    return count + node.outputs[key].connections.length;
                }, 0);
            }

            /**
             * Union-find over every node's undirected connections, so validateFlow() can tell
             * whether all triggers ultimately land in one connected component (alternative
             * starting points for the SAME sequence, the only shape the underlying data model
             * — one shared flat triggers/conditions/actions list per campaign — actually
             * supports) or split into two-or-more totally separate islands that only LOOK like
             * independent scenarios on the canvas.
             *
             * @param {Object} exportedData
             * @return {{find: function(String): String}}
             */
            function buildConnectivityGroups(exportedData) {
                var parent = {};

                function find(id) {
                    if (parent[id] === undefined) {
                        parent[id] = id;
                    }
                    while (parent[id] !== id) {
                        parent[id] = parent[parent[id]] || parent[id];
                        id = parent[id];
                    }
                    return id;
                }

                function union(a, b) {
                    var rootA = find(a),
                        rootB = find(b);

                    if (rootA !== rootB) {
                        parent[rootA] = rootB;
                    }
                }

                Object.keys(exportedData).forEach(function (id) {
                    unionNodeOutputConnections(id, exportedData[id], union);
                });

                return { find: find };
            }

            function validateFlow() {
                var exported = editor.export().drawflow.Home.data,
                    triggerIds = [],
                    reachable = {},
                    queue,
                    errors = [],
                    badNodeIds = [],
                    hasAction = false;

                Object.keys(exported).forEach(function (id) {
                    if (exported[id].name === 'ordo-flow-trigger') {
                        triggerIds.push(id);
                    }
                    if (exported[id].name === 'ordo-flow-action') {
                        hasAction = true;
                    }
                });

                if (!triggerIds.length) {
                    errors.push('The flow needs at least one Trigger — right now nothing would ever start it.');
                }

                if (!hasAction) {
                    errors.push('The flow needs at least one Action — right now it wouldn\'t do anything.');
                }

                if (!triggerIds.length) {
                    return { errors: errors, badNodeIds: badNodeIds };
                }

                // Every trigger must ultimately connect into the SAME chain — a campaign's
                // triggers/conditions/actions are one shared, flat list underneath, not a set of
                // independent parallel flows (multiple triggers are alternative starting points
                // for one scenario, not separate scenarios; see this file's own docs above and
                // Block\Adminhtml\Campaign\Edit\Flow.php). Two or more triggers that never join
                // up would each still individually pass the "reaches an Action" check below, so
                // this needs its own, separate connectivity check.
                (function checkTriggersShareOneChain() {
                    var groups = buildConnectivityGroups(exported),
                        primaryRoot = groups.find(triggerIds[0]),
                        disconnectedIds = findDisconnectedNodeIds(exported, groups, primaryRoot);

                    if (disconnectedIds.length) {
                        errors.push(
                            'This flow has more than one Trigger chain that never connect to each ' +
                            'other (highlighted). A campaign\'s triggers must all lead into the ' +
                            'same shared sequence of conditions/actions — connect them together, ' +
                            'or build the other one as a separate campaign.'
                        );
                        badNodeIds = badNodeIds.concat(disconnectedIds);
                    }
                }());

                queue = triggerIds.slice();
                triggerIds.forEach(function (id) {
                    reachable[id] = true;
                });

                while (queue.length) {
                    var currentId = queue.shift(),
                        currentNode = exported[currentId];

                    if (!currentNode) {
                        continue;
                    }

                    Object.keys(currentNode.outputs || {}).forEach(function (outputKey) {
                        expandReachableOutput(outputKey, currentNode, reachable, queue);
                    });
                }

                var badNodeCountBeforeReachabilityCheck = badNodeIds.length;

                Object.keys(exported).forEach(function (id) {
                    var node = exported[id],
                        outputCount;

                    if (node.name === 'ordo-flow-trigger') {
                        return;
                    }

                    if (!reachable[id]) {
                        badNodeIds.push(id);
                        return;
                    }

                    if (node.name === 'ordo-flow-condition') {
                        outputCount = countNodeOutputs(node);

                        if (outputCount === 0) {
                            badNodeIds.push(id);
                        }
                    }
                });

                // Only add this generic message for issues THIS check found — the
                // triggers-share-one-chain check above already reported its own, more specific
                // message for the ids it added, avoiding two overlapping error lines for what a
                // merchant would see as one problem (e.g. loading a second, disconnected template).
                if (badNodeIds.length > badNodeCountBeforeReachabilityCheck) {
                    errors.push(
                        'Every node must be connected from a Trigger all the way through to an ' +
                        'Action — the highlighted node(s) are either disconnected or a condition ' +
                        'that doesn\'t lead anywhere.'
                    );
                }

                return { errors: errors, badNodeIds: badNodeIds };
            }

            /**
             * @param {jQuery} $wrapper
             * @param {Array<String>} messages
             */
            function showFlowErrors($wrapper, messages) {
                var $notice = $wrapper.find('.ordo-flow-error');

                if (!$notice.length) {
                    $notice = $('<div class="ordo-flow-error"></div>').insertAfter($wrapper.find('.ordo-flow-toolbar'));
                }

                $notice.html(messages.map(function (message) {
                    return '<div>' + message + '</div>';
                }).join('')).show();
            }

            function clearFlowError($wrapper) {
                $wrapper.find('.ordo-flow-error').hide();
            }

            $(container).closest('.ordo-flow-wrapper').on('click', '[data-flow-action="apply"]', function () {
                var $button = $(this),
                    $wrapper = $button.closest('.ordo-flow-wrapper'),
                    validation = validateFlow();

                $(container).find('.drawflow-node').removeClass('ordo-flow-node-error');

                if (validation.errors.length) {
                    validation.badNodeIds.forEach(function (id) {
                        $(container).find('#node-' + id).addClass('ordo-flow-node-error');
                    });

                    showFlowErrors($wrapper, validation.errors);

                    return;
                }

                clearFlowError($wrapper);

                registry.get(formProviderName, function (provider) {
                    provider.set('data.triggers', {
                        triggers: collectRows('trigger')
                    });
                    provider.set('data.conditions', {
                        conditions: collectRows('condition')
                    });
                    provider.set('data.actions', {
                        actions: collectRows('action')
                    });

                    $button.text('Saving…');
                    provider.save();
                });
            });
        }());
    };

    // Exposed for Test/js/campaign-flow-editor.test.js - see segment-group-modal.js's own return
    // statement for why this is safe (attaching to the exported function, not changing its own
    // call signature/behavior at all - real callers that only ever do
    // `initCampaignFlowEditor(container, ...)` are unaffected).
    initCampaignFlowEditor.unionNodeOutputConnections = unionNodeOutputConnections;
    initCampaignFlowEditor.findDisconnectedNodeIds = findDisconnectedNodeIds;
    initCampaignFlowEditor.cloneSplitVariant = cloneSplitVariant;
    initCampaignFlowEditor.buildSplitVariantActionTypeOptionsHtml = buildSplitVariantActionTypeOptionsHtml;
    initCampaignFlowEditor.renderVariantEditor = renderVariantEditor;

    return initCampaignFlowEditor;
});
