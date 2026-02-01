<?php
/**
 * Network Admin functionality for Glimmr AI.
 *
 * Handles network-level settings management for WordPress Multisite.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Class Glimmr_AI_Network_Admin
 *
 * Manages network-wide settings and admin pages for multisite installations.
 */
class Glimmr_AI_Network_Admin {

    /**
     * The menu slug for the network admin page.
     *
     * @var string
     */
    const MENU_SLUG = 'glimmr-ai-network';

    /**
     * The capability required to access network admin pages.
     *
     * @var string
     */
    const CAPABILITY = 'manage_network_options';

    /**
     * Initialize the class.
     *
     * @since 1.0.0
     */
    public function __construct() {
        // Constructor.
    }

    /**
     * Register hooks for network admin.
     *
     * @since 1.0.0
     * @return void
     */
    public function init() {
        // Only run on multisite.
        if ( ! is_multisite() ) {
            return;
        }

        // Network admin menu.
        add_action( 'network_admin_menu', array( $this, 'add_network_menu' ) );

        // Enqueue scripts for network admin.
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

        // AJAX handlers for network settings.
        add_action( 'wp_ajax_glimmr_ai_get_network_settings', array( $this, 'ajax_get_network_settings' ) );
        add_action( 'wp_ajax_glimmr_ai_save_network_settings', array( $this, 'ajax_save_network_settings' ) );
        add_action( 'wp_ajax_glimmr_ai_get_network_sites', array( $this, 'ajax_get_network_sites' ) );
    }

    /**
     * Add network admin menu.
     *
     * @since 1.0.0
     * @return void
     */
    public function add_network_menu() {
        add_menu_page(
            __( 'Glimmr AI Network Settings', 'glimmr-ai' ),
            __( 'Glimmr AI', 'glimmr-ai' ),
            self::CAPABILITY,
            self::MENU_SLUG,
            array( $this, 'render_network_page' ),
            'dashicons-format-chat',
            30
        );
    }

    /**
     * Enqueue scripts for network admin page.
     *
     * @since 1.0.0
     * @param string $hook The current admin page hook.
     * @return void
     */
    public function enqueue_scripts( $hook ) {
        // Only load on our network admin page.
        if ( 'toplevel_page_' . self::MENU_SLUG !== $hook ) {
            return;
        }

        // Enqueue React and dependencies.
        wp_enqueue_script( 'wp-element' );
        wp_enqueue_script( 'wp-components' );
        wp_enqueue_style( 'wp-components' );

        // Check if the compiled React bundle exists.
        $bundle_path = GLIMMR_AI_PLUGIN_DIR . 'admin/js/glimmr-ai-admin-bundle.js';
        $script_file = file_exists( $bundle_path )
            ? 'admin/js/glimmr-ai-admin-bundle.js'
            : 'admin/js/glimmr-ai-admin.js';

        wp_enqueue_script(
            'glimmr-ai-network-admin',
            GLIMMR_AI_PLUGIN_URL . $script_file,
            array( 'jquery', 'wp-element', 'wp-components' ),
            GLIMMR_AI_VERSION,
            true
        );

        // Admin styles (legacy/base).
        wp_enqueue_style(
            'glimmr-ai-admin',
            GLIMMR_AI_PLUGIN_URL . 'admin/css/glimmr-ai-admin.css',
            array(),
            GLIMMR_AI_VERSION,
            'all'
        );

        // Compiled SCSS bundle from webpack (main styles for React components).
        $bundle_css_path = GLIMMR_AI_PLUGIN_DIR . 'admin/js/glimmr-ai-admin-bundle.css';
        if ( file_exists( $bundle_css_path ) ) {
            wp_enqueue_style(
                'glimmr-ai-admin-bundle',
                GLIMMR_AI_PLUGIN_URL . 'admin/js/glimmr-ai-admin-bundle.css',
                array( 'glimmr-ai-admin', 'wp-components' ),
                GLIMMR_AI_VERSION,
                'all'
            );
        }

        // Get default prompt from Context class.
        $context = new Glimmr_AI_Context( new Glimmr_AI_Settings() );
        $default_prompt = $context->get_default_system_prompt();

        // Localize script with network-specific data.
        wp_localize_script(
            'glimmr-ai-network-admin',
            'glimmrAI',
            array(
                'ajaxUrl'             => admin_url( 'admin-ajax.php' ),
                'restUrl'             => rest_url( 'glimmr-ai/v1/' ),
                'nonce'               => wp_create_nonce( 'glimmr_ai_network_admin' ),
                'restNonce'           => wp_create_nonce( 'wp_rest' ),
                'settings'            => Glimmr_AI_Settings::get_network(),
                'isMultisite'         => true,
                'isNetworkAdmin'      => true,
                'isNetworkAdminPage'  => true,
                'currentPage'         => 'network-settings',
                'siteName'            => get_network()->site_name,
                'siteUrl'             => network_home_url(),
                'strings'             => $this->get_network_strings(),
                'defaultPrompt'       => $default_prompt,
                'inheritableSettings' => Glimmr_AI_Settings::get_inheritable_settings(),
                'lockableSettings'    => Glimmr_AI_Settings::get_lockable_settings(),
                'availableModels'     => $this->get_available_models(),
            )
        );
    }

