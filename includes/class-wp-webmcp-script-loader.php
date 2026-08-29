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

        // Check for detected forms.
        $has_forms = ! empty( WP_WebMCP_Form_Detector::instance()->detected_forms );

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

        // Inject tool definitions.
        $config = array(
            'debug'   => wp_webmcp_get_settings()['debug_mode'],
            'tools'   => array_values( $tools ),
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'restUrl' => rest_url( 'wp-webmcp/v1' ),
            'nonce'   => wp_create_nonce( 'wp-webmcp' ),
        );

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
