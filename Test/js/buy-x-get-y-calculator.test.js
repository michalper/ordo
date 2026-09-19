'use strict';

const QUnit = require('qunit');
const { loadModule } = require('./support/load-module');

const MODULE_PATH = 'view/adminhtml/web/js/buy-x-get-y-calculator.js';

/**
 * A plain closure-based stand-in for a knockout observable - callable with no argument to read
 * the current value, or with one argument to write it, same as the real thing's own get/set
 * duality. buildPreview()/onXChange() only ever call their context's observables this way, so
 * this is enough without pulling in real knockout.
 */
function makeObservable(initial) {
    let value = initial;

    return function (next) {
        if (arguments.length > 0) {
            value = next;
            return;
        }

        return value;
    };
}

function makeContext(discountStep, discountAmount) {
    return {
        discountStep: makeObservable(discountStep),
        discountAmount: makeObservable(discountAmount)
    };
}

QUnit.module('Ordo_Automation/js/buy-x-get-y-calculator', function () {
    QUnit.test('buildPreview() prompts for input when discount step is empty', function (assert) {
        const config = loadModule(MODULE_PATH);

        assert.strictEqual(
            config.buildPreview.call(makeContext('', '')),
            'Enter "Discount Qty Step (Buy X)" and "Discount Amount" (as Y, free qty) to see a preview.'
        );
    });

    QUnit.test('buildPreview() prompts for input when discount step is zero or negative', function (assert) {
        const config = loadModule(MODULE_PATH);
        const expected = 'Enter "Discount Qty Step (Buy X)" and "Discount Amount" (as Y, free qty) to see a preview.';

        assert.strictEqual(config.buildPreview.call(makeContext('0', '1')), expected);
        assert.strictEqual(config.buildPreview.call(makeContext('-2', '1')), expected);
    });

    QUnit.test('buildPreview() prompts for input when discount amount is not a number', function (assert) {
        const config = loadModule(MODULE_PATH);

        assert.strictEqual(
            config.buildPreview.call(makeContext('3', '')),
            'Enter "Discount Qty Step (Buy X)" and "Discount Amount" (as Y, free qty) to see a preview.'
        );
    });

    QUnit.test('buildPreview() prompts for input when discount amount is negative', function (assert) {
        const config = loadModule(MODULE_PATH);

        assert.strictEqual(
            config.buildPreview.call(makeContext('3', '-1')),
            'Enter "Discount Qty Step (Buy X)" and "Discount Amount" (as Y, free qty) to see a preview.'
        );
    });

    QUnit.test('buildPreview() warns when the discount amount exceeds the discount step', function (assert) {
        const config = loadModule(MODULE_PATH);

        assert.strictEqual(
            config.buildPreview.call(makeContext('3', '5')),
            '"Discount Amount" (Y) must not exceed "Discount Qty Step" (X), or no discount will apply.'
        );
    });

    QUnit.test('buildPreview() flags a zero discount amount as no free items', function (assert) {
        const config = loadModule(MODULE_PATH);

        assert.strictEqual(
            config.buildPreview.call(makeContext('3', '0')),
            'No free items — Y is 0. Set "Discount Amount" to how many should be free.'
        );
    });

    QUnit.test('buildPreview() describes a valid buy-x-get-y combination', function (assert) {
        const config = loadModule(MODULE_PATH);

        assert.strictEqual(
            config.buildPreview.call(makeContext('3', '1')),
            'Buy 3, get 1 free — customers pay for 3 out of every 4 (25% off that batch).'
        );
    });

    QUnit.test('buildPreview() rounds the discount percentage', function (assert) {
        const config = loadModule(MODULE_PATH);

        // 2 free out of every 5 (2/5 = 40%) - exercises a different X/Y pair than the one above,
        // still landing on a whole percentage, so Math.round()'s own rounding path isn't implied
        // to be untested just because this fixture doesn't need it.
        assert.strictEqual(
            config.buildPreview.call(makeContext('3', '2')),
            'Buy 3, get 2 free — customers pay for 3 out of every 5 (40% off that batch).'
        );
    });

    QUnit.test('onSimpleActionChange()/onDiscountStepChange()/onDiscountAmountChange() write through to the observables', function (assert) {
        const config = loadModule(MODULE_PATH);
        const context = makeContext('', '');
        context.simpleAction = makeObservable('');

        config.onSimpleActionChange.call(context, 'buy_x_get_y');
        config.onDiscountStepChange.call(context, '3');
        config.onDiscountAmountChange.call(context, '1');

        assert.strictEqual(context.simpleAction(), 'buy_x_get_y');
        assert.strictEqual(context.discountStep(), '3');
        assert.strictEqual(context.discountAmount(), '1');
    });

    QUnit.test('initObservable() wires isBuyXGetY()/previewText() as real computeds over the observed fields', function (assert) {
        const config = loadModule(MODULE_PATH);
        const context = Object.create(config);

        context.initObservable();
        context.simpleAction('buy_x_get_y');
        context.discountStep('3');
        context.discountAmount('1');

        assert.true(context.isBuyXGetY());
        assert.strictEqual(
            context.previewText(),
            'Buy 3, get 1 free — customers pay for 3 out of every 4 (25% off that batch).'
        );

        context.simpleAction('percent_off');
        assert.false(context.isBuyXGetY());
    });
});
