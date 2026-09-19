/**
 * UX pass for the Free Gift Offer edit form's Tiers and Products dynamicRows (agreed directly,
 * point by point):
 *  - Products: type a name/SKU, see live suggestions with thumbnail/name/stock, pick one and it
 *    shows as a small "chip" card instead of a bare SKU string with no visual confirmation.
 *  - "+ Choose from Catalog": a modal listing/searching products with checkboxes, for adding
 *    several at once instead of one "Add SKU" click per product.
 *  - Tiers: a "Sort tiers" button (ascending by minimum cart subtotal) and a "Duplicate" button
 *    per row, plus inline microcopy under each row ("Customer gets N gift(s) after reaching $X")
 *    so the tier's own logic is readable without doing the math by hand.
 *
 * Deliberately does NOT touch dynamicRows' internal Knockout row-management API (adding/
 * reordering/removing rows via its own observableArray) except through its own public surface
 * (clicking its own "Add" button, letting its own delete column remove a row) - "Sort tiers" and
 * product-picker row fills work by reading/writing the same plain <input> values a person typing
 * into the form would, dispatching real input/change events so Knockout's own bindings pick up
 * the change the normal way. Safer than reaching into dynamicRows' private state, at the cost of
 * being a little more verbose.
 */
/**
 * @param {jQuery} $el
 * @return {Boolean}
 */
function isProductSkuField($el) {
    var name = $el.attr('name') || '';
    return (/^products\[products]\[\d+]\[sku]$/).test(name);
}

function sleep(ms) {
    return new Promise(function (resolve) { setTimeout(resolve, ms); });
}

function formatMoney(value) {
    var num = Number.parseFloat(value);
    return Number.isNaN(num) ? value : num.toFixed(2);
}

/**
 * @param {jQuery} $el
 * @return {Boolean}
 */
function isTierField($el) {
    var name = $el.attr('name') || '';
    return (/^tiers\[tiers]\[\d+]\[(min_subtotal|gift_slots)]$/).test(name);
}

