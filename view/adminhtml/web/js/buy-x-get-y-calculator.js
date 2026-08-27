/**
 * Live preview for the native "Buy X Get Y" cart price rule action — reads the same
 * discount_step (X)/discount_amount (Y) fields Magento\SalesRule\Model\Rule\Action\Discount\
 * BuyXGetY::calculate() uses, purely as a read-only UI aid. No new discount logic here; the
 * math below only mirrors BuyXGetY::calculate()'s own validity check (X > 0, Y <= X) so the
 * preview never promises something the native calculator wouldn't actually apply.
 */
define([
    'jquery',
    'Magento_Ui/js/form/element/abstract',
    'ko',
    'mage/translate'
], function ($, Abstract, ko) {
    'use strict';

    var $t = $.mage.__;

    return Abstract.extend({
        defaults: {
            elementTmpl: 'Ordo_Automation/buy-x-get-y-calculator',
            visible: true,
            simpleAction: '',
            discountStep: '',
            discountAmount: '',
            imports: {
                onSimpleActionChange: '${ $.parentName }.simple_action:value',
                onDiscountStepChange: '${ $.parentName }.discount_step:value',
                onDiscountAmountChange: '${ $.parentName }.discount_amount:value'
            }
        },

        /** @inheritdoc */
        initObservable: function () {
            this._super().observe(['simpleAction', 'discountStep', 'discountAmount']);

            this.isBuyXGetY = ko.computed(function () {
                return this.simpleAction() === 'buy_x_get_y';
            }, this);

            this.previewText = ko.computed(this.buildPreview, this);

            return this;
        },

        /**
         * @param {String} value
         */
        onSimpleActionChange: function (value) {
            this.simpleAction(value);
        },

        /**
         * @param {String} value
         */
        onDiscountStepChange: function (value) {
            this.discountStep(value);
        },

        /**
         * @param {String} value
         */
        onDiscountAmountChange: function (value) {
            this.discountAmount(value);
        },

        /**
         * Mirrors the guard in Magento\SalesRule\Model\Rule\Action\Discount\BuyXGetY::calculate()
         * ("if (!$x || $y > $x) return no discount") so the preview never shows a combination
         * the native calculator would silently skip.
         *
         * @return {String}
         */
        buildPreview: function () {
            var x = parseInt(this.discountStep(), 10),
                y = parseInt(this.discountAmount(), 10),
                total;

            if (!x || x <= 0 || isNaN(y) || y < 0) {
                return $t('Enter "Discount Qty Step (Buy X)" and "Discount Amount" (as Y, free qty) to see a preview.');
            }

            if (y > x) {
                return $t('"Discount Amount" (Y) must not exceed "Discount Qty Step" (X), or no discount will apply.');
            }

            total = x + y;

            if (y === 0) {
                return $t('No free items — Y is 0. Set "Discount Amount" to how many should be free.');
            }

            return $t('Buy %1, get %2 free — customers pay for %1 out of every %3 (%4% off that batch).')
                .replace('%1', x)
                .replace('%2', y)
                .replace('%1', x)
                .replace('%3', total)
                .replace('%4', Math.round((y / total) * 100));
        }
    });
});
