/**
 * Tag chip editor for the dedicated `tags` field of the "Has Tag"/"Visitor Has Tag" conditions
 * and the "add_tag" action, wherever a declarative Magento_Ui dynamicRows form renders one as a
 * plain input - ordo_segment_form.xml's own conditions list, and ordo_campaign_form.xml's own
 * conditions AND actions lists (the collapsible "Campaign Information" raw/advanced form
 * underneath the Flow canvas - see campaign/flow.phtml's own docblock on how the canvas submits
 * through this same form's provider). Kept under this "segment-" prefixed filename despite being
 * shared across both pages - same established naming drift segment-sku-autocomplete.js already
 * has (also loaded on both pages), not worth an unrelated rename.
 *
 * Same field-name-matching DOM-enhancement convention segment-sku-autocomplete.js already
 * established, but that file's own delegated keystroke-driven sku-suggest.js attach() doesn't fit
 * here: a tag field needs its chip widget mounted once a row exists, not reactively on the user's
 * first keystroke into a plain input (the raw `tags` input becomes a hidden JSON-array
 * value-holder once mounted, same role tag-chip-editor.js's hidden input plays in
 * campaign-flow-editor.js - see that file's own docblock). A MutationObserver on the whole page
 * body, not a periodic poll or a second dynamicRows-specific hook, is the simplest reliable
 * "a matching field now exists" signal that covers every dynamicRows list this module has (there
 * are three across the two pages: segment conditions, campaign conditions, campaign actions) -
 * Magento_Ui's dynamicRows has no afterRender callback this module's own JS already taps into for
 * anything else, and re-rendering is otherwise exactly the sort of DOM-churn built for.
 */
define([
    'jquery',
    'Ordo_Automation/js/tag-chip-editor',
    'domReady!'
], function ($, tagChipEditor) {
    'use strict';

    // conditions[conditions][N][tags] - ordo_segment_form.xml's and ordo_campaign_form.xml's own
    // conditions lists (Has Tag / Visitor Has Tag). actions[actions][N][tags] -
    // ordo_campaign_form.xml's own actions list (add_tag). Both dynamicRows blocks use their own
    // name as the dataScope too, which is what doubles the path segment.
    var TAG_FIELD_NAME = /^(conditions\[conditions]|actions\[actions])\[\d+]\[tags]$/,
        ENHANCED_CLASS = 'ordo-tag-chip-enhanced';

    // window.BASE_URL is already scoped to this module's single admin route - see
    // segment-sku-autocomplete.js's own comment on the identical convention.
    var searchUrl = (window.BASE_URL || '') + 'tag/search';

    /**
     * @param {String} term
     * @return {Promise<Array<String>>}
     */
    function searchTags(term) {
        return fetch(searchUrl + '?term=' + encodeURIComponent(term), {credentials: 'same-origin'})
            .then(function (response) {
                return response.ok ? response.json() : {items: []};
            })
            .then(function (data) {
                return (data?.items || []).map(function (item) {
                    return item.tag;
                });
            })
            .catch(function () {
                return [];
            });
    }

    /**
     * @param {HTMLInputElement} input
     */
    function enhance(input) {
        var $input = $(input);

        if ($input.hasClass(ENHANCED_CLASS)) {
            return;
        }
        $input.addClass(ENHANCED_CLASS);

        var raw = $input.val(),
            initialTags = [];

        try {
            var decoded = raw ? JSON.parse(raw) : [];
            initialTags = Array.isArray(decoded) ? decoded : [];
        } catch (e) {
            // A malformed or pre-existing non-JSON value (e.g. a legacy comma-separated string
            // from before this field became a chip editor) just starts the editor with no tags
            // instead of failing to render at all - the hidden input is fully overwritten on the
            // next add/remove anyway, so there's nothing to recover from the raw value.
            initialTags = [];
        }

        var $editor = $('<div class="ordo-tag-chip-editor-mount"></div>');

        $input.hide().after($editor);
        tagChipEditor.mount($editor, initialTags, $input, searchTags);
    }

    function scan() {
        $('input[name]').each(function () {
            var name = $(this).attr('name') || '';

            if (TAG_FIELD_NAME.test(name)) {
                enhance(this);
            }
        });
    }

    new MutationObserver(scan).observe(document.body, {childList: true, subtree: true});
    scan();

    return {
        scan: scan,
        enhance: enhance,
        isTagsField: function (name) {
            return TAG_FIELD_NAME.test(name);
        }
    };
});
