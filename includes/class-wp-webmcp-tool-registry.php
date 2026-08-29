<?php
/**
 * Tool Registry — Manage WebMCP tools (declarative + imperative)
 *
 * @package WP_WebMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class WP_WebMCP_Tool_Registry
 *
 * Stores and manages WebMCP tool definitions. Tools are either:
 * - Declarative: auto-generated from detected forms
 * - Imperative: manually registered via admin UI or programmatically
 */
class WP_WebMCP_Tool_Registry {

    /**
     * Instance.
     *
     * @var WP_WebMCP_Tool_Registry|null
     */
    private static $instance = null;

    /**
     * Get singleton instance.
     *
     * @return WP_WebMCP_Tool_Registry
     */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     */
    private function __construct() {
        // Allow programmatic tool registration.
        add_action( 'wp_webmcp_register_tools', array( $this, 'register_default_tools' ) );

        // REST API for tool CRUD + public discovery + invocation.
        add_action( 'rest_api_init', array( $this, 'register_rest_endpoints' ) );

        // Fire the registration hook now so default tools actually get seeded.
        // register_tool() is idempotent (duplicate-name guard), so calling this
        // on every 'init' is safe — it only writes to the option on first run.
        do_action( 'wp_webmcp_register_tools' );
    }

    /**
     * Register default tools (called via action hook).
     */
    public function register_default_tools() {
        // Site search tool — useful for all sites.
        $this->register_tool(
            array(
                'name'        => 'search_site',
                'description' => 'Search the site for content matching a query.',
                'type'        => 'imperative',
                'schema'      => array(
                    'type'       => 'object',
                    'properties' => array(
                        'query' => array(
                            'type'        => 'string',
                            'description' => 'Search query string.',
                        ),
                    ),
                    'required'   => array( 'query' ),
                ),
                'callback'    => 'wp_webmcp_tool_search_site',
                'enabled'     => true,
            )
        );
    }

    /**
     * Register a tool.
     *
     * @param array $tool Tool definition.
     * @return bool|WP_Error
     */
    public function register_tool( $tool ) {
        $tools = wp_webmcp_get_tools();

        // Validate required fields.
        $required = array( 'name', 'description', 'type' );
        foreach ( $required as $field ) {
            if ( empty( $tool[ $field ] ) ) {
                return new WP_Error( 'missing_field', sprintf( 'Tool field "%s" is required.', $field ) );
            }
        }

        // Validate type.
        if ( ! in_array( $tool['type'], array( 'declarative', 'imperative' ), true ) ) {
            return new WP_Error( 'invalid_type', 'Tool type must be "declarative" or "imperative".' );
        }

        // Check for duplicate name.
        if ( isset( $tools[ $tool['name'] ] ) ) {
            return new WP_Error( 'duplicate_name', sprintf( 'A tool named "%s" already exists.', $tool['name'] ) );
        }

        $tools[ $tool['name'] ] = wp_parse_args(
            $tool,
            array(
                'enabled'  => true,
                'schema'   => array(),
                'callback' => '',
            )
        );

        return update_option( WP_WEBMCP_TOOLS_OPTION_KEY, $tools );
    }

    /**
     * Update a tool.
     *
     * @param string $name Tool name.
     * @param array  $data Updated data.
     * @return bool|WP_Error
     */
    public function update_tool( $name, $data ) {
        $tools = wp_webmcp_get_tools();

        if ( ! isset( $tools[ $name ] ) ) {
            return new WP_Error( 'not_found', sprintf( 'Tool "%s" not found.', $name ) );
        }

        $tools[ $name ] = wp_parse_args( $data, $tools[ $name ] );

        return update_option( WP_WEBMCP_TOOLS_OPTION_KEY, $tools );
    }

    /**
     * Delete a tool.
     *
     * @param string $name Tool name.
     * @return bool|WP_Error
     */
    public function delete_tool( $name ) {
        $tools = wp_webmcp_get_tools();

        if ( ! isset( $tools[ $name ] ) ) {
            return new WP_Error( 'not_found', sprintf( 'Tool "%s" not found.', $name ) );
        }

        unset( $tools[ $name ] );

        return update_option( WP_WEBMCP_TOOLS_OPTION_KEY, $tools );
    }

