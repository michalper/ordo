/**
 * Segment Overlap page (view/adminhtml/templates/segment/overlap.phtml,
 * Controller\Adminhtml\Segment\Overlap) - on "Compare", fetches
 * Controller\Adminhtml\Segment\OverlapCompute with the two picked segment ids and renders the
 * sizes/intersection/unique-remainder counts it returns. Plain fetch(), not $.ajax() - same idiom
 * as segment-audience-size.js/segment-sku-autocomplete.js elsewhere in this module.
 */
define([
    'jquery',
    'domReady!'
], function ($) {
    'use strict';

    /**
     * @param {jQuery} $panel the .ordo-segment-overlap wrapper
     * @return {Promise}
     */
    function compute($panel) {
        var url = $panel.data('overlapComputeUrl'),
            segmentIdA = $panel.find('[data-overlap-segment="a"]').val(),
            segmentIdB = $panel.find('[data-overlap-segment="b"]').val(),
            $result = $panel.find('[data-overlap-result]'),
            $error = $panel.find('[data-overlap-error]'),
            $button = $panel.find('[data-overlap-compute]');

        $error.hide();
        $result.hide();

        if (!segmentIdA || !segmentIdB) {
            $error.text('Pick two segments first.').show();
            return Promise.resolve();
        }

        $button.prop('disabled', true);

        return fetch(
            url + '?segment_id_a=' + encodeURIComponent(segmentIdA) + '&segment_id_b=' + encodeURIComponent(segmentIdB),
            {credentials: 'same-origin'}
        )
            .then(function (response) {
                return response.ok ? response.json() : null;
            })
            .then(function (data) {
                if (!data || data.error) {
                    $error.text(data && data.error ? data.error : 'Could not compute overlap.').show();
                    return;
                }

                $panel.find('[data-overlap-size-a]').text(data.size_a);
                $panel.find('[data-overlap-size-b]').text(data.size_b);
                $panel.find('[data-overlap-intersection]').text(data.intersection);
                $panel.find('[data-overlap-unique-a]').text(data.unique_a);
                $panel.find('[data-overlap-unique-b]').text(data.unique_b);
                $result.show();
            })
            .catch(function () {
                $error.text('Could not compute overlap.').show();
            })
            .finally(function () {
                $button.prop('disabled', false);
            });
    }

    $('[data-overlap-compute]').on('click', function () {
        compute($(this).closest('.ordo-segment-overlap'));
    });

    // Exposed for Test/js/segment-overlap.test.js - see segment-group-modal.js's own return
    // statement for why this is safe (side-effect-only module, nothing else requires() its return
    // value).
    return {
        compute: compute
    };
});
