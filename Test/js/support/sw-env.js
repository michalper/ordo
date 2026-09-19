'use strict';

const path = require('path');

/**
 * A service worker has no window/document at all - just a global `self` carrying
 * addEventListener/registration/clients, nothing jsdom provides. This is a from-scratch stand-in,
 * not an extension of dom-env.js/amd-shim.js (those are AMD/jQuery-specific and don't apply here -
 * push-sw.js isn't a define()'d module, it's a plain script that registers its listeners as a
 * side effect of being loaded, same as it would in a real service worker context).
 *
 * @return {{handlers: Object<string, Function>, registration: Object, clients: Object}}
 */
function createServiceWorkerEnv() {
    const handlers = {};
    const registration = {
        showNotification: function () {
            return Promise.resolve();
        },
        pushManager: {
            subscribe: function () {
                return Promise.resolve({
                    endpoint: 'https://push.example.com/subscription/resubscribed',
                    getKey: function () {
                        return new Uint8Array([1, 2, 3]).buffer;
                    }
                });
            }
        }
    };
    const clients = {
        matchAll: function () {
            return Promise.resolve([]);
        }
        // openWindow is intentionally NOT provided by default - a test asserting the
        // "self.clients.openWindow doesn't exist" branch just doesn't add it, rather than this
        // env having to support turning it on/off.
    };

    global.self = {
        addEventListener: function (type, handler) {
            handlers[type] = handler;
        },
        registration: registration,
        clients: clients
    };

    return { handlers: handlers, registration: registration, clients: clients };
}

/**
 * Loads push-sw.js fresh against whatever service worker env is currently on `global.self` -
 * clearing require.cache first so its top-level self.addEventListener(...) calls actually re-run
 * against a new env each time, the same reasoning Test/js/support/load-module.js documents for
 * the AMD modules.
 *
 * @param {String} relativePathFromRepoRoot
 * @return {*} the module's own module.exports (see push-sw.js's own export guard)
 */
function loadServiceWorker(relativePathFromRepoRoot) {
    const absolutePath = path.join(__dirname, '..', '..', '..', relativePathFromRepoRoot);

    delete require.cache[require.resolve(absolutePath)];

    return require(absolutePath);
}

module.exports = { createServiceWorkerEnv: createServiceWorkerEnv, loadServiceWorker: loadServiceWorker };
