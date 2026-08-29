<?php
/**
 * Plugin Name:       WP-WebMCP
 * Plugin URI:        https://github.com/ralton/wp-webmcp
 * Description:       Expose your WordPress site as agent-ready tools for AI agents using the WebMCP proposed web standard.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Russ Alton
 * Author URI:        https://github.com/ralton
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-webmcp
 * Domain Path:       /languages
 *
 * @package WP_WebMCP
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Plugin constants.
define( 'WP_WEBMCP_VERSION', '0.1.0' );
define( 'WP_WEBMCP_PLUGIN_FILE', __FILE__ );
define( 'WP_WEBMCP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WP_WEBMCP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WP_WEBMCP_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'WP_WEBMCP_OPTION_KEY', 'wp_webmcp_settings' );
define( 'WP_WEBMCP_TOOLS_OPTION_KEY', 'wp_webmcp_tools' );

/**
 * Get plugin settings with defaults.
 *
 * @return array
 */
function wp_webmcp_get_settings() {
    $defaults = array(
        'enabled'              => true,
        'debug_mode'           => false,
        'auto_detect_forms'    => true,
        'origin_isolation'     => true,
        'permissions_policy'   => true,
        'excluded_post_types'  => array(),
    );

    $settings = get_option( WP_WEBMCP_OPTION_KEY, array() );
    return wp_parse_args( $settings, $defaults );
}

/**
 * Get registered tools.
 *
 * @return array
 */
function wp_webmcp_get_tools() {
    $tools = get_option( WP_WEBMCP_TOOLS_OPTION_KEY, array() );
    return is_array( $tools ) ? $tools : array();
}

/**
 * Main plugin class.
 */
class WP_WebMCP_Plugin {

    /**
     * Instance.
     *
     * @var WP_WebMCP_Plugin|null
     */
    private static $instance = null;

    /**
     * Get singleton instance.
     *
     * @return WP_WebMCP_Plugin
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
        $this->includes();
        $this->hooks();
    }

    /**
     * Include required files.
     */
    private function includes() {
        require_once WP_WEBMCP_PLUGIN_DIR . 'includes/class-wp-webmcp-headers.php';
        require_once WP_WEBMCP_PLUGIN_DIR . 'includes/class-wp-webmcp-form-detector.php';
        require_once WP_WEBMCP_PLUGIN_DIR . 'includes/class-wp-webmcp-tool-registry.php';
        require_once WP_WEBMCP_PLUGIN_DIR . 'includes/class-wp-webmcp-script-loader.php';
        require_once WP_WEBMCP_PLUGIN_DIR . 'includes/class-wp-webmcp-admin.php';
    }

    /**
     * Register hooks.
     */
    private function hooks() {
        register_activation_hook( WP_WEBMCP_PLUGIN_FILE, array( $this, 'activate' ) );
        register_deactivation_hook( WP_WEBMCP_PLUGIN_FILE, array( $this, 'deactivate' ) );

        add_action( 'init', array( $this, 'init' ) );
    }

    /**
     * Activation.
     */
    public function activate() {
        // Set default options.
        if ( ! get_option( WP_WEBMCP_OPTION_KEY ) ) {
            add_option( WP_WEBMCP_OPTION_KEY, wp_webmcp_get_settings() );
        }
        if ( ! get_option( WP_WEBMCP_TOOLS_OPTION_KEY ) ) {
            add_option( WP_WEBMCP_TOOLS_OPTION_KEY, array() );
        }

        // Flush rewrite rules.
        flush_rewrite_rules();
    }

    /**
     * Deactivation.
     */
    public function deactivate() {
        flush_rewrite_rules();
    }

    /**
     * Init.
     */
    public function init() {
        // Load components.
        WP_WebMCP_Headers::instance();
        WP_WebMCP_Form_Detector::instance();
        WP_WebMCP_Tool_Registry::instance();
        WP_WebMCP_Script_Loader::instance();

        if ( is_admin() ) {
            WP_WebMCP_Admin::instance();
        }
    }
}

// Boot the plugin.
WP_WebMCP_Plugin::instance();
