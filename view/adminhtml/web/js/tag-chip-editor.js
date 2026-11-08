/**
 * Shared chip-list tag picker: removable pill per selected tag, free-text entry (Enter or comma
 * commits a typed value even with no matching suggestion - tags aren't a closed enum, a merchant
 * can always introduce a brand-new one), and debounced autocomplete suggestions from a caller-
 * supplied search function. Mounted two different ways by two different pages:
 *  - campaign-flow-editor.js's 'tag_multiselect' field type (vanilla Drawflow canvas, mounts
 *    directly into a placeholder div - same two-pass render/enhance shape as that file's own
 *    renderVariantEditor()).
 *  - segment-tag-multiselect.js (a thin per-page wrapper, same field-name-regex DOM-enhancement
 *    convention segment-sku-autocomplete.js already established for the Magento_Ui-rendered
 *    segment form).
 *
 * Deliberately NOT a Knockout/Magento_Ui component - this module's own precedent for a
 * plain-input-enhanced-by-jQuery-after-render approach already exists twice in this codebase
 * (segment-sku-autocomplete.js, the event_only_warning tooltip work), and a true Magento_Ui
 * component class would be considerably more code for the same result on the one page
 * (ordo_segment_form.xml) that needs it.
 *
 * The caller owns a hidden `[data-field]` input whose `.val()` this module keeps in sync as
 * `JSON.stringify(tags)` on every add/remove - the generic field-collection code on both pages
 * already reads that like any other field (see CampaignSaveProcessor/SegmentSaveProcessor's own
 * JSON_DEDICATED_PARAM_FIELDS, which json_decode() it back into an array server-side). None of
 * the DOM nodes this module creates (chips, remove buttons, the text input) carry their own
 * `data-field` attribute, for the same reason renderVariantEditor()'s own nested controls don't -
 * collectNodeFields()'s `[data-field]` search would otherwise wrongly fold them into the row.
 */
define([
    'jquery',
    'mage/translate'
], function ($) {
    'use strict';

    var $t = $.mage.__;

    /**
     * @param {jQuery} $container
     * @param {Array} initialTags
     * @param {jQuery} $hiddenInput
     * @param {Function} searchFn (term: String) => Promise<Array<String>>
     */
    function mount($container, initialTags, $hiddenInput, searchFn) {
        var tags = Array.isArray(initialTags) ? initialTags.slice() : [],
            debounceTimer = null;

        function closeDropdown() {
            $container.find('.ordo-tag-chip-suggest').remove();
        }

        function render() {
            var $chips = $container.find('.ordo-tag-chip-list');

            $chips.empty();
            tags.forEach(function (tag) {
                var $chip = $('<span class="ordo-tag-chip"></span>').text(tag),
                    $remove = $('<button type="button" class="ordo-tag-chip-remove" aria-label="' +
                        $('<div>').text($t('Remove tag')).html() + '">×</button>');

                $remove.on('click', function () {
                    removeTag(tag);
                });
                $chip.append($remove);
                $chips.append($chip);
            });
        }

        function sync() {
            $hiddenInput.val(JSON.stringify(tags)).trigger('change');
            render();
        }

        function isNotSelected(tag) {
            return !tags.includes(tag);
        }

        function addTag(rawTag) {
            var tag = String(rawTag || '').trim();

            if (tag === '' || !isNotSelected(tag)) {
                return;
            }
            tags.push(tag);
            sync();
        }

        function removeTag(tag) {
            tags = tags.filter(function (t) {
                return t !== tag;
            });
            sync();
        }

        function renderDropdown($input, items) {
            closeDropdown();

            if (items.length === 0) {
                return;
            }

            var $dropdown = $('<div class="ordo-tag-chip-suggest"></div>');

            items.forEach(function (tag) {
                var $row = $('<div class="ordo-tag-chip-suggest-row"></div>').text(tag);

                // mousedown (not click) fires before the input's blur, same reasoning
                // segment-sku-autocomplete.js's own row selection already documents - otherwise
                // blur's own closeDropdown() removes the row before a click ever lands on it.
                $row.on('mousedown', function (e) {
                    e.preventDefault();
                    addTag(tag);
                    $input.val('');
                    closeDropdown();
                });
                $dropdown.append($row);
            });

            $container.find('.ordo-tag-chip-input-wrap').append($dropdown);
        }

        function onInputKeydown(e) {
            var $input = $(this);

            if (e.key === 'Enter' || e.key === ',') {
                e.preventDefault();
                addTag($input.val());
                $input.val('');
                closeDropdown();
            }
        }

        function onInput() {
            var $input = $(this),
                term = $input.val().trim();

            clearTimeout(debounceTimer);

            if (term === '') {
                closeDropdown();
                return;
            }

            debounceTimer = setTimeout(function () {
                searchFn(term).then(function (items) {
                    renderDropdown($input, items.filter(isNotSelected));
                });
            }, 200);
        }

        $container.empty().addClass('ordo-tag-chip-editor');

        var $chipList = $('<div class="ordo-tag-chip-list"></div>'),
            $inputWrap = $('<div class="ordo-tag-chip-input-wrap"></div>'),
            $input = $('<input type="text" class="ordo-tag-chip-input" placeholder="' +
                $('<div>').text($t('Add a tag…')).html() + '">');

        $input.on('keydown', onInputKeydown);
        $input.on('input', onInput);
        // Delayed, not instant - a mousedown on a suggestion row (above) needs to land before
        // this blur-triggered close removes the row out from under it.
        $input.on('blur', function () {
            setTimeout(closeDropdown, 150);
        });

        $inputWrap.append($input);
        $container.append($chipList).append($inputWrap);

        render();
    }

    return {
        mount: mount
    };
});
