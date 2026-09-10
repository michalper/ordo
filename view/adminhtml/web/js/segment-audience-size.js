/**
 * "Estimated Audience Size" panel (audiencesize.phtml) - fetches the segment's live member count
 * from Controller\Adminhtml\Segment\AudienceSize on an explicit "Refresh" click, never
 * automatically (see AudienceSize.php's own docblock for why: SegmentMemberResolver runs real
 * aggregate queries, so this is deliberately opt-in rather than fired on page load or on every
 * condition edit). Plain fetch(), not $.ajax() - same idiom as segment-sku-autocomplete.js/
 * free-gift-offer-form.js elsewhere in this module (see those files' own comments for why).
 *
 * Also tracks whether the page has any unsaved edit since load: the count this panel shows is
 * always resolved from the segment's *saved* conditions (AudienceSize.php reads them from the
 * DB, not from unsubmitted form fields), so an admin who edits a condition then clicks Refresh
 * without saving first would otherwise see a live-looking number that actually still reflects
 * the old, saved definition - a real reported confusion. Any input/change anywhere on the page
 * outside this panel (and outside the unrelated bulk-actions panel) marks the page dirty and
 * shows a warning next to the count; there's no way to reliably detect "saved" client-side short
 * of the full-page reload a real form submit already causes, so this warning only ever needs to
 * turn on, never back off, within one edit session.
 */
define([
    'jquery',
    'domReady!'
], function ($) {
    'use strict';

    var isDirty = false;

    /**
     * @param {jQuery} $panel the .ordo-audience-size-panel wrapper
     * @return {Promise}
     */
    function refresh($panel) {
        var segmentId = $panel.data('segmentId'),
            url = $panel.data('audienceSizeUrl'),
            $value = $panel.find('[data-audience-size-value]'),
            $button = $panel.find('[data-audience-size-refresh]');

        $button.prop('disabled', true);
        $value.text('Calculating...');

        return fetch(url + '?segment_id=' + encodeURIComponent(segmentId), {credentials: 'same-origin'})
            .then(function (response) {
                return response.ok ? response.json() : null;
            })
            .then(function (data) {
                var count = data && typeof data.count === 'number' ? data.count : null;

                $value.text(count === null ? 'Could not calculate audience size.' : count + ' customer(s)');
            })
            .catch(function () {
                $value.text('Could not calculate audience size.');
            })
            .finally(function () {
                $button.prop('disabled', false);
            });
    }

    /**
     * @param {jQuery} $panel the .ordo-audience-size-panel wrapper
     */
    function markDirty($panel) {
        if (isDirty) {
            return;
        }

        isDirty = true;
        $panel.find('[data-audience-size-unsaved-warning]').show();
    }

    $('[data-audience-size-refresh]').on('click', function () {
        refresh($(this).closest('.ordo-audience-size-panel'));
    });

    $(document).on('input change', function (e) {
        var $target = $(e.target);

        if ($target.closest('.ordo-audience-size-panel, .ordo-bulk-actions-panel').length) {
            return;
        }

        markDirty($('.ordo-audience-size-panel'));
    });

    // Exposed for Test/js/segment-audience-size.test.js - see segment-group-modal.js's own return
    // statement for why this is safe (side-effect-only module, nothing else requires() its return
    // value).
    return {
        refresh: refresh,
        markDirty: markDirty
    };
});
