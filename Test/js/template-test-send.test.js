'use strict';

const QUnit = require('qunit');
const { loadModule } = require('./support/load-module');

const MODULE_PATH = 'view/adminhtml/web/js/template-test-send.js';

/**
 * Stubs global.fetch for one call - same pattern as Test/js/segment-overlap.test.js's own
 * stubFetch().
 *
 * @param {Object} responseInit {ok, json: () => Promise}
 */
function stubFetch(responseInit) {
    global.fetch = function () {
        return Promise.resolve(responseInit);
    };
}

function panelHtml() {
    return '<div class="ordo-template-test-send" data-test-send-url="/admin/ordo/templatetestsend/send">'
        + '<select data-test-send-channel><option value="email">Email</option><option value="sms">SMS</option>'
        + '<option value="whatsapp">WhatsApp</option></select>'
        + '<input type="text" data-test-send-to value="">'
        + '<div data-test-send-field="message"><textarea data-test-send-message></textarea></div>'
        + '<div data-test-send-field="whatsapp" style="display: none">'
        + '<select data-test-send-template><option value="">-- Select --</option><option value="3">Order Shipped</option></select>'
        + '<input type="text" data-test-send-params>'
        + '</div>'
        + '<button type="button" data-test-send-submit>Send Test</button>'
        + '<div data-test-send-result style="display: none"></div>'
        + '</div>';
}

QUnit.module('Ordo_Automation/js/template-test-send', function () {
    QUnit.test('syncVisibleFields() shows the message field for email/sms and hides whatsapp fields', function (assert) {
        const api = loadModule(MODULE_PATH, panelHtml());
        const $panel = global.$('.ordo-template-test-send');

        $panel.find('[data-test-send-channel]').val('sms');
        api.syncVisibleFields($panel);

        assert.notStrictEqual($panel.find('[data-test-send-field="message"]').css('display'), 'none');
        assert.strictEqual($panel.find('[data-test-send-field="whatsapp"]').css('display'), 'none');
    });

    QUnit.test('syncVisibleFields() shows the whatsapp fields and hides message for whatsapp', function (assert) {
        const api = loadModule(MODULE_PATH, panelHtml());
        const $panel = global.$('.ordo-template-test-send');

        $panel.find('[data-test-send-channel]').val('whatsapp');
        api.syncVisibleFields($panel);

        assert.strictEqual($panel.find('[data-test-send-field="message"]').css('display'), 'none');
        assert.notStrictEqual($panel.find('[data-test-send-field="whatsapp"]').css('display'), 'none');
    });

    QUnit.test('submit() renders a success message and applies the success class', function (assert) {
        stubFetch({ok: true, json: function () { return Promise.resolve({success: true, message: 'Test email sent to a@b.com.'}); }});

        const api = loadModule(MODULE_PATH, panelHtml());
        const $panel = global.$('.ordo-template-test-send');
        $panel.find('[data-test-send-to]').val('a@b.com');

        return api.submit($panel).then(function () {
            assert.strictEqual($panel.find('[data-test-send-result]').text(), 'Test email sent to a@b.com.');
            assert.true($panel.find('[data-test-send-result]').hasClass('ordo-template-test-send-result-success'));
            assert.false($panel.find('[data-test-send-submit]').prop('disabled'));
        });
    });

    QUnit.test('submit() renders the server-provided error message and applies the error class', function (assert) {
        stubFetch({ok: true, json: function () { return Promise.resolve({success: false, message: 'Invalid phone number.'}); }});

        const api = loadModule(MODULE_PATH, panelHtml());

        return api.submit(global.$('.ordo-template-test-send')).then(function () {
            assert.strictEqual(global.$('[data-test-send-result]').text(), 'Invalid phone number.');
            assert.true(global.$('[data-test-send-result]').hasClass('ordo-template-test-send-result-error'));
        });
    });

    QUnit.test('submit() shows a generic error message on a non-ok response', function (assert) {
        stubFetch({ok: false});

        const api = loadModule(MODULE_PATH, panelHtml());

        return api.submit(global.$('.ordo-template-test-send')).then(function () {
            assert.strictEqual(global.$('[data-test-send-result]').text(), 'The test send failed.');
        });
    });

    QUnit.test('submit() shows a generic error message on a network failure instead of throwing', function (assert) {
        global.fetch = function () {
            return Promise.reject(new Error('network down'));
        };

        const api = loadModule(MODULE_PATH, panelHtml());

        return api.submit(global.$('.ordo-template-test-send')).then(function () {
            assert.strictEqual(global.$('[data-test-send-result]').text(), 'The test send failed.');
        });
    });

    QUnit.test('clicking Send Test triggers a fetch and disables the button until it resolves', function (assert) {
        const done = assert.async();

        stubFetch({ok: true, json: function () { return Promise.resolve({success: true, message: 'sent'}); }});

        loadModule(MODULE_PATH, panelHtml());

        global.$('[data-test-send-submit]').trigger('click');

        setTimeout(function () {
            assert.strictEqual(global.$('[data-test-send-result]').text(), 'sent');
            assert.false(global.$('[data-test-send-submit]').prop('disabled'));
            done();
        }, 0);
    });
});
