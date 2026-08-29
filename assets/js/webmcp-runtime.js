/**
 * WP-WebMCP Runtime
 * Core JavaScript for registering WebMCP tools with the browser.
 *
 * Uses the real Chrome WebMCP Imperative API: document.modelContext.
 * Reference: https://developer.chrome.com/docs/ai/webmcp/imperative-api
 *
 * @package WP_WebMCP
 */

(function () {
    'use strict';

    const config = window.WP_WebMCP_Config || {};
    const debug = config.debug || false;

    function log(...args) {
        if (debug) {
            console.log('[WP-WebMCP]', ...args);
        }
    }

    /**
     * Check if the WebMCP Imperative API is available in this browser.
     * The real API lives on document.modelContext (not navigator.mcp).
     */
    function isWebMCPAvailable() {
        return typeof document !== 'undefined' &&
               typeof document.modelContext !== 'undefined' &&
               typeof document.modelContext.registerTool === 'function';
    }

    /**
     * Register an imperative tool with the browser's WebMCP API.
     */
    async function registerImperativeTool(tool) {
        if (!isWebMCPAvailable()) {
            log('WebMCP API not available. Tool not registered:', tool.name);
            return false;
        }

        try {
            const toolDef = {
                name: tool.name,
                description: tool.description,
                inputSchema: tool.schema || { type: 'object', properties: {} },
                execute: async (input, options) => {
                    log('Tool called:', tool.name, input);

                    const response = await fetch(config.restUrl + '/tools/' + tool.name + '/invoke', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-WP-Nonce': config.nonce,
                        },
                        body: JSON.stringify({ input: input }),
                        signal: options && options.signal,
                    });

                    const result = await response.json();
                    log('Tool result:', tool.name, result);
                    return result;
                },
            };

            // Optional hints for the agent (safe defaults for read-only tools
            // like search — override per-tool via tool.annotations if set).
            if (tool.annotations) {
                toolDef.annotations = tool.annotations;
            }

            await document.modelContext.registerTool(toolDef);

            log('Tool registered:', tool.name);
            return true;
        } catch (err) {
            console.error('[WP-WebMCP] Failed to register tool:', tool.name, err);
            return false;
        }
    }

    /**
     * Initialize all registered imperative tools.
     */
    async function init() {
        if (!isWebMCPAvailable()) {
            log('WebMCP API not available in this browser. Enable chrome://flags/#enable-webmcp-testing');
            return;
        }

        log('Initializing WP-WebMCP runtime...');

        const tools = config.tools || [];
        let registered = 0;

        for (const tool of tools) {
            if (tool.type === 'imperative' && tool.enabled) {
                const success = await registerImperativeTool(tool);
                if (success) registered++;
            }
        }

        log(`Registered ${registered} of ${tools.length} tools.`);
    }

    // Wait for DOM ready.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // Expose for debugging.
    window.WP_WebMCP = {
        config: config,
        init: init,
        isAvailable: isWebMCPAvailable,
    };
})();
