'use strict';

const QUnit = require('qunit');
const { loadModule } = require('./support/load-module');

const MODULE_PATH = 'view/adminhtml/web/js/tag-chip-editor.js';

function wait(ms) {
    return new Promise(function (resolve) {
        setTimeout(resolve, ms);
    });
}

QUnit.module('Ordo_Automation/js/tag-chip-editor', function () {
    QUnit.test('mount() renders one chip per initial tag', function (assert) {
        const api = loadModule(MODULE_PATH);
        const $container = global.$('<div></div>');
        const $hidden = global.$('<input>');

        api.mount($container, ['vip', 'loyal'], $hidden, function () {
            return Promise.resolve([]);
        });

        const chips = $container.find('.ordo-tag-chip').map(function () {
            return global.$(this).text();
        }).get();

        assert.deepEqual(chips, ['vip×', 'loyal×']);
    });

    QUnit.test('pressing Enter in the input adds a new chip and syncs the hidden input', function (assert) {
        const api = loadModule(MODULE_PATH);
        const $container = global.$('<div></div>');
        const $hidden = global.$('<input>');

        api.mount($container, [], $hidden, function () {
            return Promise.resolve([]);
        });

        const $input = $container.find('.ordo-tag-chip-input');

        $input.val('newtag');
        $input.trigger(global.$.Event('keydown', { key: 'Enter' }));

        assert.equal($hidden.val(), JSON.stringify(['newtag']));
        assert.equal($container.find('.ordo-tag-chip').length, 1);
        assert.equal($input.val(), '');
    });

    QUnit.test('pressing comma in the input commits the typed tag the same as Enter', function (assert) {
        const api = loadModule(MODULE_PATH);
        const $container = global.$('<div></div>');
        const $hidden = global.$('<input>');

        api.mount($container, [], $hidden, function () {
            return Promise.resolve([]);
        });

        const $input = $container.find('.ordo-tag-chip-input');

        $input.val('vip');
        $input.trigger(global.$.Event('keydown', { key: ',' }));

        assert.equal($hidden.val(), JSON.stringify(['vip']));
    });

    QUnit.test('a blank or whitespace-only typed value is not added as a tag', function (assert) {
        const api = loadModule(MODULE_PATH);
        const $container = global.$('<div></div>');
        // Real callers always pre-populate the hidden input with JSON.stringify(initialTags)
        // before mounting (see campaign-flow-editor.js's two-pass render and
        // segment-tag-multiselect.js's enhance()) - mount() itself only ever writes to it on a
        // successful add/remove, never retroactively on load.
        const $hidden = global.$('<input>').val(JSON.stringify([]));

        api.mount($container, [], $hidden, function () {
            return Promise.resolve([]);
        });

        const $input = $container.find('.ordo-tag-chip-input');

        $input.val('   ');
        $input.trigger(global.$.Event('keydown', { key: 'Enter' }));

        assert.equal($hidden.val(), JSON.stringify([]));
        assert.equal($container.find('.ordo-tag-chip').length, 0);
    });

    QUnit.test('an already-selected tag typed again is not added twice', function (assert) {
        const api = loadModule(MODULE_PATH);
        const $container = global.$('<div></div>');
        const $hidden = global.$('<input>').val(JSON.stringify(['vip']));

        api.mount($container, ['vip'], $hidden, function () {
            return Promise.resolve([]);
        });

        const $input = $container.find('.ordo-tag-chip-input');

        $input.val('vip');
        $input.trigger(global.$.Event('keydown', { key: 'Enter' }));

        assert.equal($container.find('.ordo-tag-chip').length, 1);
        assert.equal($hidden.val(), JSON.stringify(['vip']));
    });

    QUnit.test('clicking a chip\'s remove button removes it and syncs the hidden input', function (assert) {
        const api = loadModule(MODULE_PATH);
        const $container = global.$('<div></div>');
        const $hidden = global.$('<input>');

        api.mount($container, ['vip', 'loyal'], $hidden, function () {
            return Promise.resolve([]);
        });

        $container.find('.ordo-tag-chip-remove').first().trigger('click');

        assert.equal($hidden.val(), JSON.stringify(['loyal']));
        assert.equal($container.find('.ordo-tag-chip').length, 1);
        assert.equal($container.find('.ordo-tag-chip').text().replace('×', ''), 'loyal');
    });

    QUnit.test('typing into the search input queries searchFn and renders suggestions, excluding already-selected tags', async function (assert) {
        const api = loadModule(MODULE_PATH);
        const $container = global.$('<div></div>');
        const $hidden = global.$('<input>');
        const searchCalls = [];

        api.mount($container, ['vip'], $hidden, function (term) {
            searchCalls.push(term);
            return Promise.resolve(['vip', 'vip-gold']);
        });

        const $input = $container.find('.ordo-tag-chip-input');

        $input.val('vi').trigger('input');
        await wait(250);

        assert.deepEqual(searchCalls, ['vi']);

        const rows = $container.find('.ordo-tag-chip-suggest-row').map(function () {
            return global.$(this).text();
        }).get();

        assert.deepEqual(rows, ['vip-gold']);
    });

    QUnit.test('clicking a suggestion row adds that tag and closes the dropdown', async function (assert) {
        const api = loadModule(MODULE_PATH);
        const $container = global.$('<div></div>');
        const $hidden = global.$('<input>');

        api.mount($container, [], $hidden, function () {
            return Promise.resolve(['vip']);
        });

        const $input = $container.find('.ordo-tag-chip-input');

        $input.val('vi').trigger('input');
        await wait(250);

        $container.find('.ordo-tag-chip-suggest-row').first().trigger('mousedown');

        assert.equal($hidden.val(), JSON.stringify(['vip']));
        assert.equal($container.find('.ordo-tag-chip-suggest').length, 0);
        assert.equal($input.val(), '');
    });

    QUnit.test('a search with no results renders no dropdown', async function (assert) {
        const api = loadModule(MODULE_PATH);
        const $container = global.$('<div></div>');
        const $hidden = global.$('<input>');

        api.mount($container, [], $hidden, function () {
            return Promise.resolve([]);
        });

        const $input = $container.find('.ordo-tag-chip-input');

        $input.val('zz').trigger('input');
        await wait(250);

        assert.equal($container.find('.ordo-tag-chip-suggest').length, 0);
    });

    QUnit.test('blurring the input closes an open dropdown after a short delay', async function (assert) {
        const api = loadModule(MODULE_PATH);
        const $container = global.$('<div></div>');
        const $hidden = global.$('<input>');

        api.mount($container, [], $hidden, function () {
            return Promise.resolve(['vip']);
        });

        const $input = $container.find('.ordo-tag-chip-input');

        $input.val('vi').trigger('input');
        await wait(250);
        assert.equal($container.find('.ordo-tag-chip-suggest').length, 1, 'dropdown open before blur');

        $input.trigger('blur');
        assert.equal($container.find('.ordo-tag-chip-suggest').length, 1, 'still open immediately after blur');

        await wait(200);
        assert.equal($container.find('.ordo-tag-chip-suggest').length, 0, 'closed once the delay elapses');
    });

    QUnit.test('clearing the search input closes any open dropdown without a new search', async function (assert) {
        const api = loadModule(MODULE_PATH);
        const $container = global.$('<div></div>');
        const $hidden = global.$('<input>');
        let calls = 0;

        api.mount($container, [], $hidden, function () {
            calls++;
            return Promise.resolve(['vip']);
        });

        const $input = $container.find('.ordo-tag-chip-input');

        $input.val('vi').trigger('input');
        await wait(250);
        assert.equal($container.find('.ordo-tag-chip-suggest').length, 1);

        $input.val('').trigger('input');

        assert.equal($container.find('.ordo-tag-chip-suggest').length, 0);
        assert.equal(calls, 1);
    });
});
