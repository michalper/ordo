/**
 * WhatsApp Template Edit page's live character counter + rendered preview
 * (view/adminhtml/templates/whatsapptemplate/bodypreview.phtml) - the "Body Text" field itself
 * is a Magento_Ui form component, rendered asynchronously by the form's own KnockoutJS
 * initialization, so its <textarea name="body_text"> isn't guaranteed to exist yet when this
 * script runs. Rather than reaching into the form's uiRegistry (which only exposes whole-form
 * data, not a live per-keystroke field subscription - see campaign-flow-editor.js's own
 * registry.get(formProviderName, ...) use for the one thing it *is* good for), this polls briefly
 * for the textarea to appear, then a single document-level delegated 'input' listener (same
 * "delegate rather than bind once" idiom as segment-audience-size.js) keeps the panel live for
 * as long as the page is open - simpler and just as correct for a field that, once rendered,
 * never gets removed/re-created.
 */
define([
    'jquery',
    'domReady!'
], function ($) {
    'use strict';

    var POLL_INTERVAL_MS = 100,
        POLL_MAX_ATTEMPTS = 40; // ~4 seconds - generous slack past any real form render time.

    /**
     * @param {String} bodyText
     * @return {Number}
     */
    function charCount(bodyText) {
        return bodyText.length;
    }

    /**
     * Substitutes {{1}}, {{2}}, ... positional placeholders with a generic sample value each -
     * the form has no per-variable sample-value fields to render real examples from, so this is
     * deliberately generic (matching what an admin can expect Meta's own review-time preview to
     * look like), not a fabricated realistic message.
     *
     * @param {String} bodyText
     * @return {String}
     */
    function renderPreview(bodyText) {
        return bodyText.replace(/{{\s*(\d+)\s*}}/g, function (match, index) {
            return '[Sample value ' + index + ']';
        });
    }

    /**
     * @param {jQuery} $panel the .ordo-whatsapp-body-preview wrapper
     * @param {String} bodyText
     */
    function update($panel, bodyText) {
        var max = parseInt($panel.data('bodyPreviewMax'), 10) || 0,
            count = charCount(bodyText),
            $count = $panel.find('[data-body-preview-count]'),
            $text = $panel.find('[data-body-preview-text]');

        $count.text(count + ' / ' + max + ' characters');
        $count.toggleClass('ordo-whatsapp-body-preview-count-over', max > 0 && count > max);
        $text.text(renderPreview(bodyText));
    }

    /**
     * @return {Boolean} true once found and wired up, false if the textarea still isn't rendered
     */
    function attach() {
        var $panel = $('.ordo-whatsapp-body-preview'),
            $textarea = $('textarea[name="body_text"]');

        if (!$panel.length || !$textarea.length) {
            return false;
        }

        update($panel, $textarea.val() || '');

        return true;
    }

    var attempts = 0,
        pollId = setInterval(function () {
            attempts++;

            if (attach() || attempts >= POLL_MAX_ATTEMPTS) {
                clearInterval(pollId);
            }
        }, POLL_INTERVAL_MS);

    $(document).on('input', 'textarea[name="body_text"]', function () {
        update($('.ordo-whatsapp-body-preview'), $(this).val());
    });

    // Exposed for Test/js/whatsapp-template-body-preview.test.js - see segment-group-modal.js's
    // own return statement for why this is safe (side-effect-only module, nothing else requires()
    // its return value).
    return {
        charCount: charCount,
        renderPreview: renderPreview,
        update: update,
        attach: attach
    };
});
