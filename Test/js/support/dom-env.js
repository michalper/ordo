'use strict';

const { JSDOM } = require('jsdom');
const jqueryModulePath = require.resolve('jquery');

/**
 * Builds a fresh jsdom document + jQuery bound to it, and installs both as globals - the AMD
 * modules under test (view/**\/web/js/*.js) assume a global `$`/`jQuery` and a global `document`,
 * same as they get for real from Magento_Ui's own RequireJS bundle in the browser. Called once per
 * test to get an isolated DOM: reusing one jsdom instance across tests would leak state (event
 * delegates bound in one test firing during another) exactly the way a shared browser tab would.
 *
 * jQuery 4's own dist/jquery.js dropped the re-callable `factory(window)` export its UMD wrapper
 * used to return in a CommonJS environment with no window global (jQuery 3 and earlier) - it now
 * unconditionally runs `factory(global, true)` at require() time and throws immediately if
 * `global.document` isn't already set. Getting a *fresh* $ bound to a *new* jsdom window each test
 * therefore means setting `global.window`/`global.document` BEFORE requiring jquery, and clearing
 * jquery's own require.cache entry first so its module-level UMD code actually re-runs against the
 * window just set, rather than returning the same cached $ from a previous test's now-discarded
 * document.
 *
 * @param {String} [bodyHtml] initial markup for <body>
 * @return {{dom: JSDOM, $: Function}}
 */
function createDomEnv(bodyHtml) {
    const dom = new JSDOM(`<!doctype html><html><body>${bodyHtml || ''}</body></html>`);

    global.window = dom.window;
    global.document = dom.window.document;

    delete require.cache[jqueryModulePath];
    const $ = require('jquery');

    global.$ = $;
    global.jQuery = $;

    return { dom: dom, $: $ };
}

module.exports = { createDomEnv: createDomEnv };
