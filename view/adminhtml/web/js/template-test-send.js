/**
 * Template Test Send page (view/adminhtml/templates/templatetestsend/index.phtml,
 * Controller\Adminhtml\TemplateTestSend\{Index,Send}) - toggles which fields are visible per
 * picked channel (message for email/sms, template+params for whatsapp), then posts to Send.php
 * on submit and renders back whatever {success, message} it returns. Plain fetch(), not
 * $.ajax() - same idiom as segment-overlap.js/segment-audience-size.js elsewhere in this module.
 */
define([
    'jquery',
    'domReady!'
], function ($) {
    'use strict';

    /**
     * @param {jQuery} $panel the .ordo-template-test-send wrapper
     */
    function syncVisibleFields($panel) {
        var channel = $panel.find('[data-test-send-channel]').val();

        $panel.find('[data-test-send-field="message"]').toggle(channel === 'email' || channel === 'sms');
        $panel.find('[data-test-send-field="whatsapp"]').toggle(channel === 'whatsapp');
    }

    /**
     * @param {jQuery} $panel the .ordo-template-test-send wrapper
     * @return {Promise}
     */
    function submit($panel) {
        var url = $panel.data('testSendUrl'),
            channel = $panel.find('[data-test-send-channel]').val(),
            $result = $panel.find('[data-test-send-result]'),
            $button = $panel.find('[data-test-send-submit]'),
            params = new URLSearchParams({
                channel: channel,
                to: $panel.find('[data-test-send-to]').val() || '',
                message: $panel.find('[data-test-send-message]').val() || '',
                template_id: $panel.find('[data-test-send-template]').val() || '',
                params: $panel.find('[data-test-send-params]').val() || ''
            });

        $button.prop('disabled', true);
        $result.hide();

        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: params.toString()
        })
            .then(function (response) {
                return response.ok ? response.json() : null;
            })
            .then(function (data) {
                var success = Boolean(data && data.success),
                    message = data && data.message ? data.message : 'The test send failed.';

                $result.text(message)
                    .toggleClass('ordo-template-test-send-result-success', success)
                    .toggleClass('ordo-template-test-send-result-error', !success)
                    .show();
            })
            .catch(function () {
                $result.text('The test send failed.')
                    .removeClass('ordo-template-test-send-result-success')
                    .addClass('ordo-template-test-send-result-error')
                    .show();
            })
            .finally(function () {
                $button.prop('disabled', false);
            });
    }

    $('[data-test-send-channel]').on('change', function () {
        syncVisibleFields($(this).closest('.ordo-template-test-send'));
    });

    $('[data-test-send-submit]').on('click', function () {
        submit($(this).closest('.ordo-template-test-send'));
    });

    $('.ordo-template-test-send').each(function () {
        syncVisibleFields($(this));
    });

    // Exposed for Test/js/template-test-send.test.js - see segment-group-modal.js's own return
    // statement for why this is safe (side-effect-only module, nothing else requires() its return
    // value).
    return {
        syncVisibleFields: syncVisibleFields,
        submit: submit
    };
});
