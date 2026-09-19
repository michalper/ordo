'use strict';

const QUnit = require('qunit');
const { loadModule } = require('./support/load-module');

const MODULE_PATH = 'view/adminhtml/web/js/free-gift-offer-form.js';

/**
 * Stubs global.fetch for one call and returns the args it was called with, so a test can assert
 * the request URL/params without a real network round trip - same pattern as
 * Test/js/segment-sku-autocomplete.test.js's own stubFetch().
 *
 * @param {Object} responseInit {ok, json: () => Promise}
 */
function stubFetch(responseInit) {
    const calls = [];

    global.fetch = function () {
        calls.push(Array.from(arguments));

        return Promise.resolve(responseInit);
    };

    return calls;
}

/**
 * Waits for one macrotask tick, by which point Node has already drained every microtask queued
 * so far - same technique Test/js/segment-sku-autocomplete.test.js's own flushPromises() uses.
 */
function flushPromises() {
    return new Promise(function (resolve) {
        setTimeout(resolve, 0);
    });
}

/**
 * addProductRow()/duplicateTierRow() both poll via `await sleep(50)` (setTimeout under the hood)
 * up to 40 times waiting for a new dynamicRows row to render. Rather than waiting up to 2 real
 * seconds per test, this replaces global.setTimeout with a manually-drivable queue: every call is
 * captured instead of scheduled for real, and runPending() resolves them one at a time (awaiting a
 * microtask tick after each so the polling loop's own .then()/await continuation actually runs
 * before the next one is resolved) until the queue drains or the round limit is hit - deterministic
 * regardless of real elapsed time, and self-limiting the same way the real 40-attempt loop is.
 */
function stubManualTimers() {
    const originalSetTimeout = global.setTimeout;
    const pending = [];

    global.setTimeout = function (callback) {
        pending.push(callback);
        return pending.length;
    };

    return {
        async runPending(maxRounds = 40) {
            for (let round = 0; round < maxRounds && pending.length > 0; round++) {
                const callback = pending.shift();

                callback();
                await Promise.resolve();
                await Promise.resolve();
            }
        },
        restore() {
            global.setTimeout = originalSetTimeout;
        }
    };
}