    /**
     * Get all enabled tools.
     *
     * @return array
     */
    public function get_enabled_tools() {
        $tools = wp_webmcp_get_tools();

        return array_filter(
            $tools,
            function ( $tool ) {
                return ! empty( $tool['enabled'] );
            }
        );
    }

    /**
     * Get tools for a specific page.
     *
     * @param int $post_id Post ID.
     * @return array
     */
    public function get_tools_for_page( $post_id ) {
        $tools = $this->get_enabled_tools();

        // Filter by page assignment if set.
        return array_filter(
            $tools,
            function ( $tool ) use ( $post_id ) {
                if ( empty( $tool['pages'] ) ) {
                    return true; // No page restriction = everywhere.
                }
                return in_array( $post_id, $tool['pages'], true );
            }
        );
    }

    /**
     * Register REST endpoints for tool management, discovery, and invocation.
     *
     * GET  /tools            — PUBLIC: list enabled tools for AI agent discovery.
     * POST /tools            — ADMIN:  create a new tool.
     * GET  /tools/all        — ADMIN:  list ALL tools (incl. disabled) for admin UI.
     * PUT  /tools/{name}     — ADMIN:  update a tool.
     * DEL  /tools/{name}     — ADMIN:  delete a tool.
     * POST /tools/{name}/invoke — PUBLIC (nonce-checked): execute a tool's callback.
     */
    public function register_rest_endpoints() {
        // GET /tools — public discovery (returns only enabled tools, no callbacks).
        register_rest_route(
            'wp-webmcp/v1',
            '/tools',
            array(
                array(
                    'methods'             => 'GET',
                    'callback'            => array( $this, 'rest_list_tools_public' ),
                    'permission_callback' => '__return_true',
                ),
                array(
                    'methods'             => 'POST',
                    'callback'            => array( $this, 'rest_create_tool' ),
                    'permission_callback' => function () {
                        return current_user_can( 'manage_options' );
                    },
                ),
            )
        );

        // GET /tools/all — admin-only: list ALL tools including disabled (for admin UI).
        register_rest_route(
            'wp-webmcp/v1',
            '/tools/all',
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'rest_list_tools' ),
                'permission_callback' => function () {
                    return current_user_can( 'manage_options' );
                },
            )
        );

        // POST /tools/{name}/invoke — PUBLIC endpoint (nonce-checked), this is what
        // the browser's WebMCP runtime calls when navigator.mcp actually invokes a
        // registered tool's handler. Must be registered BEFORE the /{name} route below
        // so the /invoke suffix doesn't get swallowed by the generic name pattern.
        register_rest_route(
            'wp-webmcp/v1',
            '/tools/(?P<name>[a-zA-Z0-9_-]+)/invoke',
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'rest_invoke_tool' ),
                'permission_callback' => array( $this, 'check_invoke_nonce' ),
            )
        );

        register_rest_route(
            'wp-webmcp/v1',
            '/tools/(?P<name>[a-zA-Z0-9_-]+)',
            array(
                array(
                    'methods'             => 'PUT',
                    'callback'            => array( $this, 'rest_update_tool' ),
                    'permission_callback' => function () {
                        return current_user_can( 'manage_options' );
                    },
                ),
                array(
                    'methods'             => 'DELETE',
                    'callback'            => array( $this, 'rest_delete_tool' ),
                    'permission_callback' => function () {
                        return current_user_can( 'manage_options' );
                    },
                ),
            )
        );
    }

    /**
     * Permission check for the public /invoke endpoint.
     *
     * Tools are meant to be callable by anonymous site visitors (via their
     * browser's WebMCP agent), so this isn't an auth check — it's a same-origin
     * CSRF guard via WP's standard REST nonce.
     *
     * @param WP_REST_Request $request Request.
     * @return bool|WP_Error
     */
    public function check_invoke_nonce( $request ) {
        $nonce = $request->get_header( 'X-WP-Nonce' );

        if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
            return new WP_Error( 'invalid_nonce', 'Invalid or missing nonce.', array( 'status' => 403 ) );
        }

        return true;
    }

    /**
     * REST: List enabled tools for public discovery.
     *
     * Returns only enabled tools and strips sensitive fields (callbacks)
     * so AI agents can discover what's available without exposing internals.
     *
     * @return WP_REST_Response
     */
    public function rest_list_tools_public() {
        $tools  = wp_webmcp_get_tools();
        $public = array();

        foreach ( $tools as $name => $tool ) {
            if ( empty( $tool['enabled'] ) ) {
                continue;
            }

            // Strip callback — agents invoke via REST, not direct PHP.
            $public[ $name ] = array(
                'name'        => $name,
                'description' => isset( $tool['description'] ) ? $tool['description'] : '',
                'type'        => isset( $tool['type'] ) ? $tool['type'] : 'imperative',
                'schema'      => isset( $tool['schema'] ) ? $tool['schema'] : array(),
            );
        }

        return rest_ensure_response( array_values( $public ) );
    }

    /**
     * REST: List ALL tools (admin only) — includes disabled tools and callbacks.
     *
     * @return WP_REST_Response
     */
    public function rest_list_tools() {
        return rest_ensure_response( wp_webmcp_get_tools() );
    }

    /**
     * REST: Create a tool.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public function rest_create_tool( $request ) {
        $result = $this->register_tool( $request->get_json_params() );

        if ( is_wp_error( $result ) ) {
            return rest_ensure_response( $result );
        }

        return rest_ensure_response( array( 'success' => true ) );
    }

    /**
     * REST: Update a tool.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public function rest_update_tool( $request ) {
        $name   = $request->get_param( 'name' );
        $result = $this->update_tool( $name, $request->get_json_params() );

        if ( is_wp_error( $result ) ) {
            return rest_ensure_response( $result );
        }

        return rest_ensure_response( array( 'success' => true ) );
    }

    /**
     * REST: Delete a tool.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public function rest_delete_tool( $request ) {
        $name   = $request->get_param( 'name' );
        $result = $this->delete_tool( $name );

        if ( is_wp_error( $result ) ) {
            return rest_ensure_response( $result );
        }

        return rest_ensure_response( array( 'success' => true ) );
    }

    /**
     * REST: Invoke a tool's callback. This is the endpoint the browser's
     * WebMCP runtime hits when navigator.mcp actually calls a registered tool.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public function rest_invoke_tool( $request ) {
        $name  = $request->get_param( 'name' );
        $tools = wp_webmcp_get_tools();

        if ( ! isset( $tools[ $name ] ) ) {
            return new WP_Error( 'not_found', sprintf( 'Tool "%s" not found.', $name ), array( 'status' => 404 ) );
        }

        $tool = $tools[ $name ];

        if ( empty( $tool['enabled'] ) ) {
            return new WP_Error( 'disabled', sprintf( 'Tool "%s" is disabled.', $name ), array( 'status' => 403 ) );
        }

        $callback = isset( $tool['callback'] ) ? $tool['callback'] : '';

        if ( empty( $callback ) || ! is_callable( $callback ) ) {
            return new WP_Error( 'no_callback', sprintf( 'Tool "%s" has no valid callback.', $name ), array( 'status' => 500 ) );
        }

        $params = $request->get_json_params();
        $input  = isset( $params['input'] ) ? $params['input'] : array();

        $result = call_user_func( $callback, $input );

        return rest_ensure_response( $result );
    }
}

/**
 * Default callback: site search.
 *
 * @param array $args Tool arguments.
 * @return array
 */
function wp_webmcp_tool_search_site( $args ) {
    $query = isset( $args['query'] ) ? sanitize_text_field( $args['query'] ) : '';

    if ( empty( $query ) ) {
        return array( 'error' => 'Query is required.' );
    }

    $results = new WP_Query(
        array(
            's'              => $query,
            'post_type'      => 'any',
            'post_status'    => 'publish',
            'posts_per_page' => 10,
        )
    );

    $matches = array();
    if ( $results->have_posts() ) {
        foreach ( $results->posts as $post ) {
            $matches[] = array(
                'title'   => $post->post_title,
                'url'     => get_permalink( $post->ID ),
                'excerpt' => wp_trim_words( $post->post_content, 30 ),
                'type'    => $post->post_type,
            );
        }
    }

    return array(
        'query'   => $query,
        'count'   => count( $matches ),
        'results' => $matches,
    );
}