    /**
     * Render the network admin page.
     *
     * @since 1.0.0
     * @return void
     */
    public function render_network_page() {
        ?>
        <div class="wrap glimmr-ai-admin">
            <h1><?php esc_html_e( 'Glimmr AI Network Settings', 'glimmr-ai' ); ?></h1>
            <p class="description">
                <?php esc_html_e( 'Configure default settings that apply to all sites in the network. Individual sites can override these settings unless locked.', 'glimmr-ai' ); ?>
            </p>
            <div id="glimmr-ai-network-root">
                <!-- React app mounts here -->
                <div class="glimmr-ai-loading">
                    <span class="spinner is-active"></span>
                    <?php esc_html_e( 'Loading network settings...', 'glimmr-ai' ); ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Get localized strings for network admin.
     *
     * @since 1.0.0
     * @return array Localized strings.
     */
    private function get_network_strings() {
        return array(
            'save'              => __( 'Save Network Settings', 'glimmr-ai' ),
            'saving'            => __( 'Saving...', 'glimmr-ai' ),
            'saved'             => __( 'Network settings saved.', 'glimmr-ai' ),
            'error'             => __( 'Error saving settings.', 'glimmr-ai' ),
            'apiSettings'       => __( 'API Configuration', 'glimmr-ai' ),
            'rateLimits'        => __( 'Rate Limits & Token Budgets', 'glimmr-ai' ),
            'widgetDefaults'    => __( 'Widget Appearance Defaults', 'glimmr-ai' ),
            'agentConfig'       => __( 'Agent Configuration', 'glimmr-ai' ),
            'toolsConfig'       => __( 'Tools Configuration', 'glimmr-ai' ),
            'lockedSettings'    => __( 'Locked Settings', 'glimmr-ai' ),
            'lockedDescription' => __( 'Locked settings cannot be overridden by individual sites.', 'glimmr-ai' ),
            'networkSites'      => __( 'Network Sites', 'glimmr-ai' ),
            'inheritanceNote'   => __( 'Sites will inherit these values unless they set their own or the setting is locked.', 'glimmr-ai' ),
        );
    }

    /**
     * Get available OpenAI models.
     *
     * @since 1.0.0
     * @return array List of available models.
     */
    private function get_available_models() {
        return array(
            'gpt-4o'        => 'GPT-4o (Recommended)',
            'gpt-4o-mini'   => 'GPT-4o Mini (Faster, Cheaper)',
            'gpt-4-turbo'   => 'GPT-4 Turbo',
            'gpt-4'         => 'GPT-4',
            'gpt-4.1'       => 'GPT-4.1',
            'gpt-4.1-mini'  => 'GPT-4.1 Mini',
            'gpt-4.1-nano'  => 'GPT-4.1 Nano',
            'o4-mini'       => 'o4-mini (Reasoning)',
            'o3-mini'       => 'o3-mini (Reasoning)',
        );
    }

    // =========================================================================
    // AJAX Handlers
    // =========================================================================

    /**
     * AJAX handler for getting network settings.
     *
     * @since 1.0.0
     * @return void
     */
    public function ajax_get_network_settings() {
        check_ajax_referer( 'glimmr_ai_network_admin', 'nonce' );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'glimmr-ai' ) ) );
        }

        $network_settings = Glimmr_AI_Settings::get_network();

        // Add metadata.
        $network_settings['_inheritable_settings'] = Glimmr_AI_Settings::get_inheritable_settings();
        $network_settings['_lockable_settings'] = Glimmr_AI_Settings::get_lockable_settings();

        // Mask API key for display.
        if ( ! empty( $network_settings['openai_api_key_encrypted'] ) ) {
            $network_settings['openai_api_key_masked'] = '••••••••' . substr( Glimmr_AI_Settings::get_api_key(), -4 );
            $network_settings['has_api_key'] = true;
        } else {
            $network_settings['has_api_key'] = false;
        }

        wp_send_json_success( $network_settings );
    }

    /**
     * AJAX handler for saving network settings.
     *
     * @since 1.0.0
     * @return void
     */
    public function ajax_save_network_settings() {
        check_ajax_referer( 'glimmr_ai_network_admin', 'nonce' );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'glimmr-ai' ) ) );
        }

        // Get posted settings.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized in sanitize_network_settings().
        $settings = isset( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : array();

        if ( empty( $settings ) || ! is_array( $settings ) ) {
            wp_send_json_error( array( 'message' => __( 'No settings provided.', 'glimmr-ai' ) ) );
        }

        // Sanitize settings before saving.
        $settings = $this->sanitize_network_settings( $settings );

        // Save network settings.
        $result = Glimmr_AI_Settings::update_network( $settings );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( array(
            'message'  => __( 'Network settings saved successfully.', 'glimmr-ai' ),
            'settings' => Glimmr_AI_Settings::get_network(),
        ) );
    }

    /**
     * AJAX handler for getting network sites.
     *
     * @since 1.0.0
     * @return void
     */
    public function ajax_get_network_sites() {
        check_ajax_referer( 'glimmr_ai_network_admin', 'nonce' );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'glimmr-ai' ) ) );
        }

        $sites = get_sites( array(
            'number' => 100,
            'fields' => 'ids',
        ) );

        $site_data = array();
        foreach ( $sites as $site_id ) {
            switch_to_blog( $site_id );

            $site_settings = Glimmr_AI_Settings::get_site_raw();

            $site_data[] = array(
                'id'                => $site_id,
                'name'              => get_bloginfo( 'name' ),
                'url'               => home_url(),
                'inherits_settings' => ! empty( $site_settings['inherit_network_settings'] ),
                'has_api_key'       => ! empty( $site_settings['openai_api_key_encrypted'] ),
                'widget_enabled'    => $site_settings['widget_enabled'] ?? true,
            );

            restore_current_blog();
        }

        wp_send_json_success( array(
            'sites' => $site_data,
            'total' => count( $site_data ),
        ) );
    }

    /**
     * Sanitize network settings.
     *
     * @since 1.0.0
     * @param array $settings Raw settings from POST.
     * @return array Sanitized settings.
     */
    private function sanitize_network_settings( $settings ) {
        $sanitized = array();

        // Handle API key separately (encryption is done in Settings class).
        if ( isset( $settings['openai_api_key'] ) && ! empty( $settings['openai_api_key'] ) ) {
            // Only update if a new key is provided (not the masked placeholder).
            if ( strpos( $settings['openai_api_key'], '••••' ) === false ) {
                $sanitized['openai_api_key'] = sanitize_text_field( $settings['openai_api_key'] );
            }
        }

        // Text fields.
        $text_fields = array(
            'openai_vector_store_id',
            'openai_model',
            'widget_position',
            'widget_primary_color',
            'widget_primary_hover',
            'widget_secondary_color',
            'widget_bg_color',
            'widget_bg_light',
            'widget_border_color',
            'widget_text_color',
            'widget_text_dark',
            'widget_text_muted',
            'widget_success_color',
            'widget_error_color',
            'widget_button_border',
            'widget_font_family',
            'widget_avatar_url',
            'widget_name',
            'agent_tone',
            'agent_personality',
            'fallback_response',
            'product_sync_schedule',
            'knowledge_sync_schedule',
            'support_email',
            'support_phone',
            'gdpr_consent_text',
        );

        foreach ( $text_fields as $field ) {
            if ( isset( $settings[ $field ] ) ) {
                $sanitized[ $field ] = sanitize_text_field( wp_unslash( $settings[ $field ] ) );
            }
        }

        // Integer fields.
        $int_fields = array(
            'max_tokens_per_response',
            'max_messages_per_conversation',
            'conversation_expiry_days',
            'rate_limit_authenticated',
            'rate_limit_anonymous',
            'rate_limit_window_seconds',
            'daily_token_limit',
            'monthly_token_limit',
            'product_sync_batch_size',
            'widget_width',
            'widget_height',
            'widget_button_border_width',
            'widget_border_radius',
            'data_retention_days',
        );

        foreach ( $int_fields as $field ) {
            if ( isset( $settings[ $field ] ) ) {
                $sanitized[ $field ] = absint( $settings[ $field ] );
            }
        }

        // Boolean fields.
        $bool_fields = array(
            'product_sync_enabled',
            'widget_enabled',
            'gdpr_enabled',
            'privacy_export_enabled',
            'privacy_erasure_enabled',
        );

        foreach ( $bool_fields as $field ) {
            if ( isset( $settings[ $field ] ) ) {
                $sanitized[ $field ] = filter_var( $settings[ $field ], FILTER_VALIDATE_BOOLEAN );
            }
        }

        // HTML fields (widget greeting).
        if ( isset( $settings['widget_greeting'] ) ) {
            $sanitized['widget_greeting'] = wp_kses_post( wp_unslash( $settings['widget_greeting'] ) );
        }

        // System prompt (plain text).
        if ( isset( $settings['system_prompt'] ) ) {
            $sanitized['system_prompt'] = wp_strip_all_tags( wp_unslash( $settings['system_prompt'] ) );
        }

        // Locked settings array.
        if ( isset( $settings['locked_settings'] ) && is_array( $settings['locked_settings'] ) ) {
            $lockable = Glimmr_AI_Settings::get_lockable_settings();
            $sanitized['locked_settings'] = array_values( array_intersect(
                array_map( 'sanitize_key', $settings['locked_settings'] ),
                $lockable
            ) );
        }

        // Enabled tools array.
        if ( isset( $settings['enabled_tools'] ) && is_array( $settings['enabled_tools'] ) ) {
            $sanitized['enabled_tools'] = array_map( 'boolval', $settings['enabled_tools'] );
        }

        // Network allowed tools (tools that sites are permitted to enable).
        if ( isset( $settings['network_allowed_tools'] ) && is_array( $settings['network_allowed_tools'] ) ) {
            $sanitized['network_allowed_tools'] = array_map( 'sanitize_key', $settings['network_allowed_tools'] );
        }

        return $sanitized;
    }
}
