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
define([
    'jquery',
    'uiRegistry',
    'drawflow',
    'domReady!'
], function ($, registry, Drawflow) {
    'use strict';

    /**
     * @param {HTMLElement} container
     * @param {Object} flowData
     * @param {String} formProviderName
     * @param {Object} typesConfig
     */
    return function initCampaignFlowEditor(container, flowData, formProviderName, typesConfig) {
        (function build() {
            var editor = new Drawflow(container);

            editor.reroute = true;
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
                marker.setAttribute('refX', '9');
                marker.setAttribute('refY', '5');
                marker.setAttribute('markerWidth', '3.5');
                marker.setAttribute('markerHeight', '3.5');
                marker.setAttribute('orient', 'auto-start-reverse');

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
                return (typesConfig.fields[kind] && typesConfig.fields[kind][type]) || [];
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
                        var value = params && params[field.name] ? params[field.name] : '';

                        html += '<label class="ordo-flow-field-label">' + field.label + '</label>' +
                            '<input type="text" class="ordo-flow-field-input" data-field="' + field.name + '" value="' +
                            $('<div>').text(value).html() + '">';
                    });
                } else {
                    html += '<label class="ordo-flow-field-label">Params (JSON) — advanced, no dedicated fields for this type</label>' +
                        '<textarea class="ordo-flow-params-textarea" data-field="params_json">' +
                        $('<div>').text(params && Object.keys(params).length ? JSON.stringify(params) : '').html() +
                        '</textarea>';
                }

                $fields.html(html);
            }

            /**
             * @param {jQuery} $node
             * @param {String} kind
             */
            function bindNode($node, kind) {
                var initialParams = {};

                // Triggers have no dedicated fields/params — the type select's value IS the
                // whole payload (the trigger_event itself), nothing to render or pre-fill.
                if (kind === 'trigger') {
                    return;
                }

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

            if (flowData && flowData.drawflow) {
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
             * @return {String}
             */
            function buildNodeHtml(kind, label, typeOptions) {
                var optionsHtml = typeOptions.map(function (type) {
                    return '<option value="' + type + '">' + type + '</option>';
                }).join('');

                // `data-kind` (not a class) is what collectRows() below matches on — Drawflow
                // already puts the 'ordo-flow-condition'/'ordo-flow-action'/'ordo-flow-trigger'
                // class on the OUTER .drawflow-node wrapper it builds around this HTML (see
                // addNode() below), so repeating that class on this inner div would make every
                // node match twice. Triggers have no `.ordo-flow-fields` container — see
                // bindNode() — since their select value is the entire payload.
                return '<div class="ordo-flow-node" data-kind="' + kind + '" data-params="{}">' +
                    '<div class="ordo-flow-node-head"><span>' + label + '</span>' +
                    '<button type="button" class="ordo-flow-delete" title="Remove">&times;</button></div>' +
                    '<select class="ordo-flow-type-select">' + optionsHtml + '</select>' +
                    (kind === 'trigger' ? '' : '<div class="ordo-flow-fields"></div>') +
                    '</div>';
            }

            /**
             * @param {String} kind
             */
            function addNode(kind) {
                var label = KIND_LABELS[kind],
                    typeOptions = typesConfig[KIND_TYPE_LISTS[kind]],
                    html = buildNodeHtml(kind, label, typeOptions),
                    posX = kind === 'trigger' ? 60 : (60 + Math.random() * 400),
                    posY = 260 + Math.random() * 120,
                    nodeId,
                    inputCount = kind === 'trigger' ? 0 : 1;

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
            }

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

            $(container).closest('.ordo-flow-wrapper').on('click', '[data-flow-action="add-trigger"]', function () {
                addNode('trigger');
            });

            $(container).closest('.ordo-flow-wrapper').on('click', '[data-flow-action="add-condition"]', function () {
                addNode('condition');
            });

            $(container).closest('.ordo-flow-wrapper').on('click', '[data-flow-action="add-action"]', function () {
                addNode('action');
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
            function collectRows(kind) {
                var rows = [];

                $(container).find('[data-kind="' + kind + '"]').each(function () {
                    var $node = $(this),
                        type = $node.find('.ordo-flow-type-select').val(),
                        row;

                    if (!type) {
                        return;
                    }

                    // A trigger row's only field IS its type (trigger_event) — no `type` key,
                    // no dedicated fields/params, unlike condition/action rows.
                    if (kind === 'trigger') {
                        rows.push({ trigger_event: type });
                        return;
                    }

                    row = { type: type };

                    $node.find('[data-field]').each(function () {
                        var $field = $(this);

                        row[$field.attr('data-field')] = $field.val();
                    });

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
                        currentNode.outputs[outputKey].connections.forEach(function (connection) {
                            if (!reachable[connection.node]) {
                                reachable[connection.node] = true;
                                queue.push(connection.node);
                            }
                        });
                    });
                }

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
                        outputCount = Object.keys(node.outputs || {}).reduce(function (count, key) {
                            return count + node.outputs[key].connections.length;
                        }, 0);

                        if (outputCount === 0) {
                            badNodeIds.push(id);
                        }
                    }
                });

                if (badNodeIds.length) {
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
});
