/**
 * WP-WebMCP Debug
 * Console logging and inspection helpers for development.
 *
 * @package WP_WebMCP
 */

(function () {
    'use strict';

    console.log('%c[WP-WebMCP] Debug mode enabled', 'color: #004AAD; font-weight: bold;');

    const config = window.WP_WebMCP_Config || {};

    // Log current configuration.
    console.log('[WP-WebMCP] Config:', config);

    // Check browser support.
    const available = typeof navigator !== 'undefined' &&
                      typeof navigator.mcp !== 'undefined' &&
                      typeof navigator.mcp.registerTool === 'function';

    if (available) {
        console.log('%c[WP-WebMCP] ✓ WebMCP API available in this browser', 'color: green;');
    } else {
        console.log('%c[WP-WebMCP] ✗ WebMCP API NOT available. Enable: chrome://flags/#enable-webmcp-testing', 'color: red; font-weight: bold;');
    }

    // List registered tools.
    console.table(config.tools || []);

    // Expose debug helper.
    window.WP_WebMCP_Debug = {
        config: config,
        isAvailable: available,
        listTools: function () {
            console.table(config.tools || []);
        },
        checkHeaders: async function () {
            const response = await fetch(window.location.href, { method: 'HEAD' });
            const headers = {};
            response.headers.forEach((value, key) => { headers[key] = value; });
            console.log('[WP-WebMCP] Response headers:', headers);

            const checks = {
                'Origin-Agent-Cluster': headers['origin-agent-cluster'] || 'MISSING',
                'Permissions-Policy': headers['permissions-policy'] || 'MISSING',
            };
            console.table(checks);
        },
    };

    console.log('[WP-WebMCP] Debug helpers available: WP_WebMCP_Debug.listTools(), WP_WebMCP_Debug.checkHeaders()');
})();
