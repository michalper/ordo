/**
 * The search-as-you-type half both SKU autocompletes in this module share: segment-sku-autocomplete.js
 * (the Segment/Campaign "Purchased Product (SKU)" condition's own `sku` field) and
 * free-gift-offer-form.js (each Products row's `sku` field on the Free Gift Offer form).
 *
 * Those two were written as deliberately independent modules - see segment-sku-autocomplete.js's
 * own docblock for why it doesn't cross-call FreeGiftOffer's endpoint - and they stay independent:
 * everything that actually differs between them (which fields to watch, which controller to search,
 * what a result row looks like, whether picking one also renders a chip) stays with the caller and
 * arrives here as a handler. Only the part that was character-for-character identical in both files
 * lives here: debounce the keystroke, ignore terms under 2 characters, search, and render the result
 * against the input if it's still focused.
 *
 * Extracted because that identical part was 25 lines of copy-paste (SonarCloud flagged it as a
 * duplicated block across the two files), which also meant every fix to it had to be made twice.
 */
define([
    'jquery',
    'underscore'
], function ($, _) {
    'use strict';

    /**
     * Wires one caller's SKU fields up to its own search/render handlers, debounced, for the whole
     * page lifetime - a single delegated `input` listener on document, like each caller had before,
     * so fields added later (a new dynamicRows row, a new condition row) are picked up with no
     * re-attach step.
     *
     * @param {Object} handlers
     * @param {Function} handlers.isField ($input) -> Boolean: is this one of my own SKU inputs?
     * @param {Function} handlers.search (term) -> Promise<Array>: must not reject - both callers'
     *        own searchProducts() resolve to [] on any failure (see each one's own .catch)
     * @param {Function} handlers.render ($input, items) -> void
     * @param {Function} handlers.close () -> void: close the open dropdown, if any
     * @return {Function} the debounced suggest($input), returned so a caller can expose it
     */
    function attach(handlers) {
        var suggest = _.debounce(function ($input) {
            // String(...).trim() instead of jQuery's own $.trim() - removed in jQuery 4 (deprecated
            // since 3.5) in favor of the native method.
            var term = String($input.val()).trim();

            if (term.length < 2) {
                handlers.close();
                return;
            }

            // `void`: handlers.search() swallows its own failures (resolves to []), and a debounced
            // keystroke handler has no caller to hand a promise back to.
            void handlers.search(term).then(function (items) {
                // The field may have lost focus (or its value changed again) while the request was
                // in flight - only render against the input that's still actually focused.
                if ($input.is(':focus')) {
                    handlers.render($input, items);
                }
            });
        }, 300);

        $(document).on('input', 'input', function () {
            var $input = $(this);

            if (handlers.isField($input)) {
                suggest($input);
            }
        });

        return suggest;
    }

    return {
        attach: attach
    };
});