QUnit.module('Ordo_Automation/js/free-gift-offer-form', function () {
    QUnit.test('isProductSkuField() matches a Products row\'s own sku field, nothing else', function (assert) {
        const api = loadModule(MODULE_PATH);

        assert.true(api.isProductSkuField(global.$('<input name="products[products][3][sku]">')));
        assert.false(api.isProductSkuField(global.$('<input name="products[products][3][qty]">')));
        assert.false(api.isProductSkuField(global.$('<input name="conditions[conditions][0][sku]">')));
    });

    QUnit.test('isTierField() matches a Tiers row\'s min_subtotal/gift_slots fields, nothing else', function (assert) {
        const api = loadModule(MODULE_PATH);

        assert.true(api.isTierField(global.$('<input name="tiers[tiers][1][min_subtotal]">')));
        assert.true(api.isTierField(global.$('<input name="tiers[tiers][1][gift_slots]">')));
        assert.false(api.isTierField(global.$('<input name="tiers[tiers][1][other]">')));
    });

    QUnit.test('formatMoney() fixes a numeric value to 2 decimals, leaves a non-numeric value untouched', function (assert) {
        const api = loadModule(MODULE_PATH);

        assert.strictEqual(api.formatMoney(5), '5.00');
        assert.strictEqual(api.formatMoney('19.5'), '19.50');
        assert.strictEqual(api.formatMoney('not-a-number'), 'not-a-number');
    });

    QUnit.test('sleep() resolves via setTimeout with the given delay, not before it fires', async function (assert) {
        const api = loadModule(MODULE_PATH);
        const originalSetTimeout = global.setTimeout;
        let capturedDelay = null;
        let fire = null;

        // Stub setTimeout so the test doesn't depend on real elapsed time (flaky on
        // loaded CI runners); instead assert the *contract*: sleep() schedules a
        // callback with the given delay, and only resolves once that callback runs.
        global.setTimeout = function (callback, delay) {
            capturedDelay = delay;
            fire = callback;
            return 0;
        };

        try {
            let resolved = false;
            const promise = api.sleep(10).then(function () {
                resolved = true;
            });

            assert.strictEqual(capturedDelay, 10);
            assert.false(resolved, 'must not resolve before the timer fires');

            fire();
            await promise;

            assert.true(resolved, 'resolves once the timer fires');
        } finally {
            global.setTimeout = originalSetTimeout;
        }
    });

    QUnit.test('searchProducts() resolves the response\'s items on a 200', async function (assert) {
        const api = loadModule(MODULE_PATH);

        stubFetch({ ok: true, json: () => Promise.resolve({ items: [{ sku: 'ABC', name: 'A Product' }] }) });

        const items = await api.searchProducts('abc');

        assert.deepEqual(items, [{ sku: 'ABC', name: 'A Product' }]);
    });

    QUnit.test('searchProducts() resolves to an empty array when the response has no items key', async function (assert) {
        const api = loadModule(MODULE_PATH);

        stubFetch({ ok: true, json: () => Promise.resolve({}) });

        assert.deepEqual(await api.searchProducts('abc'), []);
    });

    QUnit.test('searchProducts() resolves to an empty array on a non-ok response', async function (assert) {
        const api = loadModule(MODULE_PATH);

        stubFetch({ ok: false });

        assert.deepEqual(await api.searchProducts('abc'), []);
    });

    QUnit.test('renderChip() removes any existing chip and adds nothing when the item has no sku', function (assert) {
        const api = loadModule(
            MODULE_PATH,
            '<div class="admin__field-control"><input id="sku-input"><div class="ordo-picker-chip">stale</div></div>'
        );
        const $input = global.$('#sku-input');

        api.renderChip($input, null);

        assert.strictEqual($input.closest('.admin__field-control').find('.ordo-picker-chip').length, 0);
    });

    QUnit.test('renderChip() renders a chip with the item\'s name and sku', function (assert) {
        const api = loadModule(MODULE_PATH, '<div class="admin__field-control"><input id="sku-input"></div>');
        const $input = global.$('#sku-input');

        api.renderChip($input, { sku: 'XYZ', name: 'A Gift' });

        const $chip = $input.closest('.admin__field-control').find('.ordo-picker-chip');

        assert.strictEqual($chip.length, 1);
        assert.strictEqual($chip.find('.ordo-picker-chip-name').text(), 'A Gift');
        assert.strictEqual($chip.find('.ordo-picker-chip-sku').text(), 'XYZ');
    });

    QUnit.test('renderChip() renders a thumbnail image when the item has one', function (assert) {
        const api = loadModule(MODULE_PATH, '<div class="admin__field-control"><input id="sku-input"></div>');
        const $input = global.$('#sku-input');

        api.renderChip($input, { sku: 'XYZ', name: 'A Gift', thumbnail_url: '/media/xyz.jpg' });

        const $chip = $input.closest('.admin__field-control').find('.ordo-picker-chip');

        assert.strictEqual($chip.find('img.ordo-picker-chip-thumb').attr('src'), '/media/xyz.jpg');
        assert.strictEqual($chip.find('.ordo-picker-chip-noimg').length, 0);
    });

    QUnit.test('renderChip() falls back to the sku as the visible name when the item has none', function (assert) {
        const api = loadModule(MODULE_PATH, '<div class="admin__field-control"><input id="sku-input"></div>');
        const $input = global.$('#sku-input');

        api.renderChip($input, { sku: 'XYZ' });

        assert.strictEqual(
            $input.closest('.admin__field-control').find('.ordo-picker-chip-name').text(),
            'XYZ'
        );
    });

    // ------------------------------------------------------------------
    // hydrateExistingChips() - runs once at load time, resolving each existing row's bare SKU
    // ------------------------------------------------------------------

    QUnit.test('load-time setup renders a chip for an existing row whose SKU resolves to an exact match', async function (assert) {
        stubFetch({ ok: true, json: () => Promise.resolve({ items: [{ sku: '24-MB01', name: 'Blue Shirt' }] }) });

        loadModule(
            MODULE_PATH,
            '<div class="admin__field-control"><input name="products[products][0][sku]" value="24-MB01"></div>'
        );
        await flushPromises();

        assert.strictEqual(global.$('.ordo-picker-chip-name').text(), 'Blue Shirt');
    });

    QUnit.test('load-time setup never searches for a blank existing row', async function (assert) {
        const calls = stubFetch({ ok: true, json: () => Promise.resolve({ items: [] }) });

        loadModule(
            MODULE_PATH,
            '<div class="admin__field-control"><input name="products[products][0][sku]" value=""></div>'
        );
        await flushPromises();

        assert.strictEqual(calls.length, 0);
    });

    QUnit.test('load-time setup renders no chip when the search finds no exact SKU match', async function (assert) {
        stubFetch({ ok: true, json: () => Promise.resolve({ items: [{ sku: 'SOMETHING-ELSE', name: 'Other' }] }) });

        loadModule(
            MODULE_PATH,
            '<div class="admin__field-control"><input name="products[products][0][sku]" value="24-MB01"></div>'
        );
        await flushPromises();

        assert.strictEqual(global.$('.ordo-picker-chip').length, 0);
    });

    // ------------------------------------------------------------------
    // Products autocomplete: typing/focus/blur delegates, same shape as
    // Test/js/segment-sku-autocomplete.test.js's own equivalents
    // ------------------------------------------------------------------

    QUnit.test('typing into a matching product sku field renders a dropdown of results while still focused', async function (assert) {
        loadModule(MODULE_PATH, '<div class="admin__field-control"><input name="products[products][0][sku]"></div>');
        const $input = global.$('input[name="products[products][0][sku]"]');

        stubFetch({ ok: true, json: () => Promise.resolve({ items: [{ sku: '24-MB01', name: 'Blue Shirt' }] }) });

        $input[0].focus();
        $input.val('shirt').trigger('input');
        await flushPromises();

        assert.strictEqual(global.$('.ordo-picker-suggestion').length, 1);
    });

    QUnit.test('a dropdown suggestion with a thumbnail and known qty renders both instead of the fallbacks', async function (assert) {
        loadModule(MODULE_PATH, '<div class="admin__field-control"><input name="products[products][0][sku]"></div>');
        const $input = global.$('input[name="products[products][0][sku]"]');

        stubFetch({
            ok: true,
            json: () => Promise.resolve({
                items: [{ sku: '24-MB01', name: 'Blue Shirt', thumbnail_url: '/media/shirt.jpg', qty: 5 }]
            })
        });

        $input[0].focus();
        $input.val('shirt').trigger('input');
        await flushPromises();

        const $suggestion = global.$('.ordo-picker-suggestion');
        assert.strictEqual($suggestion.find('img').attr('src'), '/media/shirt.jpg');
        assert.strictEqual($suggestion.find('.ordo-picker-suggestion-noimg').length, 0);
        assert.strictEqual($suggestion.find('.ordo-picker-suggestion-qty').text(), 'Qty: 5');
    });

    QUnit.test('a product search response is discarded once the input is no longer focused', async function (assert) {
        loadModule(MODULE_PATH, '<div class="admin__field-control"><input name="products[products][0][sku]"></div>');
        const $input = global.$('input[name="products[products][0][sku]"]');

        stubFetch({ ok: true, json: () => Promise.resolve({ items: [{ sku: '24-MB01', name: 'Blue Shirt' }] }) });

        $input[0].focus();
        $input.val('shirt').trigger('input');
        $input[0].blur();
        await flushPromises();

        assert.strictEqual(global.$('.ordo-picker-suggestion').length, 0);
    });

    QUnit.test('typing into a non-sku product field never triggers a search', async function (assert) {
        loadModule(MODULE_PATH, '<div class="admin__field-control"><input name="products[products][0][qty]"></div>');
        const $input = global.$('input[name="products[products][0][qty]"]');

        const calls = stubFetch({ ok: true, json: () => Promise.resolve({ items: [] }) });

        $input.val('5').trigger('input');
        await flushPromises();

        assert.strictEqual(calls.length, 0);
    });

    QUnit.test('a product search term under 2 characters closes any open dropdown without searching', async function (assert) {
        loadModule(MODULE_PATH, '<div class="admin__field-control"><input name="products[products][0][sku]"></div>');
        const $input = global.$('input[name="products[products][0][sku]"]');

        // Open a real dropdown first (setting the module's own $activeDropdown), so the
        // subsequent short-term closeDropdown() call has something of its own to actually close -
        // a dropdown div appended by the test itself would never be touched, since closeDropdown()
        // only ever removes $activeDropdown.
        stubFetch({ ok: true, json: () => Promise.resolve({ items: [{ sku: '24-MB01', name: 'Blue Shirt' }] }) });
        $input[0].focus();
        $input.val('shirt').trigger('input');
        await flushPromises();
        assert.strictEqual(global.$('.ordo-picker-dropdown').length, 1, 'sanity check: a dropdown is open');

        const calls = stubFetch({ ok: true, json: () => Promise.resolve({ items: [] }) });

        $input.val('a').trigger('input');
        await flushPromises();

        assert.strictEqual(calls.length, 0);
        assert.strictEqual(global.$('.ordo-picker-dropdown').length, 0);
    });

    QUnit.test('a product search with no results renders no dropdown', async function (assert) {
        loadModule(MODULE_PATH, '<div class="admin__field-control"><input name="products[products][0][sku]"></div>');
        const $input = global.$('input[name="products[products][0][sku]"]');

        stubFetch({ ok: true, json: () => Promise.resolve({ items: [] }) });

        $input[0].focus();
        $input.val('shirt').trigger('input');
        await flushPromises();

        assert.strictEqual(global.$('.ordo-picker-dropdown').length, 0);
    });

    QUnit.test('a suggestion row\'s mousedown fills the field, renders its chip, and closes the dropdown', async function (assert) {
        loadModule(MODULE_PATH, '<div class="admin__field-control"><input name="products[products][0][sku]"></div>');
        const $input = global.$('input[name="products[products][0][sku]"]');

        stubFetch({ ok: true, json: () => Promise.resolve({ items: [{ sku: '24-MB01', name: 'Blue Shirt' }] }) });

        $input[0].focus();
        $input.val('shirt').trigger('input');
        await flushPromises();
        global.$('.ordo-picker-suggestion').trigger('mousedown');

        assert.strictEqual($input.val(), '24-MB01');
        assert.strictEqual(global.$('.ordo-picker-dropdown').length, 0);
        assert.strictEqual(global.$('.ordo-picker-chip-name').text(), 'Blue Shirt');
    });

    QUnit.test('losing focus on a product sku field schedules the dropdown to close after the mousedown grace period', async function (assert) {
        loadModule(MODULE_PATH, '<div class="admin__field-control"><input name="products[products][0][sku]"></div>');
        const $input = global.$('input[name="products[products][0][sku]"]');

        // Open a real dropdown first (setting the module's own $activeDropdown) - see the
        // equivalent comment on the "under 2 characters" test above for why.
        stubFetch({ ok: true, json: () => Promise.resolve({ items: [{ sku: '24-MB01', name: 'Blue Shirt' }] }) });
        $input[0].focus();
        $input.val('shirt').trigger('input');
        await flushPromises();

        const originalSetTimeout = global.setTimeout;
        let capturedDelay = null;
        let fire = null;

        global.setTimeout = function (callback, delay) {
            capturedDelay = delay;
            fire = callback;
            return 0;
        };

        try {
            $input.trigger('focusout');

            assert.strictEqual(capturedDelay, 150);
            assert.strictEqual(global.$('.ordo-picker-dropdown').length, 1, 'still open until the timer fires');

            fire();

            assert.strictEqual(global.$('.ordo-picker-dropdown').length, 0);
        } finally {
            global.setTimeout = originalSetTimeout;
        }
    });

    QUnit.test('losing focus on a non-sku field never schedules a dropdown close', function (assert) {
        loadModule(MODULE_PATH, '<div class="admin__field-control"><input name="products[products][0][qty]"></div>');
        const $input = global.$('input[name="products[products][0][qty]"]');
        const originalSetTimeout = global.setTimeout;
        let calls = 0;

        global.setTimeout = function (callback, delay) {
            calls++;
            return 0;
        };

        try {
            $input.trigger('focusout');

            assert.strictEqual(calls, 0);
        } finally {
            global.setTimeout = originalSetTimeout;
        }
    });

    // ------------------------------------------------------------------
    // Tiers: microcopy, "Sort tiers", "Duplicate"
    // ------------------------------------------------------------------

    function tierRowMarkup(index, minSubtotal, giftSlots) {
        return '<tr class="data-row">'
            + '<td><div data-index="min_subtotal"><div class="admin__field-control">'
            + '<input name="tiers[tiers][' + index + '][min_subtotal]" value="' + minSubtotal + '"></div></div></td>'
            + '<td><div data-index="gift_slots"><div class="admin__field-control">'
            + '<input name="tiers[tiers][' + index + '][gift_slots]" value="' + giftSlots + '"></div></div></td>'
            + '</tr>';
    }

    QUnit.test('load-time setup adds own labels, microcopy, and a duplicate button to each tier row', function (assert) {
        loadModule(
            MODULE_PATH,
            '<div data-index="tiers"><table><tbody>' + tierRowMarkup(0, '50', '2') + '</tbody></table>'
            + '<button data-action="add_new_row"></button></div>'
        );

        const $row = global.$('[data-index="tiers"] tr.data-row');

        assert.strictEqual($row.find('.ordo-tier-own-label').length, 2);
        assert.strictEqual($row.find('.ordo-tier-note').text(), 'Customer gets 2 gifts after reaching $50.00.');
        assert.strictEqual($row.find('.ordo-tier-duplicate').length, 1);
    });

    QUnit.test('the tier note uses singular wording for exactly one gift slot', function (assert) {
        loadModule(
            MODULE_PATH,
            '<div data-index="tiers"><table><tbody>' + tierRowMarkup(0, '25', '1') + '</tbody></table></div>'
        );

        assert.strictEqual(global.$('.ordo-tier-note').text(), 'Customer gets 1 gift after reaching $25.00.');
    });

    QUnit.test('the tier note is cleared for an invalid gift slot count and re-rendered once fixed', function (assert) {
        loadModule(
            MODULE_PATH,
            '<div data-index="tiers"><table><tbody>' + tierRowMarkup(0, '25', '0') + '</tbody></table></div>'
        );

        assert.strictEqual(global.$('.ordo-tier-note').text(), '');

        global.$('input[name$="[gift_slots]"]').val('3').trigger('input');

        assert.strictEqual(global.$('.ordo-tier-note').text(), 'Customer gets 3 gifts after reaching $25.00.');
    });

    QUnit.test('clicking "Sort tiers by subtotal" reorders row values ascending and refreshes their microcopy', function (assert) {
        loadModule(
            MODULE_PATH,
            '<div data-index="tiers"><table><tbody>'
            + tierRowMarkup(0, '100', '3') + tierRowMarkup(1, '25', '1')
            + '</tbody></table><button data-action="add_new_row"></button></div>'
        );

        global.$('.ordo-tier-sort-button').trigger('click');

        const $rows = global.$('[data-index="tiers"] tr.data-row');

        assert.strictEqual($rows.eq(0).find('input[name$="[min_subtotal]"]').val(), '25');
        assert.strictEqual($rows.eq(0).find('input[name$="[gift_slots]"]').val(), '1');
        assert.strictEqual($rows.eq(0).find('.ordo-tier-note').text(), 'Customer gets 1 gift after reaching $25.00.');
        assert.strictEqual($rows.eq(1).find('input[name$="[min_subtotal]"]').val(), '100');
        assert.strictEqual($rows.eq(1).find('input[name$="[gift_slots]"]').val(), '3');
    });

    QUnit.test('"Sort tiers by subtotal" treats a blank min_subtotal as 0 instead of NaN', function (assert) {
        loadModule(
            MODULE_PATH,
            '<div data-index="tiers"><table><tbody>'
            + tierRowMarkup(0, '100', '3') + tierRowMarkup(1, '', '1') + tierRowMarkup(2, '50', '2')
            + '</tbody></table><button data-action="add_new_row"></button></div>'
        );

        global.$('.ordo-tier-sort-button').trigger('click');

        const $rows = global.$('[data-index="tiers"] tr.data-row');

        assert.strictEqual($rows.eq(0).find('input[name$="[min_subtotal]"]').val(), '', 'the blank row sorts first, as 0');
        assert.strictEqual($rows.eq(1).find('input[name$="[min_subtotal]"]').val(), '50');
        assert.strictEqual($rows.eq(2).find('input[name$="[min_subtotal]"]').val(), '100');
    });

    QUnit.test('clicking a tier row\'s "Duplicate" button copies its values into a newly added row', async function (assert) {
        loadModule(
            MODULE_PATH,
            '<div data-index="tiers"><table><tbody>' + tierRowMarkup(0, '50', '2') + '</tbody></table>'
            + '<button data-action="add_new_row"></button></div>'
        );

        // Simulates dynamicRows' own reaction to its "Add" button - duplicateTierRow() triggers
        // this same click expecting a real dynamicRows widget to append a row in response.
        global.$('[data-index="tiers"] button[data-action="add_new_row"]').on('click', function () {
            global.$('[data-index="tiers"] tbody').append(tierRowMarkup(1, '', ''));
        });

        const timers = stubManualTimers();

        try {
            global.$('.ordo-tier-duplicate').first().trigger('click');
            await timers.runPending();
        } finally {
            timers.restore();
        }

        const $rows = global.$('[data-index="tiers"] tr.data-row');

        assert.strictEqual($rows.length, 2);
        assert.strictEqual($rows.eq(1).find('input[name$="[min_subtotal]"]').val(), '50');
        assert.strictEqual($rows.eq(1).find('input[name$="[gift_slots]"]').val(), '2');
    });

    // ------------------------------------------------------------------
    // "+ Choose from Catalog" bulk-add modal
    // ------------------------------------------------------------------

    function productsMarkup() {
        return '<div data-index="products"><table><tbody></tbody></table>'
            + '<button data-action="add_new_row"></button></div>';
    }

    QUnit.test('load-time setup adds the "+ Choose from Catalog" button next to the products Add button', function (assert) {
        loadModule(MODULE_PATH, productsMarkup());

        assert.strictEqual(global.$('.ordo-picker-bulk-button').length, 1);
    });

    QUnit.test('the bulk picker modal searches and renders matching products', async function (assert) {
        loadModule(MODULE_PATH, productsMarkup());
        stubFetch({ ok: true, json: () => Promise.resolve({ items: [{ sku: '24-MB01', name: 'Blue Shirt' }] }) });

        global.$('.ordo-picker-bulk-button').trigger('click');
        global.$('.ordo-picker-modal-search').val('shirt').trigger('input');
        await flushPromises();

        assert.strictEqual(global.$('.ordo-picker-modal-row').length, 1);
        assert.strictEqual(global.$('.ordo-picker-suggestion-name').text(), 'Blue Shirt');
    });

    QUnit.test('a bulk picker modal row with a thumbnail and known qty renders both instead of the fallbacks', async function (assert) {
        loadModule(MODULE_PATH, productsMarkup());
        stubFetch({
            ok: true,
            json: () => Promise.resolve({
                items: [{ sku: '24-MB01', name: 'Blue Shirt', thumbnail_url: '/media/shirt.jpg', qty: 5 }]
            })
        });

        global.$('.ordo-picker-bulk-button').trigger('click');
        global.$('.ordo-picker-modal-search').val('shirt').trigger('input');
        await flushPromises();

        const $row = global.$('.ordo-picker-modal-row');
        assert.strictEqual($row.find('img').attr('src'), '/media/shirt.jpg');
        assert.strictEqual($row.find('.ordo-picker-suggestion-noimg').length, 0);
        assert.strictEqual($row.find('.ordo-picker-suggestion-qty').text(), 'Qty: 5');
    });

    QUnit.test('the bulk picker modal shows an empty-state message when nothing matches', async function (assert) {
        loadModule(MODULE_PATH, productsMarkup());
        stubFetch({ ok: true, json: () => Promise.resolve({ items: [] }) });

        global.$('.ordo-picker-bulk-button').trigger('click');
        global.$('.ordo-picker-modal-search').val('shirt').trigger('input');
        await flushPromises();

        assert.strictEqual(global.$('.ordo-picker-modal-empty').length, 1);
    });

    QUnit.test('the bulk picker modal search clears results instead of searching under 2 characters', async function (assert) {
        loadModule(MODULE_PATH, productsMarkup());
        const calls = stubFetch({ ok: true, json: () => Promise.resolve({ items: [] }) });

        global.$('.ordo-picker-bulk-button').trigger('click');
        global.$('.ordo-picker-modal-search').val('s').trigger('input');
        await flushPromises();

        assert.strictEqual(calls.length, 0);
        assert.strictEqual(global.$('.ordo-picker-modal-results').children().length, 0);
    });

    QUnit.test('checking results and clicking "Add Selected" adds each picked product as a new row', async function (assert) {
        loadModule(MODULE_PATH, productsMarkup());
        stubFetch({ ok: true, json: () => Promise.resolve({ items: [{ sku: '24-MB01', name: 'Blue Shirt' }] }) });

        // Simulates dynamicRows' own reaction to the products "Add" button.
        global.$('[data-index="products"] button[data-action="add_new_row"]').on('click', function () {
            global.$('[data-index="products"] tbody').append(
                '<tr class="data-row"><td><div class="admin__field-control">'
                + '<input name="products[products][0][sku]"></div></td></tr>'
            );
        });

        global.$('.ordo-picker-bulk-button').trigger('click');
        global.$('.ordo-picker-modal-search').val('shirt').trigger('input');
        await flushPromises();
        global.$('.ordo-picker-modal-row input[type="checkbox"]').prop('checked', true);

        const timers = stubManualTimers();

        try {
            global.$('.ordo-test-modal-buttons button').eq(0).trigger('click');
            await timers.runPending();
        } finally {
            timers.restore();
        }

        assert.strictEqual(
            global.$('[data-index="products"] input[name$="[sku]"]').val(),
            '24-MB01'
        );
    });

    QUnit.test('clicking "Cancel" closes the bulk picker modal without adding anything', function (assert) {
        loadModule(MODULE_PATH, productsMarkup());

        global.$('.ordo-picker-bulk-button').trigger('click');
        global.$('.ordo-test-modal-buttons button').eq(1).trigger('click');

        assert.strictEqual(global.$('[data-index="products"] tr.data-row').length, 0);
    });
});
