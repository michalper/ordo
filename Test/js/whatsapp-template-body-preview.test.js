'use strict';

const QUnit = require('qunit');
const { loadModule } = require('./support/load-module');

const MODULE_PATH = 'view/adminhtml/web/js/whatsapp-template-body-preview.js';

function panelHtml() {
    return '<div class="ordo-whatsapp-body-preview" data-body-preview-max="1024">'
        + '<p data-body-preview-count="1">0 / 1024 characters</p>'
        + '<div data-body-preview-text="1"></div>'
        + '</div>'
        + '<textarea name="body_text"></textarea>';
}

QUnit.module('Ordo_Automation/js/whatsapp-template-body-preview', function () {
    QUnit.test('charCount() returns the string length', function (assert) {
        const api = loadModule(MODULE_PATH, panelHtml());

        assert.strictEqual(api.charCount(''), 0);
        assert.strictEqual(api.charCount('Hello'), 5);
    });

    QUnit.test('renderPreview() substitutes {{1}}, {{2}}, ... with generic sample values', function (assert) {
        const api = loadModule(MODULE_PATH, panelHtml());

        assert.strictEqual(
            api.renderPreview('Hi {{1}}, your order {{2}} shipped!'),
            'Hi [Sample value 1], your order [Sample value 2] shipped!'
        );
    });

    QUnit.test('renderPreview() leaves text with no placeholders unchanged', function (assert) {
        const api = loadModule(MODULE_PATH, panelHtml());

        assert.strictEqual(api.renderPreview('Plain text, nothing to fill in.'), 'Plain text, nothing to fill in.');
    });

    QUnit.test('update() renders the count and the substituted preview', function (assert) {
        const api = loadModule(MODULE_PATH, panelHtml());

        api.update(global.$('.ordo-whatsapp-body-preview'), 'Hi {{1}}!');

        assert.strictEqual(global.$('[data-body-preview-count]').text(), '9 / 1024 characters');
        assert.strictEqual(global.$('[data-body-preview-text]').text(), 'Hi [Sample value 1]!');
        assert.false(global.$('[data-body-preview-count]').hasClass('ordo-whatsapp-body-preview-count-over'));
    });

    QUnit.test('update() flags the count as over-limit once the body exceeds the max', function (assert) {
        const api = loadModule(MODULE_PATH, panelHtml());

        api.update(global.$('.ordo-whatsapp-body-preview'), 'x'.repeat(1025));

        assert.true(global.$('[data-body-preview-count]').hasClass('ordo-whatsapp-body-preview-count-over'));
    });

    QUnit.test('attach() finds the rendered textarea and renders its current value', function (assert) {
        const api = loadModule(MODULE_PATH, panelHtml());
        global.$('textarea[name="body_text"]').val('Hi {{1}}!');

        assert.true(api.attach());
        assert.strictEqual(global.$('[data-body-preview-text]').text(), 'Hi [Sample value 1]!');
    });

    QUnit.test('attach() returns false when the panel or textarea isn\'t rendered yet', function (assert) {
        const api = loadModule(MODULE_PATH, '<div class="ordo-whatsapp-body-preview" data-body-preview-max="1024">'
            + '<p data-body-preview-count="1"></p><div data-body-preview-text="1"></div></div>');

        assert.false(api.attach());
    });

    QUnit.test('typing into the textarea updates the panel live', function (assert) {
        loadModule(MODULE_PATH, panelHtml());

        global.$('textarea[name="body_text"]').val('Hi {{1}}!').trigger('input');

        assert.strictEqual(global.$('[data-body-preview-text]').text(), 'Hi [Sample value 1]!');
        assert.strictEqual(global.$('[data-body-preview-count]').text(), '9 / 1024 characters');
    });
});
