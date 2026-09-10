/**
 * "Group (nested AND/OR)" condition editor for the Segment form's conditions list.
 *
 * A nested dynamicRows-inside-dynamicRows was tried first (a group's own conditions as a second
 * dynamicRows field nested inside the outer conditions row) and does not work: no core Magento
 * module anywhere nests one dynamicRows inside another's own record, and confirmed directly here
 * too - its "Add Condition to Group" button fired with no console error but never added a row.
 * Rather than keep guessing at Magento_Ui internals, a group's own conditions are rendered with a
 * small hand-rolled row list instead, written into a single hidden group_conditions_json field (a
 * plain JSON array of {type, params}) that Model\Segment\SegmentSaveProcessor::normalizeGroupRow()
 * reads directly - no dynamicRows involved on the group's own list at all.
 *
 * This list renders INLINE, directly under the group's own "Group matches" selector, always
 * visible - not behind a "Manage group conditions" button opening a modal, which an earlier
 * version of this file used. Reported directly against that version: hiding a group's conditions
 * behind a popup means the admin can't see the segment's whole rule tree at a glance ("Tag AND
 * (Score OR Big Spender)") without clicking into a separate window for every group. The inline
 * list is simply a visually-nested version of the exact same row markup the modal used to build,
 * still writing to the same hidden field on every change - no "Done"/"Cancel" step, since there's
 * no modal to confirm or discard.
 *
 * Each row is Type + one generic "value" input (labeled per-type, e.g. "Minimum score" for
 * score_at_least) - the same one-value-per-type shape every dedicated field on the outer form
 * already has, just not switched via a declarative switcherConfig here since there is no
 * declarative row to switch fields on. The 3 condition types with no single dedicated value
 * anywhere on this form (in_segment, loyalty_tier_at_least, nps_score_at_least) get a raw
 * "Advanced (JSON)" textarea instead, identical in spirit to the outer form's own params_json
 * fallback for the same 3 types.
 */