define([
    'jquery',
    'underscore',
    'Magento_Ui/js/modal/modal',
    'domReady!'
], function ($, _) {
    'use strict';

    var searchUrl = (window.BASE_URL || '') + 'freegiftoffer/productsearch';

    /**
     * Plain fetch(), not $.ajax() - something on this specific admin page (a ui_component form,
     * unlike the plain phtml pages this module's other custom AJAX already runs on) rewrites
     * $.ajax's own `data` option to `{isAjax: true}` before the request goes out, silently
     * dropping the real "term" param and turning every search into a request for nothing.
     * Confirmed directly: a raw fetch() with the exact same URL/params, from the exact same
     * page, returns the correct results every time; $.ajax with identical options does not.
     *
     * @param {String} term
     * @return {Promise<Array>}
     */
    function searchProducts(term) {
        return fetch(searchUrl + '?term=' + encodeURIComponent(term), { credentials: 'same-origin' })
            .then(function (response) { return response.ok ? response.json() : { items: [] }; })
            .then(function (data) { return data?.items || []; })
            .catch(function () { return []; });
    }

    // ------------------------------------------------------------------
    // Autocomplete dropdown + chip for each Products row's SKU field
    // ------------------------------------------------------------------

    var $activeDropdown = null;

    function closeDropdown() {
        if ($activeDropdown) {
            $activeDropdown.remove();
            $activeDropdown = null;
        }
    }

    /**
     * @param {jQuery} $input
     * @param {Object} item {sku, name, qty, thumbnail_url}
     */
    function setSkuFieldValue($input, item) {
        $input.val(item.sku).trigger('input').trigger('change');
        renderChip($input, item);
    }

    /**
     * @param {jQuery} $input
     * @param {Object|null} item
     */
    function renderChip($input, item) {
        var $control = $input.closest('.admin__field-control'),
            $chip = $control.find('.ordo-picker-chip');

        if (!item?.sku) {
            $chip.remove();
            return;
        }

        if (!$chip.length) {
            $chip = $('<div class="ordo-picker-chip"></div>');
            $control.append($chip);
        }

        $chip.empty().append(
            item.thumbnail_url
                ? $('<img>').attr('src', item.thumbnail_url).addClass('ordo-picker-chip-thumb')
                : $('<span class="ordo-picker-chip-thumb ordo-picker-chip-noimg"></span>'),
            $('<span class="ordo-picker-chip-name"></span>').text(item.name || item.sku),
            $('<span class="ordo-picker-chip-sku"></span>').text(item.sku)
        );
    }

    /**
     * @param {jQuery} $input
     * @param {Array} items
     */
    function renderDropdown($input, items) {
        closeDropdown();

        if (!items.length) {
            return;
        }

        var $list = $('<div class="ordo-picker-dropdown"></div>');

        items.forEach(function (item) {
            var $row = $('<div class="ordo-picker-suggestion"></div>').append(
                item.thumbnail_url
                    ? $('<img>').attr('src', item.thumbnail_url)
                    : $('<span class="ordo-picker-suggestion-noimg"></span>'),
                $('<span class="ordo-picker-suggestion-name"></span>').text(item.name),
                $('<span class="ordo-picker-suggestion-sku"></span>').text(item.sku),
                $('<span class="ordo-picker-suggestion-qty"></span>').text(
                    item.qty !== null && item.qty !== undefined ? ('Qty: ' + item.qty) : ''
                )
            );

            // mousedown (not click) fires before the input's blur handler closes the dropdown.
            $row.on('mousedown', function (event) {
                event.preventDefault();
                setSkuFieldValue($input, item);
                closeDropdown();
            });

            $list.append($row);
        });

        $input.closest('.admin__field-control').css('position', 'relative').append($list);
        $activeDropdown = $list;
    }

    var debouncedSuggest = _.debounce(function ($input) {
        // String(...).trim() instead of jQuery's own $.trim() - removed in jQuery 4 (deprecated
        // since 3.5) in favor of the native method.
        var term = String($input.val()).trim();

        if (term.length < 2) {
            closeDropdown();
            return;
        }

        searchProducts(term).then(function (items) {
            // The field may have changed (or lost focus) while the request was in flight.
            if ($input.is(':focus')) {
                renderDropdown($input, items);
            }
        });
    }, 300);

    $(document).on('input', 'input', function () {
        var $input = $(this);
        if (isProductSkuField($input)) {
            debouncedSuggest($input);
        }
    });

    $(document).on('focusout', 'input', function () {
        var $input = $(this);
        if (isProductSkuField($input)) {
            // Let a suggestion's mousedown handler run first.
            setTimeout(closeDropdown, 150);
        }
    });

    /**
     * Existing rows (editing a saved offer) only have a bare SKU value on load - resolve each to
     * a real name/thumbnail once so the chip renders immediately instead of only after the next
     * time someone edits that field.
     */
    function hydrateExistingChips() {
        $('input[name^="products[products]"][name$="[sku]"]').each(function () {
            var $input = $(this),
                sku = String($input.val()).trim();

            if (sku === '') {
                return;
            }

            searchProducts(sku).then(function (items) {
                var exact = _.findWhere(items, { sku: sku });
                if (exact) {
                    renderChip($input, exact);
                }
            });
        });
    }

    // ------------------------------------------------------------------
    // "+ Choose from Catalog" bulk-add modal
    // ------------------------------------------------------------------

    function getExistingSkus() {
        return $('input[name^="products[products]"][name$="[sku]"]')
            .map(function () { return String($(this).val() ?? '').trim(); })
            .get()
            .filter(function (sku) { return sku !== ''; });
    }

    /**
     * Clicks the Products dynamicRows' own "Add SKU" button and waits for the resulting new row
     * to actually render, then fills it - reusing dynamicRows' own row-creation path instead of
     * fabricating a row's markup by hand.
     *
     * @param {Object} item
     */
    async function addProductRow(item) {
        var $table = $('[data-index="products"]'),
            beforeCount = $table.find('tbody tr.data-row').length,
            attempt;

        $table.find('button[data-action="add_new_row"]').trigger('click');

        for (attempt = 0; attempt < 40; attempt++) {
            await sleep(50); // NOSONAR: polling for a Knockout re-render, not a timing attack
            var $rows = $table.find('tbody tr.data-row');
            if ($rows.length > beforeCount) {
                setSkuFieldValue($rows.last().find('input[name$="[sku]"]'), item);
                return;
            }
        }
    }

    /**
     * @param {Array} items
     */
    async function addSelectedProducts(items) {
        var existing = getExistingSkus(),
            item,
            i;

        for (i = 0; i < items.length; i++) {
            item = items[i];
            if (!existing.includes(item.sku)) {
                await addProductRow(item);
                existing.push(item.sku);
            }
        }
    }

    function buildModalMarkup() {
        return $(
            '<div class="ordo-picker-modal">' +
                '<input type="text" class="admin__control-text ordo-picker-modal-search" ' +
                    'placeholder="Search by name or SKU…">' +
                '<div class="ordo-picker-modal-results"></div>' +
            '</div>'
        );
    }

    function renderModalResults($modal, items) {
        var $results = $modal.find('.ordo-picker-modal-results').empty();

        if (!items.length) {
            $results.append('<p class="ordo-picker-modal-empty">No matching products.</p>');
            return;
        }

        items.forEach(function (item) {
            var $row = $('<label class="ordo-picker-modal-row"></label>').append(
                $('<input type="checkbox">').data('item', item),
                item.thumbnail_url
                    ? $('<img>').attr('src', item.thumbnail_url)
                    : $('<span class="ordo-picker-suggestion-noimg"></span>'),
                $('<span class="ordo-picker-suggestion-name"></span>').text(item.name),
                $('<span class="ordo-picker-suggestion-sku"></span>').text(item.sku),
                $('<span class="ordo-picker-suggestion-qty"></span>').text(
                    item.qty !== null && item.qty !== undefined ? ('Qty: ' + item.qty) : ''
                )
            );
            $results.append($row);
        });
    }

    function openBulkPickerModal() {
        var $modalContent = buildModalMarkup(),
            debouncedModalSearch = _.debounce(function (term) {
                if (term.length < 2) {
                    $modalContent.find('.ordo-picker-modal-results').empty();
                    return;
                }
                searchProducts(term).then(function (items) {
                    renderModalResults($modalContent, items);
                });
            }, 300);

        $modalContent.find('.ordo-picker-modal-search').on('input', function () {
            debouncedModalSearch(String($(this).val()).trim());
        });

        $modalContent.modal({
            title: 'Choose Products from Catalog',
            modalClass: 'ordo-picker-modal-wrapper',
            buttons: [
                {
                    // Magento's modal widget calls each button's click handler with `this`
                    // bound to the clicked <button> itself, not the modal content - $(this)
                    // .modal('closeModal') was calling .modal() on a plain button that was
                    // never initialized as a modal widget, which threw ("cannot call methods
                    // on modal prior to initialization") and silently aborted the whole
                    // handler, so nothing after it (adding the selected products) ever ran.
                    text: 'Add Selected',
                    class: 'action-primary',
                    click: function () {
                        var selected = $modalContent.find('.ordo-picker-modal-results input:checked')
                            .map(function () { return $(this).data('item'); })
                            .get();

                        $modalContent.modal('closeModal');
                        addSelectedProducts(selected);
                    }
                },
                {
                    text: 'Cancel',
                    click: function () { $modalContent.modal('closeModal'); }
                }
            ]
        }).modal('openModal');
    }

    function injectBulkPickerButton() {
        var $table = $('[data-index="products"]'),
            $addButton = $table.find('button[data-action="add_new_row"]');

        if (!$addButton.length || $addButton.siblings('.ordo-picker-bulk-button').length) {
            return;
        }

        $('<button type="button" class="ordo-picker-bulk-button">+ Choose from Catalog</button>')
            .on('click', openBulkPickerModal)
            .insertAfter($addButton);
    }

    // ------------------------------------------------------------------
    // Tiers: microcopy, "Sort tiers", "Duplicate" per row
    // ------------------------------------------------------------------

    /**
     * @param {jQuery} $row
     */
    function updateTierMicrocopy($row) {
        var subtotal = $row.find('input[name$="[min_subtotal]"]').val(),
            slots = $row.find('input[name$="[gift_slots]"]').val(),
            // The gift_slots field's own cell, NOT the row's last <td> - actionDelete's own cell
            // is now the last one (it's declared after gift_slots in the form XML), and stuffing
            // the microcopy/duplicate button in there fought with the delete icon for space.
            $targetCell = $row.find('[data-index="gift_slots"]').closest('td'),
            $note = $row.find('.ordo-tier-note'),
            subtotalNum = Number.parseFloat(subtotal),
            slotsNum = Number.parseInt(slots, 10);

        if (!$note.length) {
            $note = $('<div class="ordo-tier-note"></div>');
            $targetCell.append($note);
        }

        if (Number.isNaN(subtotalNum) || Number.isNaN(slotsNum) || slotsNum <= 0) {
            $note.text('');
            return;
        }

        $note.text(
            'Customer gets ' + slotsNum + (slotsNum === 1 ? ' gift' : ' gifts') +
            ' after reaching $' + formatMoney(subtotalNum) + '.'
        );
    }

    $(document).on('input change', 'input', function () {
        var $input = $(this);
        if (isTierField($input)) {
            updateTierMicrocopy($input.closest('tr.data-row'));
        }
    });

    /**
     * Reads every tier row's current values, sorts them ascending by minimum cart subtotal, and
     * writes the sorted values back into the SAME rows in place (real input/change events, so
     * Knockout's own bindings update normally) - reordering displayed VALUES rather than
     * reaching into dynamicRows' internal row-management API to reorder the rows themselves.
     */
    function sortTiers() {
        var $rows = $('[data-index="tiers"] tbody tr.data-row'),
            values = $rows.map(function () {
                var $row = $(this);
                return {
                    min_subtotal: $row.find('input[name$="[min_subtotal]"]').val(),
                    gift_slots: $row.find('input[name$="[gift_slots]"]').val()
                };
            }).get();

        values.sort(function (a, b) {
            return (Number.parseFloat(a.min_subtotal) || 0) - (Number.parseFloat(b.min_subtotal) || 0);
        });

        $rows.each(function (index) {
            var $row = $(this),
                sorted = values[index];

            $row.find('input[name$="[min_subtotal]"]').val(sorted.min_subtotal).trigger('input').trigger('change');
            $row.find('input[name$="[gift_slots]"]').val(sorted.gift_slots).trigger('input').trigger('change');
            updateTierMicrocopy($row);
        });
    }

    /**
     * Duplicates one tier row's current values into a brand-new row appended via dynamicRows'
     * own "Add Tier" button (same reasoning as addProductRow() above: reuse the real add path).
     *
     * @param {jQuery} $sourceRow
     */
    async function duplicateTierRow($sourceRow) {
        var $table = $('[data-index="tiers"]'),
            beforeCount = $table.find('tbody tr.data-row').length,
            sourceValues = {
                min_subtotal: $sourceRow.find('input[name$="[min_subtotal]"]').val(),
                gift_slots: $sourceRow.find('input[name$="[gift_slots]"]').val()
            },
            attempt;

        $table.find('button[data-action="add_new_row"]').trigger('click');

        for (attempt = 0; attempt < 40; attempt++) {
            await sleep(50); // NOSONAR: polling for a Knockout re-render, not a timing attack
            var $rows = $table.find('tbody tr.data-row');
            if ($rows.length > beforeCount) {
                var $newRow = $rows.last();
                $newRow.find('input[name$="[min_subtotal]"]')
                    .val(sourceValues.min_subtotal).trigger('input').trigger('change');
                $newRow.find('input[name$="[gift_slots]"]')
                    .val(sourceValues.gift_slots).trigger('input').trigger('change');
                updateTierMicrocopy($newRow);
                return;
            }
        }
    }

    /**
     * Magento's own field label for these two inputs stays hidden (its default) rather than
     * forced visible - see free-gift-offer-form.css's own comment on why fighting its float/
     * width/text-align rules kept producing a broken-looking label. This renders a plain,
     * fully-owned label above the input instead, once per field.
     *
     * @param {jQuery} $row
     * @param {String} fieldIndex 'min_subtotal' | 'gift_slots'
     * @param {String} text
     */
    function ensureOwnLabel($row, fieldIndex, text) {
        var $control = $row.find('[data-index="' + fieldIndex + '"] .admin__field-control');

        if ($control.find('.ordo-tier-own-label').length) {
            return;
        }

        $('<label class="ordo-tier-own-label"></label>').text(text).prependTo($control);
    }

    function injectTierRowControls() {
        $('[data-index="tiers"] tbody tr.data-row').each(function () {
            var $row = $(this),
                $targetCell = $row.find('[data-index="gift_slots"]').closest('td');

            ensureOwnLabel($row, 'min_subtotal', 'Minimum cart subtotal');
            ensureOwnLabel($row, 'gift_slots', 'Gift slots this tier adds');
            updateTierMicrocopy($row);

            if ($targetCell.find('.ordo-tier-duplicate').length) {
                return;
            }

            $('<button type="button" class="ordo-tier-duplicate" title="Duplicate this tier">Duplicate</button>')
                .on('click', function () { duplicateTierRow($row); })
                .appendTo($targetCell);
        });
    }

    function injectSortTiersButton() {
        var $table = $('[data-index="tiers"]'),
            $addButton = $table.find('button[data-action="add_new_row"]');

        if (!$addButton.length || $addButton.siblings('.ordo-tier-sort-button').length) {
            return;
        }

        $('<button type="button" class="ordo-tier-sort-button">Sort tiers by subtotal</button>')
            .on('click', sortTiers)
            .insertAfter($addButton);
    }

    // ------------------------------------------------------------------
    // One-time setup + a lightweight poll for dynamicRows re-renders (adding/removing a row
    // doesn't fire a plain DOM event this module can listen for from the outside, so periodic
    // re-application is simpler and safer than hooking Knockout's own afterRender internals).
    // ------------------------------------------------------------------

    hydrateExistingChips();
    injectBulkPickerButton();
    injectSortTiersButton();
    injectTierRowControls();

    var pollHandle = setInterval(function () {
        injectBulkPickerButton();
        injectSortTiersButton();
        injectTierRowControls();
    }, 800);

    // In a real browser this interval is meant to run for the page's whole lifetime, so there's
    // nothing to unref there (and browser timer handles don't have .unref() anyway). But this
    // module is require()'d as-is by Test/js/free-gift-offer-form.test.js under QUnit/Node, where
    // an un-unref'd interval keeps the event loop alive forever after the tests finish - the
    // process never exits, which just hangs (not fails) `npm run test:js:coverage` in CI.
    if (typeof pollHandle.unref === 'function') {
        pollHandle.unref();
    }

    // Exposed for Test/js/free-gift-offer-form.test.js - see segment-group-modal.js's own return
    // statement for why this is safe (side-effect-only module, nothing else requires() its own
    // return value).
    return {
        isProductSkuField: isProductSkuField,
        sleep: sleep,
        formatMoney: formatMoney,
        isTierField: isTierField,
        searchProducts: searchProducts,
        renderChip: renderChip
    };
});
