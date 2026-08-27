/**
 * drawflow.min.js is a UMD build (checks `typeof define === 'function' && define.amd` first) —
 * loading it via a plain injected <script> tag on an admin page registers it as an anonymous
 * AMD module instead of setting `window.Drawflow` (RequireJS's `define` is always present in
 * the admin), which silently breaks `new window.Drawflow(...)`. Mapping it to a real path here
 * lets it load the way it actually expects to.
 */
var config = {
    paths: {
        drawflow: 'Ordo_Automation/lib/drawflow/drawflow.min'
    }
};
