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

        // REST API for tool CRUD.
        add_action( 'rest_api_init', array( $this, 'register_rest_endpoints' ) );
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
                'enabled'     => false,
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
     * Register REST endpoints for tool management.
     */
    public function register_rest_endpoints() {
        register_rest_route(
            'wp-webmcp/v1',
            '/tools',
            array(
                array(
                    'methods'             => 'GET',
                    'callback'            => array( $this, 'rest_list_tools' ),
                    'permission_callback' => function () {
                        return current_user_can( 'manage_options' );
                    },
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
     * REST: List all tools.
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
        $name = $request->get_param( 'name' );
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
        $name = $request->get_param( 'name' );
        $result = $this->delete_tool( $name );

        if ( is_wp_error( $result ) ) {
            return rest_ensure_response( $result );
        }

        return rest_ensure_response( array( 'success' => true ) );
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