define([
    'jquery',
    'domReady!'
], function ($) {
    'use strict';

    // type -> {key, label} for the one dedicated value every other condition type has - mirrors
    // Model\Segment\SegmentSaveProcessor::DEDICATED_PARAM_FIELDS / ordo_segment_form.xml's own
    // switcherConfig exactly, just expressed as a JS lookup instead of 6 separate declarative
    // fields (a group's own list isn't a declarative dynamicRows at all, see file docblock).
    var VALUE_FIELD_BY_TYPE = {
        tag: {key: 'tag', label: 'Tag'},
        order_total_gte: {key: 'amount', label: 'Amount'},
        visitor_tag: {key: 'tag', label: 'Tag'},
        score_at_least: {key: 'threshold', label: 'Minimum score'},
        recency_days_at_most: {key: 'days', label: 'Recency (days since last order, at most)'},
        order_frequency_at_least: {key: 'count', label: 'Order count, at least'},
        monetary_total_at_least: {key: 'amount', label: 'Minimum order total'},
        recency_percentile_at_least: {key: 'percentile', label: 'Percentile, at least (0-100)'},
        order_frequency_percentile_at_least: {key: 'percentile', label: 'Percentile, at least (0-100)'},
        monetary_percentile_at_least: {key: 'percentile', label: 'Percentile, at least (0-100)'},
        purchased_sku: {key: 'sku', label: 'SKU'},
        purchased_category: {key: 'category_id', label: 'Category ID'}
    };

    /**
     * The outer conditions list's own Type <select> already has every ConditionTypeWithGroup
     * option, correctly translated - cloned here (minus "group" itself, since a group's own
     * conditions can't themselves be groups - one level of nesting only) instead of hard-coding
     * type/label pairs a second time in JS where they could drift out of sync.
     *
     * @return {Array} [{value, label}]
     */
    function readTypeOptions() {
        var $anySelect = $('[data-index="conditions"] select').first(),
            options = [];

        $anySelect.find('option').each(function () {
            var value = $(this).val();

            if (value && value !== 'group') {
                options.push({value: value, label: $(this).text()});
            }
        });

        return options;
    }

    /**
     * @param {jQuery} $valueWrap
     * @param {String} type
     * @param {Object} existingParams
     */
    function renderValueField($valueWrap, type, existingParams) {
        var field = VALUE_FIELD_BY_TYPE[type];

        $valueWrap.empty();

        if (field) {
            $('<label></label>').text(field.label).appendTo($valueWrap);
            $('<input type="text" class="admin__control-text ordo-group-value-input">')
                .val(existingParams[field.key] || '')
                .appendTo($valueWrap);
            $valueWrap.data('valueKey', field.key);
        } else {
            $('<label></label>').text('Advanced (JSON)').appendTo($valueWrap);
            $('<textarea class="admin__control-textarea ordo-group-json"></textarea>')
                .val(Object.keys(existingParams).length ? JSON.stringify(existingParams) : '')
                .appendTo($valueWrap);
            $valueWrap.data('valueKey', null);
        }
    }

    /**
     * Toggles a visible error state on a "Advanced (JSON)" textarea - the group-condition editor
     * used to silently fall back to an empty {} on malformed JSON with no feedback at all, so a
     * non-technical marketer had no way to know their condition now quietly matches nothing.
     * Marks the field invalid (red border + inline message) rather than blocking save entirely -
     * the fallback-to-{} behavior itself is unchanged (still the safe failure direction), this
     * only makes it visible instead of silent.
     *
     * @param {jQuery} $textarea
     * @param {Boolean} isValid
     * @param {String} [reason] the underlying JSON.parse() error message, appended to the
     *     visible notice so an admin who does know JSON gets an actual clue, not just "invalid".
     */
    function markJsonValidity($textarea, isValid, reason) {
        var $wrap = $textarea.closest('.ordo-group-value'),
            $message = $wrap.find('.ordo-group-json-error-message');

        $textarea.toggleClass('ordo-group-json-invalid', !isValid);

        if (isValid) {
            $message.remove();
            return;
        }

        if (!$message.length) {
            $message = $('<div class="ordo-group-json-error-message"></div>').insertAfter($textarea);
        }
        $message.text('Invalid JSON - this condition will match nothing until fixed.'
            + (reason ? ' (' + reason + ')' : ''));
    }

    /**
     * @param {jQuery} $rows
     * @param {Array} typeOptions
     * @param {Object} condition {type, params}
     * @param {Function} sync call after any change to this row (add/edit/delete)
     */
    function appendInlineRow($rows, typeOptions, condition, sync) {
        var $row = $('<div class="ordo-group-row"></div>'),
            $typeSelect = $('<select class="admin__control-select"></select>'),
            $valueWrap = $('<div class="ordo-group-value"></div>'),
            $delete = $('<button type="button" class="ordo-group-row-delete" title="Remove">✕</button>');

        typeOptions.forEach(function (opt) {
            $('<option></option>').attr('value', opt.value).text(opt.label).appendTo($typeSelect);
        });
        $typeSelect.val(condition.type);

        $delete.on('click', function () {
            $row.remove();
            sync();
        });

        $typeSelect.on('change', function () {
            renderValueField($valueWrap, $typeSelect.val(), {});
            sync();
        });

        $valueWrap.on('change input', sync);

        $row.append($typeSelect).append($valueWrap).append($delete);
        $rows.append($row);

        renderValueField($valueWrap, condition.type, condition.params || {});
    }

    /**
     * @param {jQuery} $rows
     * @return {Array} [{type, params}]
     */
    function readRows($rows) {
        var conditions = [];

        $rows.find('.ordo-group-row').each(function () {
            var $row = $(this),
                type = $row.find('select').val(),
                $valueWrap = $row.find('.ordo-group-value'),
                valueKey = $valueWrap.data('valueKey'),
                params = {};

            if (valueKey) {
                var value = $.trim($valueWrap.find('input').val());

                if (value !== '') {
                    params[valueKey] = value;
                }
            } else {
                var $textarea = $valueWrap.find('textarea'),
                    raw = $.trim($textarea.val());

                if (raw !== '') {
                    try {
                        params = JSON.parse(raw);
                        markJsonValidity($textarea, true);
                    } catch (e) {
                        // Still falls back to {} (a malformed group condition matching nothing is
                        // the safe failure direction, same as an empty group) - but now visibly,
                        // surfacing e.message in the notice instead of the admin silently getting
                        // a condition that quietly matches nothing with no indication why.
                        params = {};
                        markJsonValidity($textarea, false, e.message);
                    }
                } else {
                    markJsonValidity($textarea, true);
                }
            }

            conditions.push({type: type, params: params});
        });

        return conditions;
    }

    /**
     * Builds the always-visible inline panel for one 'group' row's own conditions and appends it
     * to $groupCell, wiring every row to serialize straight back into $jsonField on any change -
     * there's no separate "Done" step to remember to click, since there's no modal to confirm.
     *
     * @param {jQuery} $groupCell the group_logic field's own (visible) <td> for this row
     * @param {jQuery} $jsonField the hidden group_conditions_json textarea for this row
     */
    function buildInlinePanel($groupCell, $jsonField) {
        var typeOptions = readTypeOptions(),
            existing = [],
            $panel = $('<div class="ordo-group-inline"></div>'),
            $rows = $('<div class="ordo-group-rows"></div>'),
            $addButton = $('<button type="button" class="ordo-group-inline-add">+ Add Condition</button>'),
            sync;

        try {
            existing = JSON.parse($jsonField.val() || '[]');
        } catch (e) {
            // Any parse failure is treated the same way (start from an empty condition list,
            // shown visibly below rather than silently) - e.message surfaced for debugging.
            existing = [];
            $('<div class="ordo-group-json-error-message"></div>')
                .text('This group\'s saved conditions were corrupted and could not be loaded - starting empty. ('
                    + e.message + ')')
                .appendTo($panel);
        }
        if (!Array.isArray(existing)) {
            existing = [];
        }

        sync = function () {
            var conditions = readRows($rows);

            $jsonField.val(JSON.stringify(conditions)).trigger('change').trigger('input');
        };

        existing.forEach(function (condition) {
            appendInlineRow($rows, typeOptions, condition, sync);
        });

        $addButton.on('click', function () {
            appendInlineRow($rows, typeOptions, {type: typeOptions[0].value, params: {}}, sync);
            sync();
        });

        $panel.append($rows).append($addButton);
        $groupCell.append($panel);
    }

    /**
     * Finds every condition row currently set to type "group" and, if it doesn't already have its
     * inline panel built, builds one next to the hidden group_conditions_json field. Re-run on
     * every change to the outer Type select and after every dynamicRows add/delete, since rows
     * (and their type) can change at any time.
     */
    function refreshGroupRows() {
        $('[data-index="conditions"] tr.data-row').each(function () {
            var $row = $(this),
                type = $row.find('[data-index="type"] select').val(),
                $jsonField = $row.find('textarea[name*="[group_conditions_json]"]'),
                // Magento's own knockout binding sets `visible: elem.visible()` on the <td>
                // itself, not just the field div inside it - since group_conditions_json's own
                // visible is permanently false (see ordo_segment_form.xml), its <td> is always
                // display:none too, which would hide anything appended inside it, panel included.
                // group_logic's own <td> stays visible for a 'group' row, so the panel is
                // injected there instead - a sibling field's cell, not this field's own
                // (invisible) one.
                $groupCell = $row.find('[data-index="group_logic"]').closest('td');

            if (!$groupCell.length) {
                return;
            }

            if (type !== 'group') {
                $groupCell.find('.ordo-group-inline').remove();
                return;
            }

            if ($groupCell.find('.ordo-group-inline').length) {
                return;
            }

            buildInlinePanel($groupCell, $jsonField);
        });
    }

    $(document).on('change', '[data-index="conditions"] select', function () {
        // A change on any Type select (including one that just switched a row to/from "group")
        // - re-scan on a tick delay so knockout's own visible-binding toggle for
        // group_conditions_json (see ordo_segment_form.xml's switcherConfig) has already applied
        // before this looks for it. The delegate is scoped to `[data-index="conditions"] select`,
        // which only matches Magento-rendered selects carrying that ancestor - the plain
        // <select> elements this file injects into its own inline rows live inside the same
        // subtree but never trigger a spurious extra rescan beyond the harmless no-op one below,
        // since refreshGroupRows() only rebuilds a panel that isn't built yet.
        setTimeout(refreshGroupRows, 50);
    });

    $(document).on('click', '[data-index="conditions"] button[data-action="add_new_row"], [data-index="conditions"] button.action-delete', function () {
        setTimeout(refreshGroupRows, 150);
    });

    refreshGroupRows();

    // Exposed purely so Test/js/segment-group-modal.test.js can exercise this module's actual
    // logic directly (readTypeOptions/renderValueField/readRows are the parts with real branching
    // - type-with-a-dedicated-field vs the 3 JSON-fallback types, non-empty vs blank values) rather
    // than only indirectly through full DOM/jQuery event simulation. No other module requires()
    // this one for its return value - require(['Ordo_Automation/js/segment-group-modal']) in
    // require_group_modal_js.phtml only ever runs it for its side effects (the $(document).on(...)
    // delegates and the initial refreshGroupRows() call above, both already wired by this point) -
    // so adding a return object here changes nothing about how the module behaves in the browser.
    return {
        readTypeOptions: readTypeOptions,
        renderValueField: renderValueField,
        appendInlineRow: appendInlineRow,
        readRows: readRows,
        buildInlinePanel: buildInlinePanel,
        refreshGroupRows: refreshGroupRows
    };
});
