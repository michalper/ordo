/**
 * SKU autocomplete for the Segment/Campaign "Purchased Product (SKU)" condition's dedicated `sku`
 * field - a trimmed version of free-gift-offer-form.js's own autocomplete half (search-as-you-type
 * dropdown of matching products), reusing the exact same search idiom (plain fetch(),
 * mousedown-before-blur row selection - see that file's own comments for why each of those choices
 * exists) against this module's own Segment-namespaced endpoint
 * (Controller/Adminhtml/Segment/ProductSearch.php) instead of cross-calling FreeGiftOffer's.
 *
 * The debounced input handling the two files once held in common now lives in
 * Ordo_Automation/js/sku-suggest (see its own docblock); everything below is this page's own half -
 * its endpoint, its field-name predicate, its dropdown markup, its focusout handling.
 *
 * Deliberately NOT the bulk "+ Choose from Catalog" modal / chip-rendering half of that file: a
 * purchased_sku condition has exactly one SKU value per condition row, not a repeatable list, so
 * there's nothing to bulk-add and no second value to show a persistent chip for once the field
 * already displays the SKU itself.
 */
/**
 * @param {jQuery} $el
 * @return {Boolean}
 */
function isSkuField($el) {
    var name = $el.attr('name') || '';

    return /^conditions\[conditions]\[\d+]\[sku]$/.test(name);
}

define([
    'jquery',
    'Ordo_Automation/js/sku-suggest',
    'domReady!'
], function ($, skuSuggest) {
    'use strict';

    // window.BASE_URL is already scoped to this module's single admin route (frontName "ordo" -
    // see etc/adminhtml/routes.xml, one <route> covers every controller in the whole module), so
    // it resolves to ".../admin/ordo/" on any page of this module - only "segment/productSearch"
    // (controller + action) belongs after it, same as free-gift-offer-form.js's own
    // 'freegiftoffer/productsearch'. Confirmed directly: an earlier version prefixed "ordo/" here
    // too and every request 404'd against ".../admin/ordo/ordo/segment/productSearch".
    var searchUrl = (window.BASE_URL || '') + 'segment/productsearch';

    /**
     * @param {String} term
     * @return {Promise<Array>} [{sku, name}]
     */
    function searchProducts(term) {
        return fetch(searchUrl + '?term=' + encodeURIComponent(term), {credentials: 'same-origin'})
            .then(function (response) {
                return response.ok ? response.json() : {items: []};
            })
            .then(function (data) {
                return data?.items || [];
            })
            .catch(function () {
                return [];
            });
    }

    function closeDropdown() {
        $('.ordo-sku-suggest').remove();
    }

    /**
     * @param {jQuery} $input
     * @param {Array} items [{sku, name}]
     */
    function renderDropdown($input, items) {
        closeDropdown();

        if (items.length === 0) {
            return;
        }

        var $dropdown = $('<div class="ordo-sku-suggest"></div>');

        items.forEach(function (item) {
            var $row = $('<div class="ordo-sku-suggest-row"></div>')
                .text(item.sku + ' — ' + item.name);

            // mousedown, not click - fires before the input's own blur handler closes the
            // dropdown, same reasoning as free-gift-offer-form.js's identical choice.
            $row.on('mousedown', function (event) {
                event.preventDefault();
                $input.val(item.sku).trigger('input').trigger('change');
                closeDropdown();
            });

            $dropdown.append($row);
        });

        $input.after($dropdown);
    }

    skuSuggest.attach({
        isField: isSkuField,
        search: searchProducts,
        render: renderDropdown,
        close: closeDropdown
    });

    $(document).on('focusout', 'input', function () {
        // Let a suggestion row's own mousedown handler run first (see renderDropdown()'s own
        // comment) - closing on the same tick as blur would remove the dropdown before its click
        // ever registers.
        setTimeout(closeDropdown, 150);
    });

    // Exposed for Test/js/segment-sku-autocomplete.test.js - see segment-group-modal.js's own
    // return statement for why this is safe (side-effect-only module, nothing else requires() its
    // return value).
    return {
        searchProducts: searchProducts,
        closeDropdown: closeDropdown,
        renderDropdown: renderDropdown,
        isSkuField: isSkuField
    };
});
