<?php
/**
 * Form Detector — Auto-detect WordPress forms and annotate them
 *
 * @package WP_WebMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class WP_WebMCP_Form_Detector
 *
 * Detects forms on the current page and prepares them for WebMCP
 * declarative annotation. Supports:
 * - Native HTML forms
 * - Gutenberg form blocks
 * - Contact Form 7
 * - WPForms
 * - Gravity Forms
 */
class WP_WebMCP_Form_Detector {

    /**
     * Instance.
     *
     * @var WP_WebMCP_Form_Detector|null
     */
    private static $instance = null;

    /**
     * Detected forms on current page.
     *
     * @var array
     */
    private $detected_forms = array();

    /**
     * Get singleton instance.
     *
     * @return WP_WebMCP_Form_Detector
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

        // Detect forms in the final HTML output.
        add_filter( 'the_content', array( $this, 'detect_in_content' ), 99 );
        add_filter( 'wp_footer', array( $this, 'output_form_data' ), 99 );

        // Register REST endpoint for form scanning.
        add_action( 'rest_api_init', array( $this, 'register_scan_endpoint' ) );
    }

    /**
     * Detect forms in post content.
     *
     * @param string $content Post content.
     * @return string
     */
    public function detect_in_content( $content ) {
        if ( ! is_singular() ) {
            return $content;
        }

        // Pattern: find all <form> elements.
        if ( preg_match_all( '/<form[^>]*>/i', $content, $matches ) ) {
            foreach ( $matches[0] as $index => $form_tag ) {
                $form_id    = $this->extract_attribute( $form_tag, 'id' );
                $form_action = $this->extract_attribute( $form_tag, 'action' );
                $form_class = $this->extract_attribute( $form_tag, 'class' );

                $this->detected_forms[] = array(
                    'id'         => $form_id ?: 'wp-webmcp-form-' . $index,
                    'action'     => $form_action,
                    'class'      => $form_class,
                    'plugin'     => $this->detect_form_plugin( $form_tag, $form_class ),
                    'type'       => 'declarative',
                );
            }
        }

        return $content;
    }

    /**
     * Extract an attribute value from an HTML tag.
     *
     * @param string $tag HTML tag.
     * @param string $attr Attribute name.
     * @return string|false
     */
    private function extract_attribute( $tag, $attr ) {
        if ( preg_match( '/' . $attr . '=["\']([^"\']*)["\']/i', $tag, $m ) ) {
            return $m[1];
        }
        return false;
    }

    /**
     * Detect which form plugin generated the form.
     *
     * @param string $form_tag Form HTML tag.
     * @param string|false $class Form class attribute.
     * @return string
     */
    private function detect_form_plugin( $form_tag, $class ) {
        $class_lower = strtolower( $class ?: '' );

        if ( false !== strpos( $class_lower, 'wpcf7' ) || false !== strpos( $form_tag, 'wpcf7' ) ) {
            return 'contact-form-7';
        }
        if ( false !== strpos( $class_lower, 'wpforms' ) ) {
            return 'wpforms';
        }
        if ( false !== strpos( $class_lower, 'gform' ) || false !== strpos( $form_tag, 'gform' ) ) {
            return 'gravity-forms';
        }
        if ( false !== strpos( $class_lower, 'wp-block' ) ) {
            return 'gutenberg';
        }

        return 'native';
    }

    /**
     * Output detected form data as JSON for the JS loader.
     */
    public function output_form_data() {
        if ( empty( $this->detected_forms ) ) {
            return;
        }

        // Allow filtering detected forms.
        $this->detected_forms = apply_filters( 'wp_webmcp_detected_forms', $this->detected_forms );

        echo '<script type="application/json" id="wp-webmcp-forms">' . "\n";
        echo wp_json_encode( $this->detected_forms );
        echo "\n" . '</script>' . "\n";
    }

    /**
     * Register REST endpoint for scanning site forms.
     */
    public function register_scan_endpoint() {
        register_rest_route(
            'wp-webmcp/v1',
            '/scan',
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'scan_site_forms' ),
                'permission_callback' => function () {
                    return current_user_can( 'manage_options' );
                },
            )
        );
    }

    /**
     * Scan site for forms (admin utility).
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function scan_site_forms( $request ) {
        $results = array();

        // Query published posts/pages that likely contain forms.
        $query = new WP_Query(
            array(
                'post_type'      => 'any',
                'post_status'     => 'publish',
                'posts_per_page'  => 100,
                's'               => '<form',
            )
        );

        if ( $query->have_posts() ) {
            foreach ( $query->posts as $post ) {
                $form_count = preg_match_all( '/<form[^>]*>/i', $post->post_content );
                if ( $form_count > 0 ) {
                    $results[] = array(
                        'id'         => $post->ID,
                        'title'      => $post->post_title,
                        'url'        => get_permalink( $post->ID ),
                        'form_count' => $form_count,
                        'post_type'  => $post->post_type,
                    );
                }
            }
        }

        return rest_ensure_response( $results );
    }
}
