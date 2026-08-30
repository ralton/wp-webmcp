<?php
/**
 * Admin UI — Settings page and tool management
 *
 * @package WP_WebMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class WP_WebMCP_Admin
 *
 * Provides the WordPress admin interface for configuring WebMCP settings,
 * managing tools, and scanning for forms.
 */
class WP_WebMCP_Admin {

    /**
     * Instance.
     *
     * @var WP_WebMCP_Admin|null
     */
    private static $instance = null;

    /**
     * Get singleton instance.
     *
     * @return WP_WebMCP_Admin
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
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
    }

    /**
     * Add admin menu.
     */
    public function add_admin_menu() {
        add_options_page(
            __( 'WebMCP Settings', 'wp-webmcp' ),
            __( 'WebMCP', 'wp-webmcp' ),
            'manage_options',
            'wp-webmcp',
            array( $this, 'render_settings_page' )
        );
    }

    /**
     * Register settings.
     */
    public function register_settings() {
        register_setting( 'wp_webmcp_settings_group', WP_WEBMCP_OPTION_KEY, array(
            $this, 'sanitize_settings',
        ) );

        // Settings section: General.
        add_settings_section(
            'wp_webmcp_general',
            __( 'General Settings', 'wp-webmcp' ),
            array( $this, 'render_general_section' ),
            'wp-webmcp'
        );

        add_settings_field( 'enabled', __( 'Enable WebMCP', 'wp-webmcp' ),
            array( $this, 'render_checkbox_field' ),
            'wp-webmcp', 'wp_webmcp_general',
            array( 'key' => 'enabled', 'label' => 'Expose WebMCP tools to AI agents' )
        );

        add_settings_field( 'debug_mode', __( 'Debug Mode', 'wp-webmcp' ),
            array( $this, 'render_checkbox_field' ),
            'wp-webmcp', 'wp_webmcp_general',
            array( 'key' => 'debug_mode', 'label' => 'Log tool registration to browser console' )
        );

        add_settings_field( 'auto_detect_forms', __( 'Auto-Detect Forms', 'wp-webmcp' ),
            array( $this, 'render_checkbox_field' ),
            'wp-webmcp', 'wp_webmcp_general',
            array( 'key' => 'auto_detect_forms', 'label' => 'Automatically detect and annotate forms on published pages' )
        );

        add_settings_field( 'origin_isolation', __( 'Origin Isolation', 'wp-webmcp' ),
            array( $this, 'render_checkbox_field' ),
            'wp-webmcp', 'wp_webmcp_general',
            array( 'key' => 'origin_isolation', 'label' => 'Inject origin isolation headers (required for WebMCP)' )
        );

        add_settings_field( 'permissions_policy', __( 'Permissions Policy', 'wp-webmcp' ),
            array( $this, 'render_checkbox_field' ),
            'wp-webmcp', 'wp_webmcp_general',
            array( 'key' => 'permissions_policy', 'label' => 'Inject permissions policy header for tools' )
        );
    }

    /**
     * Sanitize settings.
     *
     * @param array $input Raw input.
     * @return array
     */
    public function sanitize_settings( $input ) {
        $sanitized = array();

        $sanitized['enabled']           = ! empty( $input['enabled'] );
        $sanitized['debug_mode']         = ! empty( $input['debug_mode'] );
        $sanitized['auto_detect_forms']  = ! empty( $input['auto_detect_forms'] );
        $sanitized['origin_isolation']   = ! empty( $input['origin_isolation'] );
        $sanitized['permissions_policy'] = ! empty( $input['permissions_policy'] );

        return $sanitized;
    }

    /**
     * Render settings page.
     */
    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $tools = wp_webmcp_get_tools();
        ?>
        <div class="wrap">
            <h1>🔧 WebMCP Settings</h1>
            <p>Expose your WordPress site as agent-ready tools for AI agents. <a href="https://developer.chrome.com/docs/ai/webmcp" target="_blank">Learn about WebMCP →</a></p>

            <div class="wp-webmcp-tabs">
                <button class="nav-tab nav-tab-active" data-tab="settings">Settings</button>
                <button class="nav-tab" data-tab="tools">Tools (<?php echo count( $tools ); ?>)</button>
                <button class="nav-tab" data-tab="scan">Form Scanner</button>
            </div>

