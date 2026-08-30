const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const root = path.resolve(__dirname, '..');
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');

test('admin UI does not offer database-backed imperative tools', () => {
    const adminPhp = read('includes/class-wp-webmcp-admin.php');
    const adminJs = read('assets/js/webmcp-admin.js');

    assert.doesNotMatch(adminPhp, /value="imperative"/);
    assert.doesNotMatch(adminJs, /type:\s*type/);
    assert.match(adminPhp, /Developer-registered tools/i);
    assert.match(adminPhp, /Managed in PHP/);
});

test('a REST nonce is emitted and sent only for authenticated cookie sessions', () => {
    const loader = read('includes/class-wp-webmcp-script-loader.php');
    const runtime = read('assets/js/webmcp-runtime.js');

    assert.match(loader, /if \( is_user_logged_in\(\) \) \{\s*\$config\['nonce'\] = wp_create_nonce\( 'wp_rest' \)/);
    assert.match(runtime, /config\.nonce \? \{ 'X-WP-Nonce': config\.nonce \} : \{\}/);
    assert.match(runtime, /credentials:\s*'same-origin'/);
});

test('tool callbacks are registered in code rather than persisted and invoked dynamically', () => {
    const registry = read('includes/class-wp-webmcp-tool-registry.php');

    assert.match(registry, /private \$callbacks\s*=\s*array\(\)/);
    assert.match(registry, /register_runtime_tool/);
    assert.doesNotMatch(registry, /call_user_func\(\s*\$callback/);
    assert.doesNotMatch(registry, /'callback'\s*=>\s*'wp_webmcp_tool_search_site'/);
});

test('the invocation boundary validates origin, rate, tool policy, and input schema', () => {
    const registry = read('includes/class-wp-webmcp-tool-registry.php');

    assert.match(registry, /check_invoke_permission/);
    assert.match(registry, /validate_same_origin/);
    assert.match(registry, /check_rate_limit/);
    assert.match(registry, /validate_input/);
    assert.match(registry, /validate_schema/);
    assert.match(registry, /'access'\s*=>\s*'public'/);
    assert.match(registry, /catch \( Throwable \$throwable \)/);
    assert.match(registry, /if \( \$valid\['name'\] !== \$name && isset\( \$tools\[ \$valid\['name'\] \] \) \)/);
    assert.match(registry, /! is_int\( \$value \) && ! is_float\( \$value \)/);
    assert.match(registry, /'integer' === \$property\['type'\] && ! is_int\( \$value \)/);
    assert.match(registry, /v0\.1 persisted an empty callback key for declarative tools/);
    assert.match(registry, /extract_object_input/);
    assert.match(registry, /! is_object\( \$payload \) \|\| ! isset\( \$payload->input \) \|\| ! is_object\( \$payload->input \)/);
});

test('page assignments are rejected until invocation has verifiable page context', () => {
    const registry = read('includes/class-wp-webmcp-tool-registry.php');

    assert.match(registry, /Page-scoped tools are not supported/);
});

test('form scanner builds result rows without interpolating API strings into HTML', () => {
    const adminJs = read('assets/js/webmcp-admin.js');

    assert.doesNotMatch(adminJs, /html \+= .*page\.title/);
    assert.doesNotMatch(adminJs, /resultsDiv\.html\(html\)/);
    assert.match(adminJs, /\.text\(page\.title\)/);
    assert.match(adminJs, /isSafeHttpUrl/);
});
