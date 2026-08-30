<?php
/**
 * Script Loader — Enqueue WebMCP JS on pages with tools
 *
 * @package WP_WebMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class WP_WebMCP_Script_Loader
 *
 * Loads the WebMCP JavaScript runtime only on pages that have
 * registered tools or detected forms. Injects tool definitions
 * and form data as inline config.
 */
class WP_WebMCP_Script_Loader {

    /**
     * Instance.
     *
     * @var WP_WebMCP_Script_Loader|null
     */
    private static $instance = null;

    /**
     * Get singleton instance.
     *
     * @return WP_WebMCP_Script_Loader
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
        $settings = wp_webmcp_get_settings();

        if ( ! $settings['enabled'] ) {
            return;
        }

        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
    }

    /**
     * Check the raw post content for forms without relying on the
     * Form_Detector's runtime state (which populates during the_content,
     * AFTER wp_enqueue_scripts has already run — too late to gate on here).
     *
     * @param int $post_id Post ID.
     * @return bool
     */
    private function post_has_forms( $post_id ) {
        if ( ! $post_id ) {
            return false;
        }

        $post = get_post( $post_id );

        if ( ! $post ) {
            return false;
        }

        // Check raw content first — catches literal HTML forms and
        // Gutenberg form blocks that store <form> in post_content.
        if ( preg_match( '/<form[^>]*>/i', $post->post_content ) ) {
            return true;
        }

        // Render shortcodes and re-check — catches shortcode-based forms
        // like [medifit_contact_form], [contact-form-7], [gravityform],
        // [wpforms] etc. that only produce <form> tags at render time.
        $rendered = do_shortcode( $post->post_content );

        return (bool) preg_match( '/<form[^>]*>/i', $rendered );
    }

    /**
     * Enqueue scripts on frontend.
     */
    public function enqueue_scripts() {
        // Only load on singular pages (where forms live).
        if ( ! is_singular() ) {
            return;
        }

        $post_id  = get_queried_object_id();
        $registry = WP_WebMCP_Tool_Registry::instance();
        $tools    = $registry->get_tools_for_page( $post_id );

        // Check the raw post content directly — the_content filter (which the
        // Form_Detector uses) hasn't run yet at this point in the request.
        $has_forms = $this->post_has_forms( $post_id );

        if ( empty( $tools ) && ! $has_forms ) {
            return;
        }

        // Main WebMCP runtime.
        wp_enqueue_script(
            'wp-webmcp-runtime',
            WP_WEBMCP_PLUGIN_URL . 'assets/js/webmcp-runtime.js',
            array(),
            WP_WEBMCP_VERSION,
            true
        );

        // Inject tool definitions — strip internal 'callback' field, the
        // browser only needs name/description/schema/type to register with
        // navigator.mcp; the actual PHP function is never exposed client-side.
        $public_tools = array_map(
            function ( $tool ) {
                unset( $tool['callback'] );
                return $tool;
            },
            array_values( $tools )
        );

        $config = array(
            'debug'   => wp_webmcp_get_settings()['debug_mode'],
            'tools'   => $public_tools,
            'restUrl' => rest_url( 'wp-webmcp/v1' ),
        );

        // A REST nonce is CSRF protection, not authorization. Only provide it
        // to an authenticated visitor so WordPress cookie authentication can
        // identify that visitor for tools that explicitly require sign-in.
        if ( is_user_logged_in() ) {
            $config['nonce'] = wp_create_nonce( 'wp_rest' );
        }

        wp_localize_script( 'wp-webmcp-runtime', 'WP_WebMCP_Config', $config );

        // Declarative form annotation JS (if forms detected).
        if ( $has_forms && wp_webmcp_get_settings()['auto_detect_forms'] ) {
            wp_enqueue_script(
                'wp-webmcp-declarative',
                WP_WEBMCP_PLUGIN_URL . 'assets/js/webmcp-declarative.js',
                array( 'wp-webmcp-runtime' ),
                WP_WEBMCP_VERSION,
                true
            );
        }

        // Debug mode — inspector helper.
        if ( wp_webmcp_get_settings()['debug_mode'] ) {
            wp_enqueue_script(
                'wp-webmcp-debug',
                WP_WEBMCP_PLUGIN_URL . 'assets/js/webmcp-debug.js',
                array( 'wp-webmcp-runtime' ),
                WP_WEBMCP_VERSION,
                true
            );
        }
    }
}
