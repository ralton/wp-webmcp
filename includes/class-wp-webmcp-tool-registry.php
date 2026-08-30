<?php
/**
 * Safe WebMCP tool registry.
 *
 * Persisted definitions describe declarative tools only. Imperative callbacks
 * exist only in PHP code for the current request; an option can never choose a
 * callable function.
 *
 * @package WP_WebMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_WebMCP_Tool_Registry {
    private static $instance = null;
    private $callbacks = array();
    private $runtime_tools = array();

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'rest_api_init', array( $this, 'register_rest_endpoints' ) );
        add_action( 'wp_webmcp_register_tools', array( $this, 'register_default_tools' ) );
        do_action( 'wp_webmcp_register_tools' );
    }

    /**
     * Register an imperative tool from trusted plugin/theme PHP only.
     *
     * @param array    $tool     Public definition.
     * @param callable $callback Server-side implementation.
     * @return bool|WP_Error
     */
    public function register_runtime_tool( $tool, $callback ) {
        if ( ! is_callable( $callback ) ) {
            return new WP_Error( 'invalid_callback', 'Runtime tools require a callable registered in PHP code.' );
        }

        $tool['type']   = 'imperative';
        $tool['access'] = isset( $tool['access'] ) ? $tool['access'] : 'public';
        $valid          = $this->validate_tool_definition( $tool, true );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        if ( isset( $this->runtime_tools[ $valid['name'] ] ) || isset( wp_webmcp_get_tools()[ $valid['name'] ] ) ) {
            return new WP_Error( 'duplicate_name', sprintf( 'A tool named "%s" already exists.', $valid['name'] ) );
        }

        $this->runtime_tools[ $valid['name'] ] = $valid;
        $this->callbacks[ $valid['name'] ]     = $callback;
        return true;
    }

    public function register_default_tools() {
        $this->register_runtime_tool(
            array(
                'name'        => 'search_site',
                'description' => 'Search the site for published content matching a query.',
                'schema'      => array(
                    'type'       => 'object',
                    'properties' => array(
                        'query' => array(
                            'type'        => 'string',
                            'description' => 'Search query string.',
                            'minLength'   => 1,
                            'maxLength'   => 200,
                        ),
                    ),
                    'required'   => array( 'query' ),
                    'additionalProperties' => false,
                ),
                'enabled'     => true,
                'access'      => 'public',
                'annotations' => array( 'readOnlyHint' => true ),
            ),
            'wp_webmcp_tool_search_site'
        );
    }

    /**
     * Persist a declarative definition. Imperative tools cannot be configured
     * in the database because their callback must be trusted PHP code.
     */
    public function register_tool( $tool ) {
        if ( isset( $tool['callback'] ) ) {
            return new WP_Error( 'callback_not_allowed', 'Callbacks may only be registered through register_runtime_tool().' );
        }
        if ( isset( $tool['type'] ) && 'imperative' === $tool['type'] ) {
            return new WP_Error( 'imperative_not_allowed', 'Imperative tools must be registered in PHP code.' );
        }

        $valid = $this->validate_tool_definition( $tool, false );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        $tools = $this->get_persisted_tools();
        if ( isset( $tools[ $valid['name'] ] ) || isset( $this->runtime_tools[ $valid['name'] ] ) ) {
            return new WP_Error( 'duplicate_name', sprintf( 'A tool named "%s" already exists.', $valid['name'] ) );
        }
        $tools[ $valid['name'] ] = $valid;
        return update_option( WP_WEBMCP_TOOLS_OPTION_KEY, $tools );
    }

    public function update_tool( $name, $data ) {
        $tools = $this->get_persisted_tools();
        if ( ! isset( $tools[ $name ] ) ) {
            return new WP_Error( 'not_found', sprintf( 'Tool "%s" not found.', $name ) );
        }
        if ( isset( $data['callback'] ) || ( isset( $data['type'] ) && 'imperative' === $data['type'] ) ) {
            return new WP_Error( 'imperative_not_allowed', 'Imperative tools must be registered in PHP code.' );
        }

        $valid = $this->validate_tool_definition( wp_parse_args( $data, $tools[ $name ] ), false );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }
        if ( $valid['name'] !== $name && isset( $tools[ $valid['name'] ] ) ) {
            return new WP_Error( 'duplicate_name', sprintf( 'A tool named "%s" already exists.', $valid['name'] ) );
        }
        unset( $tools[ $name ] );
        $tools[ $valid['name'] ] = $valid;
        return update_option( WP_WEBMCP_TOOLS_OPTION_KEY, $tools );
    }

    public function delete_tool( $name ) {
        $tools = $this->get_persisted_tools();
        if ( ! isset( $tools[ $name ] ) ) {
            return new WP_Error( 'not_found', sprintf( 'Tool "%s" not found.', $name ) );
        }
        unset( $tools[ $name ] );
        return update_option( WP_WEBMCP_TOOLS_OPTION_KEY, $tools );
    }

    public function get_enabled_tools() {
        return array_filter(
            array_merge( $this->get_persisted_tools(), $this->runtime_tools ),
            function ( $tool ) { return ! empty( $tool['enabled'] ); }
        );
    }

    public function get_tools_for_page( $post_id ) {
        // Page-scoped tools are deliberately unsupported until invocation has a
        // verifiable page-context protocol. validate_tool_definition rejects them.
        return $this->get_enabled_tools();
    }

    private function get_persisted_tools() {
        $raw   = wp_webmcp_get_tools();
        $clean = array();
        foreach ( $raw as $tool ) {
            if ( ! is_array( $tool ) || ( isset( $tool['type'] ) && 'imperative' === $tool['type'] ) ) {
                continue;
            }
            // v0.1 persisted an empty callback key for declarative tools. It
            // never executed those callbacks, so remove that legacy metadata
            // while refusing any non-empty executable callback value.
            if ( isset( $tool['callback'] ) ) {
                if ( ! empty( $tool['callback'] ) ) {
                    continue;
                }
                unset( $tool['callback'] );
            }
            $valid = $this->validate_tool_definition( $tool, false );
            if ( ! is_wp_error( $valid ) ) {
                $clean[ $valid['name'] ] = $valid;
            }
        }
        return $clean;
    }

    private function validate_tool_definition( $tool, $runtime ) {
        if ( ! is_array( $tool ) ) {
            return new WP_Error( 'invalid_tool', 'Tool definition must be an object.' );
        }
        $name = isset( $tool['name'] ) ? sanitize_key( $tool['name'] ) : '';
        if ( empty( $name ) || ! preg_match( '/^[a-z][a-z0-9_]{0,63}$/', $name ) ) {
            return new WP_Error( 'invalid_name', 'Tool name must be lowercase snake_case and begin with a letter.' );
        }
        $description = isset( $tool['description'] ) ? sanitize_text_field( $tool['description'] ) : '';
        if ( empty( $description ) || strlen( $description ) > 500 ) {
            return new WP_Error( 'invalid_description', 'Tool description is required and must be 500 characters or fewer.' );
        }
        if ( ! empty( $tool['pages'] ) ) {
            return new WP_Error( 'page_scope_not_supported', 'Page-scoped tools are not supported until invocation has verifiable page context.' );
        }
        $schema = isset( $tool['schema'] ) ? $tool['schema'] : array( 'type' => 'object', 'properties' => array() );
        $schema = $this->validate_schema( $schema );
        if ( is_wp_error( $schema ) ) {
            return $schema;
        }
        $access = isset( $tool['access'] ) ? $tool['access'] : 'public';
        if ( ! in_array( $access, array( 'public', 'authenticated' ), true ) ) {
            return new WP_Error( 'invalid_access', 'Tool access must be public or authenticated.' );
        }
        if ( ! $runtime && 'imperative' === ( isset( $tool['type'] ) ? $tool['type'] : 'declarative' ) ) {
            return new WP_Error( 'imperative_not_allowed', 'Imperative tools must be registered in PHP code.' );
        }
        return array(
            'name'        => $name,
            'description' => $description,
            'type'        => $runtime ? 'imperative' : 'declarative',
            'schema'      => $schema,
            'enabled'     => ! isset( $tool['enabled'] ) || (bool) $tool['enabled'],
            'access'      => $access,
            'annotations' => isset( $tool['annotations'] ) && is_array( $tool['annotations'] ) ? $tool['annotations'] : array(),
        );
    }

    private function validate_schema( $schema ) {
        if ( ! is_array( $schema ) || ! isset( $schema['type'] ) || 'object' !== $schema['type'] ) {
            return new WP_Error( 'invalid_schema', 'Input schema must be a JSON Schema object.' );
        }
        $properties = isset( $schema['properties'] ) ? $schema['properties'] : array();
        if ( ! is_array( $properties ) ) {
            return new WP_Error( 'invalid_schema', 'Schema properties must be an object.' );
        }
        foreach ( $properties as $key => $property ) {
            if ( ! preg_match( '/^[a-zA-Z][a-zA-Z0-9_]*$/', $key ) || ! is_array( $property ) || empty( $property['type'] ) || ! in_array( $property['type'], array( 'string', 'number', 'integer', 'boolean' ), true ) ) {
                return new WP_Error( 'invalid_schema', 'Schema properties must have safe names and scalar types.' );
            }
        }
        $required = isset( $schema['required'] ) ? $schema['required'] : array();
        if ( ! is_array( $required ) || array_diff( $required, array_keys( $properties ) ) ) {
            return new WP_Error( 'invalid_schema', 'Schema required fields must be declared properties.' );
        }
        return array( 'type' => 'object', 'properties' => $properties, 'required' => array_values( $required ), 'additionalProperties' => ! isset( $schema['additionalProperties'] ) || (bool) $schema['additionalProperties'] );
    }

    public function register_rest_endpoints() {
        register_rest_route( 'wp-webmcp/v1', '/tools', array(
            array( 'methods' => 'GET', 'callback' => array( $this, 'rest_list_tools_public' ), 'permission_callback' => '__return_true' ),
            array( 'methods' => 'POST', 'callback' => array( $this, 'rest_create_tool' ), 'permission_callback' => array( $this, 'can_manage_tools' ) ),
        ) );
        register_rest_route( 'wp-webmcp/v1', '/tools/all', array( 'methods' => 'GET', 'callback' => array( $this, 'rest_list_tools' ), 'permission_callback' => array( $this, 'can_manage_tools' ) ) );
        register_rest_route( 'wp-webmcp/v1', '/tools/(?P<name>[a-zA-Z0-9_-]+)/invoke', array( 'methods' => 'POST', 'callback' => array( $this, 'rest_invoke_tool' ), 'permission_callback' => array( $this, 'check_invoke_permission' ) ) );
        register_rest_route( 'wp-webmcp/v1', '/tools/(?P<name>[a-zA-Z0-9_-]+)', array(
            array( 'methods' => 'PUT', 'callback' => array( $this, 'rest_update_tool' ), 'permission_callback' => array( $this, 'can_manage_tools' ) ),
            array( 'methods' => 'DELETE', 'callback' => array( $this, 'rest_delete_tool' ), 'permission_callback' => array( $this, 'can_manage_tools' ) ),
        ) );
    }

    public function can_manage_tools() { return current_user_can( 'manage_options' ); }

    public function check_invoke_permission( $request ) {
        $name = sanitize_key( $request->get_param( 'name' ) );
        if ( ! isset( $this->runtime_tools[ $name ] ) || empty( $this->runtime_tools[ $name ]['enabled'] ) ) {
            return new WP_Error( 'not_found', 'Tool not found.', array( 'status' => 404 ) );
        }
        if ( ! $this->validate_same_origin( $request ) ) {
            return new WP_Error( 'invalid_origin', 'Tool invocation must come from this site.', array( 'status' => 403 ) );
        }
        if ( 'authenticated' === $this->runtime_tools[ $name ]['access'] && ! is_user_logged_in() ) {
            return new WP_Error( 'authentication_required', 'This tool requires an authenticated user.', array( 'status' => 401 ) );
        }
        if ( ! $this->check_rate_limit( $name ) ) {
            return new WP_Error( 'rate_limited', 'Too many tool invocations. Please try again shortly.', array( 'status' => 429 ) );
        }
        return true;
    }

    private function validate_same_origin( $request ) {
        $origin = wp_parse_url( $request->get_header( 'Origin' ) );
        $site   = wp_parse_url( home_url() );
        if ( empty( $origin['scheme'] ) || empty( $origin['host'] ) || empty( $site['scheme'] ) || empty( $site['host'] ) ) {
            return false;
        }
        $origin_port = isset( $origin['port'] ) ? (int) $origin['port'] : ( 'https' === $origin['scheme'] ? 443 : 80 );
        $site_port   = isset( $site['port'] ) ? (int) $site['port'] : ( 'https' === $site['scheme'] ? 443 : 80 );
        return strtolower( $origin['scheme'] ) === strtolower( $site['scheme'] )
            && strtolower( $origin['host'] ) === strtolower( $site['host'] )
            && $origin_port === $site_port;
    }

    private function check_rate_limit( $name ) {
        $ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
        $key = 'wp_webmcp_rate_' . md5( $name . '|' . $ip );
        $hits = (int) get_transient( $key );
        if ( $hits >= 30 ) { return false; }
        set_transient( $key, $hits + 1, MINUTE_IN_SECONDS );
        return true;
    }

    public function rest_list_tools_public() {
        $public = array();
        foreach ( $this->get_enabled_tools() as $tool ) {
            $public[] = array( 'name' => $tool['name'], 'description' => $tool['description'], 'type' => $tool['type'], 'schema' => $tool['schema'], 'annotations' => $tool['annotations'], 'access' => $tool['access'] );
        }
        return rest_ensure_response( $public );
    }
    public function rest_list_tools() { return rest_ensure_response( $this->get_enabled_tools() ); }
    public function rest_create_tool( $request ) { $result = $this->register_tool( $request->get_json_params() ); return is_wp_error( $result ) ? $result : rest_ensure_response( array( 'success' => true ) ); }
    public function rest_update_tool( $request ) { $result = $this->update_tool( sanitize_key( $request->get_param( 'name' ) ), $request->get_json_params() ); return is_wp_error( $result ) ? $result : rest_ensure_response( array( 'success' => true ) ); }
    public function rest_delete_tool( $request ) { $result = $this->delete_tool( sanitize_key( $request->get_param( 'name' ) ) ); return is_wp_error( $result ) ? $result : rest_ensure_response( array( 'success' => true ) ); }

    public function rest_invoke_tool( $request ) {
        $name  = sanitize_key( $request->get_param( 'name' ) );
        $input = $this->extract_object_input( $request );
        if ( is_wp_error( $input ) ) { return $input; }
        $input = $this->validate_input( $input, $this->runtime_tools[ $name ]['schema'] );
        if ( is_wp_error( $input ) ) { return $input; }
        try {
            $callback = $this->callbacks[ $name ];
            return rest_ensure_response( $callback( $input ) );
        } catch ( Throwable $throwable ) {
            return new WP_Error( 'tool_execution_failed', 'The tool could not complete.', array( 'status' => 500 ) );
        }
    }

    /**
     * Decode the request body as objects so JSON arrays cannot masquerade as
     * PHP arrays accepted by the schema validation layer.
     */
    private function extract_object_input( $request ) {
        $payload = json_decode( $request->get_body() );
        if ( JSON_ERROR_NONE !== json_last_error() || ! is_object( $payload ) || ! isset( $payload->input ) || ! is_object( $payload->input ) ) {
            return new WP_Error( 'invalid_input', 'Tool input must be a JSON object.', array( 'status' => 400 ) );
        }
        return get_object_vars( $payload->input );
    }

    private function validate_input( $input, $schema ) {
        if ( ! is_array( $input ) ) { return new WP_Error( 'invalid_input', 'Tool input must be an object.', array( 'status' => 400 ) ); }
        $properties = $schema['properties'];
        if ( empty( $schema['additionalProperties'] ) && array_diff( array_keys( $input ), array_keys( $properties ) ) ) { return new WP_Error( 'invalid_input', 'Tool input contains unsupported fields.', array( 'status' => 400 ) ); }
        foreach ( $schema['required'] as $key ) { if ( ! array_key_exists( $key, $input ) || '' === $input[ $key ] ) return new WP_Error( 'invalid_input', sprintf( 'Tool input field "%s" is required.', $key ), array( 'status' => 400 ) ); }
        foreach ( $input as $key => $value ) {
            if ( ! isset( $properties[ $key ] ) ) continue;
            $property = $properties[ $key ];
            if ( 'string' === $property['type'] && ! is_string( $value ) ) return new WP_Error( 'invalid_input', sprintf( 'Tool input field "%s" must be a string.', $key ), array( 'status' => 400 ) );
            if ( 'number' === $property['type'] && ! is_int( $value ) && ! is_float( $value ) ) return new WP_Error( 'invalid_input', sprintf( 'Tool input field "%s" must be a JSON number.', $key ), array( 'status' => 400 ) );
            if ( 'integer' === $property['type'] && ! is_int( $value ) ) return new WP_Error( 'invalid_input', sprintf( 'Tool input field "%s" must be a JSON integer.', $key ), array( 'status' => 400 ) );
            if ( 'boolean' === $property['type'] && ! is_bool( $value ) ) return new WP_Error( 'invalid_input', sprintf( 'Tool input field "%s" must be a boolean.', $key ), array( 'status' => 400 ) );
            if ( 'string' === $property['type'] && isset( $property['minLength'] ) && strlen( $value ) < $property['minLength'] ) return new WP_Error( 'invalid_input', sprintf( 'Tool input field "%s" is too short.', $key ), array( 'status' => 400 ) );
            if ( 'string' === $property['type'] && isset( $property['maxLength'] ) && strlen( $value ) > $property['maxLength'] ) return new WP_Error( 'invalid_input', sprintf( 'Tool input field "%s" is too long.', $key ), array( 'status' => 400 ) );
        }
        return $input;
    }
}

function wp_webmcp_tool_search_site( $args ) {
    $query = sanitize_text_field( $args['query'] );
    $results = new WP_Query( array( 's' => $query, 'post_type' => 'any', 'post_status' => 'publish', 'posts_per_page' => 10 ) );
    $matches = array();
    foreach ( $results->posts as $post ) { $matches[] = array( 'title' => get_the_title( $post ), 'url' => get_permalink( $post->ID ), 'excerpt' => wp_trim_words( $post->post_content, 30 ), 'type' => $post->post_type ); }
    return array( 'query' => $query, 'count' => count( $matches ), 'results' => $matches );
}