            <div id="tab-settings" class="wp-webmcp-tab-content nav-tab-active-content">
                <form method="post" action="options.php">
                    <?php
                    settings_fields( 'wp_webmcp_settings_group' );
                    do_settings_sections( 'wp-webmcp' );
                    submit_button();
                    ?>
                </form>
            </div>

            <div id="tab-tools" class="wp-webmcp-tab-content" style="display:none;">
                <h2>Registered Tools</h2>
                <table class="wp-list-table widefat striped">
                    <thead>
                        <tr>
                            <th>Tool Name</th>
                            <th>Type</th>
                            <th>Description</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ( empty( $tools ) ) : ?>
                        <tr><td colspan="5">No tools registered yet.</td></tr>
                    <?php else : ?>
                        <?php foreach ( $tools as $name => $tool ) : ?>
                            <tr>
                                <td><code><?php echo esc_html( $name ); ?></code></td>
                                <td><?php echo esc_html( $tool['type'] ); ?></td>
                                <td><?php echo esc_html( $tool['description'] ); ?></td>
                                <td>
                                    <?php echo $tool['enabled'] ? '<span style="color:green;">✓ Enabled</span>' : '<span style="color:#ccc;">○ Disabled</span>'; ?>
                                </td>
                                <td>
                                    <?php if ( 'declarative' === $tool['type'] ) : ?>
                                        <button class="button button-small wp-webmcp-toggle-tool" data-tool="<?php echo esc_attr( $name ); ?>">
                                            <?php echo $tool['enabled'] ? 'Disable' : 'Enable'; ?>
                                        </button>
                                        <button class="button button-small wp-webmcp-delete-tool" data-tool="<?php echo esc_attr( $name ); ?>">Delete</button>
                                    <?php else : ?>
                                        <em>Managed in PHP</em>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>

                <h3>Developer-registered tools</h3>
                <p>Imperative tools are registered by trusted plugin or theme PHP code, not through this screen. This prevents database options from selecting executable callbacks.</p>
            </div>

            <div id="tab-scan" class="wp-webmcp-tab-content" style="display:none;">
                <h2>Form Scanner</h2>
                <p>Scan your published pages for forms that can be exposed as WebMCP tools.</p>
                <button class="button button-primary" id="wp-webmcp-scan">Scan Site</button>
                <div id="wp-webmcp-scan-results"></div>
            </div>
        </div>
        <?php
    }

    /**
     * Render general section description.
     */
    public function render_general_section() {
        echo '<p>Configure how WebMCP tools are exposed on your site.</p>';
    }

    /**
     * Render a checkbox field.
     *
     * @param array $args Field arguments.
     */
    public function render_checkbox_field( $args ) {
        $settings = wp_webmcp_get_settings();
        $key      = $args['key'];
        $label    = $args['label'];
        $checked  = ! empty( $settings[ $key ] );
        ?>
        <label>
            <input type="checkbox" name="<?php echo esc_attr( WP_WEBMCP_OPTION_KEY . '[' . $key . ']' ); ?>" value="1" <?php checked( $checked ); ?> />
            <?php echo esc_html( $label ); ?>
        </label>
        <?php
    }

    /**
     * Enqueue admin assets.
     *
     * @param string $hook Current admin page hook.
     */
    public function enqueue_admin_assets( $hook ) {
        if ( 'settings_page_wp-webmcp' !== $hook ) {
            return;
        }

        wp_enqueue_script(
            'wp-webmcp-admin',
            WP_WEBMCP_PLUGIN_URL . 'assets/js/webmcp-admin.js',
            array( 'jquery' ),
            WP_WEBMCP_VERSION,
            true
        );

        wp_localize_script( 'wp-webmcp-admin', 'WP_WebMCP_Admin', array(
            'restUrl' => rest_url( 'wp-webmcp/v1' ),
            'nonce'   => wp_create_nonce( 'wp_rest' ),
        ) );

        wp_enqueue_style(
            'wp-webmcp-admin',
            WP_WEBMCP_PLUGIN_URL . 'assets/css/webmcp-admin.css',
            array(),
            WP_WEBMCP_VERSION
        );
    }
}
