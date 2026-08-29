# WP-WebMCP Plugin Architecture

## Overview

WP-WebMCP is a WordPress plugin that implements the [WebMCP proposed web standard](https://developer.chrome.com/docs/ai/webmcp), allowing AI agents to discover and interact with WordPress sites through structured, callable tools.

## Folder Structure

```
wp-webmcp/
├── wp-webmcp.php                          # Main plugin file (entry point)
├── README.md                              # Project readme (GitHub-facing)
├── PLUGIN_ARCHITECTURE.md                 # This document
├── LICENSE                                # GPL-2.0
├── package.json                           # NPM metadata
├── uninstall.php                          # Cleanup on uninstall (TODO)
├── includes/
│   ├── class-wp-webmcp-headers.php         # HTTP headers (origin isolation, permissions policy)
│   ├── class-wp-webmcp-form-detector.php   # Auto-detect forms on pages
│   ├── class-wp-webmcp-tool-registry.php   # Tool CRUD + REST API
│   ├── class-wp-webmcp-script-loader.php   # Conditional JS/CSS enqueue
│   └── class-wp-webmcp-admin.php           # Settings page + tool management UI
├── assets/
│   ├── js/
│   │   ├── webmcp-runtime.js               # Core WebMCP tool registration
│   │   ├── webmcp-declarative.js           # Auto-annotate HTML forms
│   │   ├── webmcp-debug.js                 # Console logging + inspection
│   │   └── webmcp-admin.js                 # Admin UI interactions
│   └── css/
│       └── webmcp-admin.css                # Admin styles
├── admin/                                  # (reserved for v0.2 admin templates)
├── templates/                              # (reserved for v0.3 tool templates)
├── languages/                              # (reserved for i18n)
└── tests/                                  # (reserved for PHPUnit)
```

## Component Architecture

### 1. Main Plugin (`wp-webmcp.php`)
- Singleton pattern via `WP_WebMCP_Plugin`
- Handles activation/deactivation (sets default options)
- Loads all includes and registers hooks

### 2. Headers (`class-wp-webmcp-headers.php`)
- Injects `Origin-Agent-Cluster: ?1` header (required for WebMCP)
- Injects `Permissions-Policy: tools=(self)` header
- Works via both `wp_headers` filter and `send_headers` action (server compatibility)

### 3. Form Detector (`class-wp-webmcp-form-detector.php`)
- Scans post content for `<form>` elements via `the_content` filter
- Detects form plugin source (Contact Form 7, WPForms, Gravity Forms, Gutenberg, native)
- Outputs detected forms as inline JSON for the JS layer
- Provides REST endpoint for site-wide form scanning (admin utility)

### 4. Tool Registry (`class-wp-webmcp-tool-registry.php`)
- Stores tool definitions in `wp_options` (`wp_webmcp_tools`)
- CRUD operations via class methods
- REST API endpoints: `GET/POST /wp-json/wp-webmcp/v1/tools`, `PUT/DELETE /wp-json/wp-webmcp/v1/tools/{name}`
- Supports programmatic tool registration via `do_action('wp_webmcp_register_tools')`
- Ships with one default tool: `search_site`

### 5. Script Loader (`class-wp-webmcp-script-loader.php`)
- Enqueues JS only on singular pages with registered tools or detected forms
- Injects tool config via `wp_localize_script`
- Conditionally loads declarative annotation JS
- Conditionally loads debug JS

### 6. Admin UI (`class-wp-webmcp-admin.php`)
- Settings page under Settings → WebMCP
- Tabbed interface: Settings, Tools, Form Scanner
- Tool management: add, enable/disable, delete
- Form scanner: scan published pages for forms

### 7. JS Runtime (`assets/js/webmcp-runtime.js`)
- Checks for `navigator.mcp` API availability
- Registers imperative tools via `navigator.mcp.registerTool()`
- Routes tool invocations to WordPress REST API
- Debug logging via `WP_WebMCP_Config.debug`

### 8. JS Declarative (`assets/js/webmcp-declarative.js`)
- Reads detected forms from inline JSON
- Annotates form elements with `data-webmcp-*` attributes
- Auto-generates JSON schemas from input fields
- Resolves field labels from `<label>`, `aria-label`, or `placeholder`

## Data Flow

```
1. Page loads → PHP detects forms in content
2. Form data output as inline JSON
3. Script Loader enqueues JS based on tools + forms
4. webmcp-runtime.js → registers imperative tools with navigator.mcp
5. webmcp-declarative.js → annotates HTML forms with data attributes
6. AI agent visits page → discovers tools → calls them
7. Tool invocation → REST API → PHP callback → response
```

## WebMCP API Reference

- **Imperative API**: `navigator.mcp.registerTool({ name, description, inputSchema, handler })`
- **Declarative API**: HTML `data-webmcp-tool`, `data-webmcp-description`, `data-webmcp-schema` attributes
- **Discovery**: Agent visits page, reads registered tools, calls them
- **Security**: Origin isolation required, permissions policy gated, tools run visibly in browser

## Settings Schema

```json
{
    "enabled": true,
    "debug_mode": false,
    "auto_detect_forms": true,
    "origin_isolation": true,
    "permissions_policy": true,
    "excluded_post_types": []
}
```

## Tool Schema

```json
{
    "name": "string (snake_case, unique)",
    "description": "string",
    "type": "declarative | imperative",
    "schema": { /* JSON Schema object */ },
    "callback": "string (function name or URL)",
    "enabled": true,
    "pages": [ /* optional: array of post IDs where tool is available */ ]
}
```

## Versioning

- v0.1 — Current scaffold: headers, form detector, tool registry, admin UI, JS runtime
- v0.2 — Polish: uninstall.php, i18n, PHPUnit tests, WordPress.org readiness
- v0.3 — Imperative tool builder UI (visual schema editor)
- v0.4 — Form plugin integrations (CF7, WPForms, Gravity Forms native support)
- v0.5 — Analytics dashboard (track agent interactions)
- v1.0 — Stable release

## Developer Hooks

```php
// Register custom tools programmatically.
add_action('wp_webmcp_register_tools', function() {
    WP_WebMCP_Tool_Registry::instance()->register_tool([
        'name' => 'my_custom_tool',
        'description' => 'Does something cool.',
        'type' => 'imperative',
        'schema' => ['type' => 'object', 'properties' => [...]],
        'callback' => 'my_callback_function',
        'enabled' => true,
    ]);
});

// Filter detected forms before annotation.
add_filter('wp_webmcp_detected_forms', function($forms) {
    // Add, remove, or modify detected forms.
    return $forms;
});
```
