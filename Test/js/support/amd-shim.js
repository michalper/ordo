'use strict';

/**
 * Minimal AMD `define()` shim so a RequireJS module under view/**\/web/js can be loaded directly
 * by Node's own `require()` in tests, without pulling in a full RequireJS/Node loader just to
 * resolve two dependencies these modules actually declare: 'jquery' and the 'domReady!' loader
 * plugin (which none of them consume as a factory parameter - they only list it so their own
 * top-level code doesn't run until the DOM exists, see each module's own `define([...])` call).
 *
 * Resolves 'jquery' to whatever `$` Test/js/support/dom-env.js's createDomEnv() put on `global.$`,
 * and 'domReady!' to a no-op (safe: jsdom's DOM already exists by the time a test requires the
 * module, so there's nothing left for the real domReady! plugin to defer).
 *
 * @param {Function} loadModule call require(path) for the AMD module under this shim active
 * @return {*} whatever the module's factory function returned
 */
function loadAmdModule(loadModule) {
    let exported;

    global.define = function (deps, factory) {
        const resolved = deps.map(function (dep) {
            if (dep === 'jquery') {
                return global.$;
            }
            if (dep === 'domReady!') {
                return null;
            }
            if (dep === 'underscore') {
                // A minimal stand-in for underscore's own debounce(): calls straight through with
                // no actual delay. Tests exercise the named functions a module builds around its
                // debounced wrapper directly (searchProducts/isSkuField/renderDropdown, see each
                // module's own return statement) rather than the debounce timing itself, so a
                // real timer isn't needed here and would only make tests slower/flakier.
                return {
                    debounce: function (fn) {
                        return function () {
                            return fn.apply(this, arguments);
                        };
                    },
                    // Only the exact-match lookup free-gift-offer-form.js's hydrateExistingChips()
                    // needs - a plain linear scan for the first item whose properties all match.
                    findWhere: function (list, properties) {
                        return (list || []).find(function (item) {
                            return Object.keys(properties).every(function (key) {
                                return item[key] === properties[key];
                            });
                        });
                    }
                };
            }
            if (dep === 'Magento_Ui/js/modal/modal') {
                // Real usage only imports this for its side effect: registering $.fn.modal() on
                // jQuery. openModal/closeModal are no-ops (jsdom has no real dialog to show/hide),
                // but a call with an options object still appends the modal content to
                // document.body (so a test can find it via ordinary document-rooted selectors
                // afterwards, the only way to reach it once it's only reachable by clicking a
                // module's own "open" button) and renders each of `buttons` as a real, clickable
                // <button> - free-gift-offer-form.js's own "Add Selected"/"Cancel" buttons need to
                // actually run their click handlers for a test to drive that flow end to end, same
                // as a real Magento admin page would. Matches the real widget's own contract of
                // invoking each button's click handler with `this` bound to the clicked <button>
                // itself (see that file's own comment on why that binding specifically matters).
                global.$.fn.modal = global.$.fn.modal || function (optionsOrAction) {
                    if (optionsOrAction === 'openModal' || optionsOrAction === 'closeModal') {
                        return this;
                    }

                    var options = optionsOrAction || {};
                    var $buttonBar = global.$('<div class="ordo-test-modal-buttons"></div>');

                    (options.buttons || []).forEach(function (button) {
                        var $button = global.$('<button type="button"></button>').text(button.text);

                        $button.on('click', function () {
                            button.click.call($button[0]);
                        });
                        $buttonBar.append($button);
                    });

                    global.$(global.document.body).append(this).append($buttonBar);

                    return this;
                };

                return undefined;
            }
            if (dep === 'uiRegistry') {
                // Stub matching Magento_Ui's own registry.get(name, callback) shape. A test that
                // wants to drive the "Apply flow to form" save path registers a fake provider by
                // name first (global.__uiRegistryProviders['x'] = {set: ..., save: ...}) - one
                // shared lookup table rather than a per-load closure, since `registry` itself is
                // never exposed to a test (it's an internal closure variable inside campaign-flow-
                // editor.js's own define() factory, only ever used later, when a test clicks the
                // Apply button, not at module-load time). A test that doesn't register anything
                // gets the same no-op behavior every other test here already relies on.
                return {
                    get: function (name, callback) {
                        var provider = (global.__uiRegistryProviders || {})[name];

                        if (provider) {
                            callback(provider);
                        }
                    }
                };
            }
            if (dep === 'drawflow') {
                // The REAL vendored Drawflow library (view/adminhtml/web/lib/drawflow/
                // drawflow.min.js), not a stand-in - confirmed directly that its own UMD build
                // constructs, .start()s, and drives nodes/connections correctly against a plain
                // jsdom container with no special jsdom options (no pretendToBeVisual, no canvas
                // shim) needed. Safe to cache across tests unlike jquery's own require in
                // dom-env.js - this exports a plain class definition with no document/window
                // reference captured at require() time, only inside its own methods, called fresh
                // against whatever container a test passes to `new Drawflow(container)`. This lets
                // Test/js/campaign-flow-editor.test.js drive initCampaignFlowEditor() itself end to
                // end via window.ordoFlowTestHook.buildChain(), instead of only ever exercising the
                // pure helpers pulled out of it.
                return require('../../../view/adminhtml/web/lib/drawflow/drawflow.min.js');
            }
            if (dep === 'Magento_Ui/js/form/element/abstract') {
                // extend() returns the raw config object a module passes to Abstract.extend({...})
                // unchanged (no real knockout-observable prototype chain), but attaches a _super()
                // real enough for initObservable() to actually run: real uiClass's own _super()
                // (injected per-overridden-method by its ES5-style inheritance) returns the base
                // class instance, whose .observe(names) turns each named property into a plain
                // get/set-by-calling-with-or-without-an-argument function - exactly what
                // buildPreview()/onXChange() already read/write via this.discountStep()/
                // this.discountStep(value). Good enough for a test to call initObservable() for
                // real and then drive the resulting isBuyXGetY()/previewText() computeds by
                // writing through the very observables .observe() created, instead of only ever
                // calling buildPreview()/onXChange() directly via .call(fakeThis).
                return {
                    extend: function (config) {
                        config._super = config._super || function () {
                            var self = this;

                            return {
                                observe: function (names) {
                                    (names || []).forEach(function (name) {
                                        var value;

                                        self[name] = function (next) {
                                            if (arguments.length > 0) {
                                                value = next;
                                                return self;
                                            }

                                            return value;
                                        };
                                    });

                                    return self;
                                }
                            };
                        };

                        return config;
                    }
                };
            }
            if (dep === 'ko') {
                // computed(fn, context) returns fn re-bound to context, called fresh every time -
                // not memoized/reactive like the real thing, but since nothing here mutates an
                // observable out from under a test between reading computed() results, a plain
                // eagerly-recomputing bound function is behaviorally indistinguishable for testing
                // purposes.
                return {
                    computed: function (fn, context) {
                        return typeof fn === 'function' ? fn.bind(context) : fn;
                    }
                };
            }
            if (dep === 'mage/translate') {
                // Real usage only imports this for its side effect (registering $.mage.__() on
                // jQuery, same role Magento_Ui/js/modal/modal plays for $.fn.modal() above) - an
                // identity passthrough is indistinguishable from the real translator for a test
                // that never switches locale.
                global.$.mage = global.$.mage || {};
                global.$.mage.__ = global.$.mage.__ || function (text) {
                    return text;
                };
                return undefined;
            }
            throw new Error('Test/js/support/amd-shim: unsupported dependency "' + dep + '"');
        });

        exported = factory.apply(null, resolved);
    };
    global.define.amd = true;

    try {
        loadModule();
    } finally {
        delete global.define;
    }

    return exported;
}

module.exports = { loadAmdModule: loadAmdModule };
