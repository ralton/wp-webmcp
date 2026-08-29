<?php
/**
 * HTTP Headers — Origin Isolation & Permissions Policy
 *
 * @package WP_WebMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class WP_WebMCP_Headers
 *
 * Handles HTTP header injection for WebMCP requirements:
 * - Origin isolation (required for WebMCP API access)
 * - Permissions Policy (tools directive)
 */
class WP_WebMCP_Headers {

    /**
     * Instance.
     *
     * @var WP_WebMCP_Headers|null
     */
    private static $instance = null;

    /**
     * Get singleton instance.
     *
     * @return WP_WebMCP_Headers
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
        add_filter( 'wp_headers', array( $this, 'inject_headers' ), 10, 1 );
        add_action( 'send_headers', array( $this, 'send_headers' ) );
    }

    /**
     * Inject headers into the wp_headers array.
     *
     * @param array $headers Existing headers.
     * @return array
     */
    public function inject_headers( $headers ) {
        $settings = wp_webmcp_get_settings();

        // Origin isolation — prevents document.domain relaxation.
        if ( $settings['origin_isolation'] ) {
            $headers['Origin-Agent-Cluster'] = '?1';
            // Explicitly disable document.domain.
            $headers['Cross-Origin-Resource-Policy'] = 'same-origin';
        }

        // Permissions Policy — enable 'tools' feature.
        if ( $settings['permissions_policy'] ) {
            $headers['Permissions-Policy'] = 'tools=(self)';
        }

        return $headers;
    }

    /**
     * Send headers via send_headers action.
     * Fallback for servers that don't use wp_headers filter.
     */
    public function send_headers() {
        $settings = wp_webmcp_get_settings();

        if ( $settings['origin_isolation'] ) {
            if ( ! headers_sent() ) {
                header( 'Origin-Agent-Cluster: ?1' );
            }
        }

        if ( $settings['permissions_policy'] ) {
            if ( ! headers_sent() ) {
                header( 'Permissions-Policy: tools=(self)' );
            }
        }
    }
}
