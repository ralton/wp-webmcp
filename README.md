# WP-WebMCP

**WebMCP for WordPress — expose your site as agent-ready tools for AI agents.**

WP-WebMCP is a WordPress plugin that implements the [WebMCP](https://developer.chrome.com/docs/ai/webmcp) proposed web standard, allowing AI agents to discover and interact with your website through structured, callable tools instead of DOM scraping.

## Features

- **Declarative form annotation** — automatically exposes WordPress forms (Gutenberg, Contact Form 7, WPForms, Gravity Forms) as WebMCP tools
- **Safe imperative tool registry** — register callbacks from trusted plugin or theme PHP code; database options cannot select executable callbacks
- **Origin isolation & permissions policy** — handles HTTP headers automatically
- **Form discovery** — scans your site and suggests forms to expose
- **Debug mode** — browser console logging for tool registration testing
- **Lightweight** — enqueues JS only on pages with registered tools

## Requirements

- WordPress 6.0+
- PHP 7.4+
- HTTPS enabled
- Chrome 149+ (for WebMCP origin trial / flag testing)

## Installation

1. Upload `wp-webmcp` to `/wp-content/plugins/`
2. Activate the plugin through the WordPress Plugins menu
3. Navigate to **Settings → WebMCP** to configure tools
4. Enable Chrome flag: `chrome://flags/#enable-webmcp-testing`
5. Test with the [Model Context Tool Inspector Extension](https://developer.chrome.com/docs/ai/webmcp)

## How It Works

WebMCP lets your website declare structured tools that AI agents can call directly. Instead of an agent guessing what a button does, you explicitly define:

- **Tool name** — e.g., `book_consultation`, `subscribe_newsletter`
- **JSON Schema** — expected inputs and outputs
- **Callback** — what happens when the tool is invoked

WP-WebMCP handles declarative annotation automatically. Imperative tools are a developer-only capability: callbacks are registered in trusted PHP code for the current request and are never read from database options.

```php
add_action( 'wp_webmcp_register_tools', function() {
    WP_WebMCP_Tool_Registry::instance()->register_runtime_tool(
        array(
            'name'        => 'example_lookup',
            'description' => 'Look up public example data.',
            'schema'      => array(
                'type'       => 'object',
                'properties' => array(
                    'query' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 200 ),
                ),
                'required'   => array( 'query' ),
            ),
            'access'      => 'public', // Or 'authenticated'.
            'annotations' => array( 'readOnlyHint' => true ),
        ),
        function( $input ) {
            return array( 'query' => $input['query'] );
        }
    );
} );
```

Public runtime invocations require a same-origin browser request and are rate-limited. Tools should default to read-only; any write-capable tool needs its own authorization and confirmation design before it is exposed.

## Roadmap

- [x] Plugin scaffold and architecture
- [ ] v0.1 — Declarative form annotations (auto-detect + tag forms)
- [ ] v0.2 — Admin settings UI + tool registry
- [ ] v0.3 — Imperative tool API (custom JS tools)
- [ ] v0.4 — Origin isolation + permissions policy headers
- [ ] v0.5 — Debug mode + inspector integration
- [ ] v1.0 — Form scanning, multi-form-plugin support, documentation
- [ ] v1.1 — Analytics dashboard (agent interactions)
- [ ] v1.2 — Multi-site support
- [ ] v2.0 — Pro tier (custom tool builder, advanced schemas, webhooks)

## License

GPL-2.0-or-later

## Links

- [WebMCP Chrome docs](https://developer.chrome.com/docs/ai/webmcp)
- [WebMCP explainer (GitHub)](https://github.com/nicolo-ribaudo/webmcp-explainer)
- [OpenAI WebMCP Challenge](https://openai.com/webmcp-challenge/)
