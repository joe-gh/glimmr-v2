<?php
/**
 * The admin-specific functionality of the plugin.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Class Glimmr_AI_Admin
 *
 * Handles all admin functionality including menus, settings pages, and AJAX handlers.
 */
class Glimmr_AI_Admin {

    /**
     * The menu slug for the main admin page.
     *
     * @var string
     */
    const MENU_SLUG = 'glimmr-ai';

    /**
     * The capability required to access admin pages.
     *
     * @var string
     */
    const CAPABILITY = 'manage_options';

    /**
     * Initialize the class.
     *
     * @since 1.0.0
     */
    public function __construct() {
        // Constructor - can add initialization here if needed.
    }

    /**
     * Register the stylesheets for the admin area.
     *
     * @since 1.0.0
     * @param string $hook The current admin page hook.
     * @return void
     */
    public function enqueue_styles( $hook ) {
        // Only load on our plugin pages.
        if ( ! $this->is_plugin_page( $hook ) ) {
            return;
        }

        // Legacy admin CSS (fallback/base styles).
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
    }

    /**
     * Register the JavaScript for the admin area.
     *
     * @since 1.0.0
     * @param string $hook The current admin page hook.
     * @return void
     */
    public function enqueue_scripts( $hook ) {
        // Only load on our plugin pages.
        if ( ! $this->is_plugin_page( $hook ) ) {
            return;
        }

        // Enqueue React and dependencies for modern UI.
        wp_enqueue_script( 'wp-element' );
        wp_enqueue_script( 'wp-components' );
        wp_enqueue_style( 'wp-components' );

        // Check if the compiled React bundle exists, otherwise fall back to jQuery placeholder.
        $bundle_path = GLIMMR_AI_PLUGIN_DIR . 'admin/js/glimmr-ai-admin-bundle.js';
        $script_file = file_exists( $bundle_path )
            ? 'admin/js/glimmr-ai-admin-bundle.js'
            : 'admin/js/glimmr-ai-admin.js';

        wp_enqueue_script(
            'glimmr-ai-admin',
            GLIMMR_AI_PLUGIN_URL . $script_file,
            array( 'jquery', 'react', 'react-dom', 'wp-element', 'wp-components' ),
            GLIMMR_AI_VERSION,
            true
        );

        // Get default prompt from Context class (single source of truth).
        $context = new Glimmr_AI_Context( new Glimmr_AI_Settings() );
        $default_prompt = $context->get_default_system_prompt();

        // Localize script with data.
        wp_localize_script(
            'glimmr-ai-admin',
            'glimmrAI',
            array(
                'ajaxUrl'            => admin_url( 'admin-ajax.php' ),
                'restUrl'            => rest_url( 'glimmr-ai/v1/' ),
                'nonce'              => wp_create_nonce( 'glimmr_ai_admin' ),
                'restNonce'          => wp_create_nonce( 'wp_rest' ),
                'settings'           => Glimmr_AI_Settings::get_all_safe(), // S16: API keys excluded.
                'isMultisite'        => is_multisite(),
                'isNetworkAdmin'     => Glimmr_AI_Database::can_view_network_data(),
                'isNetworkAdminPage' => false, // This is site admin, not network admin.
                'currentSiteId'      => Glimmr_AI_Database::get_current_site_id(),
                'currentPage'        => $this->get_current_page( $hook ),
                'siteName'           => get_bloginfo( 'name' ),
                'siteUrl'            => home_url(),
                'strings'            => $this->get_admin_strings(),
                'defaultPrompt'      => $default_prompt,
                'lockableSettings'   => Glimmr_AI_Settings::get_lockable_settings(),
            )
        );
    }

    /**
     * Check if current page is a plugin page.
     *
     * @param string $hook The current admin page hook.
     * @return bool
     */
    private function is_plugin_page( $hook ) {
        $plugin_pages = array(
            'toplevel_page_' . self::MENU_SLUG,
            'glimmr-ai_page_glimmr-ai-dashboard',
            'glimmr-ai_page_glimmr-ai-settings',
            'glimmr-ai_page_glimmr-ai-knowledge',
            'glimmr-ai_page_glimmr-ai-prompts',
            'glimmr-ai_page_glimmr-ai-conversations',
            'glimmr-ai_page_glimmr-ai-contact-requests',
        );

        return in_array( $hook, $plugin_pages, true );
    }

    /**
     * Get current page slug from hook.
     *
     * @param string $hook The admin page hook.
     * @return string
     */
    private function get_current_page( $hook ) {
        $page_map = array(
            'toplevel_page_' . self::MENU_SLUG            => 'get-started',
            'glimmr-ai_page_glimmr-ai-dashboard'          => 'dashboard',
            'glimmr-ai_page_glimmr-ai-settings'           => 'settings',
            'glimmr-ai_page_glimmr-ai-knowledge'          => 'knowledge',
            'glimmr-ai_page_glimmr-ai-prompts'            => 'prompts',
            'glimmr-ai_page_glimmr-ai-conversations'      => 'conversations',
            'glimmr-ai_page_glimmr-ai-contact-requests'   => 'contact-requests',
        );

        return isset( $page_map[ $hook ] ) ? $page_map[ $hook ] : 'get-started';
    }

    /**
     * Get translatable strings for JavaScript.
     *
     * @return array
     */
    private function get_admin_strings() {
        return array(
            'saving'            => __( 'Saving...', 'glimmr-ai' ),
            'saved'             => __( 'Settings saved successfully.', 'glimmr-ai' ),
            'error'             => __( 'An error occurred. Please try again.', 'glimmr-ai' ),
            'syncStarted'       => __( 'Sync started...', 'glimmr-ai' ),
            'syncComplete'      => __( 'Sync completed successfully.', 'glimmr-ai' ),
            'confirmReset'      => __( 'Are you sure you want to reset all settings to defaults?', 'glimmr-ai' ),
            'confirmDelete'     => __( 'Are you sure you want to delete this item?', 'glimmr-ai' ),
            'noConversations'   => __( 'No conversations found.', 'glimmr-ai' ),
            'loading'           => __( 'Loading...', 'glimmr-ai' ),
        );
    }

    /**
     * Add admin menu pages.
     *
     * @since 1.0.0
     * @return void
     */
    public function add_admin_menu() {
        // Main menu - points to Get Started page.
        add_menu_page(
            __( 'Glimmr AI', 'glimmr-ai' ),
            __( 'Glimmr AI', 'glimmr-ai' ),
            self::CAPABILITY,
            self::MENU_SLUG,
            array( $this, 'render_get_started_page' ),
            'dashicons-format-chat',
            56
        );

        // Get Started submenu (first item, replaces default).
        add_submenu_page(
            self::MENU_SLUG,
            __( 'Get Started', 'glimmr-ai' ),
            __( 'Get Started', 'glimmr-ai' ),
            self::CAPABILITY,
            self::MENU_SLUG,
            array( $this, 'render_get_started_page' )
        );

        // Dashboard.
        add_submenu_page(
            self::MENU_SLUG,
            __( 'Dashboard', 'glimmr-ai' ),
            __( 'Dashboard', 'glimmr-ai' ),
            self::CAPABILITY,
            self::MENU_SLUG . '-dashboard',
            array( $this, 'render_dashboard_page' )
        );

        // Settings.
        add_submenu_page(
            self::MENU_SLUG,
            __( 'Settings', 'glimmr-ai' ),
            __( 'Settings', 'glimmr-ai' ),
            self::CAPABILITY,
            self::MENU_SLUG . '-settings',
            array( $this, 'render_settings_page' )
        );

        // Knowledge Base.
        add_submenu_page(
            self::MENU_SLUG,
            __( 'Knowledge Base', 'glimmr-ai' ),
            __( 'Knowledge Base', 'glimmr-ai' ),
            self::CAPABILITY,
            self::MENU_SLUG . '-knowledge',
            array( $this, 'render_knowledge_page' )
        );

        // Prompts & Tools.
        add_submenu_page(
            self::MENU_SLUG,
            __( 'Prompts & Tools', 'glimmr-ai' ),
            __( 'Prompts & Tools', 'glimmr-ai' ),
            self::CAPABILITY,
            self::MENU_SLUG . '-prompts',
            array( $this, 'render_prompts_page' )
        );

        // Conversations.
        add_submenu_page(
            self::MENU_SLUG,
            __( 'Conversations', 'glimmr-ai' ),
            __( 'Conversations', 'glimmr-ai' ),
            self::CAPABILITY,
            self::MENU_SLUG . '-conversations',
            array( $this, 'render_conversations_page' )
        );

        // Contact Requests.
        add_submenu_page(
            self::MENU_SLUG,
            __( 'Contact Requests', 'glimmr-ai' ),
            __( 'Contact Requests', 'glimmr-ai' ),
            self::CAPABILITY,
            self::MENU_SLUG . '-contact-requests',
            array( $this, 'render_contact_requests_page' )
        );

        // License.
        add_submenu_page(
            self::MENU_SLUG,
            __( 'License', 'glimmr-ai' ),
            __( 'License', 'glimmr-ai' ),
            self::CAPABILITY,
            self::MENU_SLUG . '-license',
            array( $this, 'render_license_page' )
        );
    }

    /**
     * Add network admin menu for multisite.
     *
     * @since 1.0.0
     * @return void
     */
    public function add_network_admin_menu() {
        if ( ! is_multisite() ) {
            return;
        }

        add_menu_page(
            __( 'Glimmr AI Network', 'glimmr-ai' ),
            __( 'Glimmr AI', 'glimmr-ai' ),
            'manage_network_options',
            self::MENU_SLUG . '-network',
            array( $this, 'render_network_settings_page' ),
            'dashicons-format-chat',
            56
        );
    }

    /**
     * Render the Get Started page.
     *
     * @since 1.8.0
     * @return void
     */
    public function render_get_started_page() {
        ?>
        <div class="wrap glimmr-ai-admin glimmr-ai-get-started">
            <div id="glimmr-ai-get-started-root">
                <div class="glimmr-ai-loading">
                    <span class="spinner is-active"></span>
                    <?php esc_html_e( 'Loading...', 'glimmr-ai' ); ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render the dashboard page.
     *
     * @since 1.0.0
     * @return void
     */
    public function render_dashboard_page() {
        ?>
        <div class="wrap glimmr-ai-admin">
            <h1><?php esc_html_e( 'Glimmr AI Dashboard', 'glimmr-ai' ); ?></h1>
            <div id="glimmr-ai-dashboard-root">
                <div class="glimmr-ai-loading">
                    <span class="spinner is-active"></span>
                    <?php esc_html_e( 'Loading dashboard...', 'glimmr-ai' ); ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render the settings page.
     *
     * @since 1.0.0
     * @return void
     */
    public function render_settings_page() {
        ?>
        <div class="wrap glimmr-ai-admin">
            <h1><?php esc_html_e( 'Glimmr AI Settings', 'glimmr-ai' ); ?></h1>
            <div id="glimmr-ai-settings-root">
                <div class="glimmr-ai-loading">
                    <span class="spinner is-active"></span>
                    <?php esc_html_e( 'Loading settings...', 'glimmr-ai' ); ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render the knowledge base page.
     *
     * @since 1.0.0
     * @return void
     */
    public function render_knowledge_page() {
        ?>
        <div class="wrap glimmr-ai-admin">
            <h1><?php esc_html_e( 'Knowledge Base', 'glimmr-ai' ); ?></h1>
            <div id="glimmr-ai-knowledge-root">
                <div class="glimmr-ai-loading">
                    <span class="spinner is-active"></span>
                    <?php esc_html_e( 'Loading knowledge base...', 'glimmr-ai' ); ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render the prompts & tools page.
     *
     * @since 1.0.0
     * @return void
     */
    public function render_prompts_page() {
        ?>
        <div class="wrap glimmr-ai-admin">
            <h1><?php esc_html_e( 'Prompts & Tools', 'glimmr-ai' ); ?></h1>
            <div id="glimmr-ai-prompts-root">
                <div class="glimmr-ai-loading">
                    <span class="spinner is-active"></span>
                    <?php esc_html_e( 'Loading prompts configuration...', 'glimmr-ai' ); ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render the conversations page.
     *
     * @since 1.0.0
     * @return void
     */
    public function render_conversations_page() {
        ?>
        <div class="wrap glimmr-ai-admin">
            <h1><?php esc_html_e( 'Conversations', 'glimmr-ai' ); ?></h1>
            <div id="glimmr-ai-conversations-root">
                <div class="glimmr-ai-loading">
                    <span class="spinner is-active"></span>
                    <?php esc_html_e( 'Loading conversations...', 'glimmr-ai' ); ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render the contact requests page.
     *
     * @since 1.8.0
     * @return void
     */
    public function render_contact_requests_page() {
        ?>
        <div class="wrap glimmr-ai-admin">
            <h1><?php esc_html_e( 'Contact Requests', 'glimmr-ai' ); ?></h1>
            <div id="glimmr-ai-contact-requests-root">
                <div class="glimmr-ai-loading">
                    <span class="spinner is-active"></span>
                    <?php esc_html_e( 'Loading contact requests...', 'glimmr-ai' ); ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render network settings page for multisite.
     *
     * @since 1.0.0
     * @return void
     */
    public function render_network_settings_page() {
        ?>
        <div class="wrap glimmr-ai-admin">
            <h1><?php esc_html_e( 'Glimmr AI Network Settings', 'glimmr-ai' ); ?></h1>
            <div id="glimmr-ai-network-settings-root">
                <div class="glimmr-ai-loading">
                    <span class="spinner is-active"></span>
                    <?php esc_html_e( 'Loading network settings...', 'glimmr-ai' ); ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Handle activation redirect.
     *
     * @since 1.0.0
     * @return void
     */
    public function activation_redirect() {
        if ( get_transient( 'glimmr_ai_activation_redirect' ) ) {
            delete_transient( 'glimmr_ai_activation_redirect' );

            // Don't redirect if activating multiple plugins.
            if ( isset( $_GET['activate-multi'] ) ) {
                return;
            }

            wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
            exit;
        }
    }

    /**
     * Add plugin action links.
     *
     * @since 1.0.0
     * @param array $links Existing action links.
     * @return array Modified action links.
     */
    public function add_action_links( $links ) {
        $plugin_links = array(
            '<a href="' . admin_url( 'admin.php?page=' . self::MENU_SLUG . '-settings' ) . '">' .
                esc_html__( 'Settings', 'glimmr-ai' ) .
            '</a>',
        );

        return array_merge( $plugin_links, $links );
    }

    /**
     * AJAX handler for getting settings.
     *
     * @since 1.0.0
     * @return void
     */
    public function ajax_get_settings() {
        check_ajax_referer( 'glimmr_ai_admin', 'nonce' );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'glimmr-ai' ) ) );
        }

        wp_send_json_success( $this->prepare_settings_for_frontend() );
    }

    /**
     * AJAX handler for getting product categories.
     *
     * @since 1.0.0
     * @return void
     */
    public function ajax_get_categories() {
        check_ajax_referer( 'glimmr_ai_admin', 'nonce' );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'glimmr-ai' ) ) );
        }

        $categories = array();

        if ( function_exists( 'get_terms' ) ) {
            $terms = get_terms( array(
                'taxonomy'   => 'product_cat',
                'hide_empty' => false,
            ) );

            if ( ! is_wp_error( $terms ) ) {
                foreach ( $terms as $term ) {
                    $categories[] = array(
                        'id'   => $term->term_id,
                        'name' => $term->name,
                        'slug' => $term->slug,
                    );
                }
            }
        }

        wp_send_json_success( $categories );
    }

    /**
     * AJAX handler for saving settings.
     *
     * @since 1.0.0
     * @return void
     */
    public function ajax_save_settings() {
        check_ajax_referer( 'glimmr_ai_admin', 'nonce' );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'glimmr-ai' ) ) );
        }

        $raw_settings = isset( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : '';

        if ( empty( $raw_settings ) ) {
            wp_send_json_error( array( 'message' => __( 'No settings data received.', 'glimmr-ai' ) ) );
        }

        $settings = json_decode( $raw_settings, true );

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            wp_send_json_error( array(
                'message' => sprintf(
                    /* translators: %s: JSON error message */
                    __( 'Invalid JSON: %s', 'glimmr-ai' ),
                    json_last_error_msg()
                ),
            ) );
        }

        if ( ! is_array( $settings ) ) {
            wp_send_json_error( array( 'message' => __( 'Settings must be an object.', 'glimmr-ai' ) ) );
        }

        // Handle API key specially: if it contains asterisks (masked version), don't overwrite.
        if ( isset( $settings['openai_api_key'] ) ) {
            if ( strpos( $settings['openai_api_key'], '*' ) !== false ) {
                // This is the masked version, don't save it.
                unset( $settings['openai_api_key'] );
            } elseif ( empty( $settings['openai_api_key'] ) ) {
                // User cleared the field - clear the encrypted key too.
                $settings['openai_api_key_encrypted'] = '';
                unset( $settings['openai_api_key'] );
            }
            // If it's a new key (no asterisks, not empty), let it pass through for encryption.
        }

        // Remove the configured flag - it's metadata, not a setting.
        unset( $settings['openai_api_key_configured'] );

        // Capture old settings for audit comparison.
        $old_settings = Glimmr_AI_Settings::get_all( true );

        $result = Glimmr_AI_Settings::update( $settings );

        if ( $result ) {
            // Audit log: Track which settings changed.
            $changed_keys = array();
            foreach ( $settings as $key => $value ) {
                if ( ! isset( $old_settings[ $key ] ) || $old_settings[ $key ] !== $value ) {
                    $changed_keys[] = $key;
                }
            }
            if ( ! empty( $changed_keys ) ) {
                Glimmr_AI_Audit_Log::log_bulk_settings_change( $changed_keys );
            }

            // Get fresh settings with masked API key for response.
            $response_settings = $this->prepare_settings_for_frontend();
            wp_send_json_success( array(
                'message'  => __( 'Settings saved successfully.', 'glimmr-ai' ),
                'settings' => $response_settings,
            ) );
        } else {
            wp_send_json_error( array( 'message' => __( 'Failed to save settings to database.', 'glimmr-ai' ) ) );
        }
    }

    /**
     * Prepare settings for frontend response.
     *
     * Handles masking sensitive values like API keys.
     *
     * @since 1.0.0
     * @return array Settings array safe for frontend.
     */
    private function prepare_settings_for_frontend() {
        $settings = Glimmr_AI_Settings::get_all( true );

        // Add masked API key for display purposes.
        $api_key = Glimmr_AI_Settings::get_api_key();
        if ( ! empty( $api_key ) ) {
            // Show masked version: first 7 chars + asterisks + last 4 chars.
            $length = strlen( $api_key );
            if ( $length > 11 ) {
                $settings['openai_api_key'] = substr( $api_key, 0, 7 ) . str_repeat( '*', $length - 11 ) . substr( $api_key, -4 );
            } else {
                $settings['openai_api_key'] = str_repeat( '*', $length );
            }
            $settings['openai_api_key_configured'] = true;
        } else {
            $settings['openai_api_key'] = '';
            $settings['openai_api_key_configured'] = false;
        }

        // Remove the encrypted key from response.
        unset( $settings['openai_api_key_encrypted'] );

        // Add locked settings info for multisite.
        if ( is_multisite() ) {
            $network_settings = Glimmr_AI_Settings::get_network();
            if ( ! empty( $network_settings['locked_settings'] ) ) {
                $settings['_locked_settings'] = $network_settings['locked_settings'];
            } else {
                $settings['_locked_settings'] = array();
            }
        }

        // Add model configs with reasoning_effort info for the selected model.
        $current_model = $settings['openai_model'] ?? 'gpt-4o';
        $model_config = Glimmr_AI_OpenAI::get_model_config( $current_model );
        if ( $model_config ) {
            $settings['_model_config'] = array(
                'id'               => $model_config['id'],
                'reasoning_effort' => $model_config['reasoning_effort'] ?? array( 'supported' => false ),
                'temperature'      => $model_config['temperature'] ?? array( 'supported' => true ),
            );
        }

        // Add all model configs for the model selector to show reasoning effort availability.
        $all_model_configs = array();
        $model_ids = array(
            'gpt-5.2', 'gpt-5.1', 'gpt-5', 'gpt-5-mini', 'gpt-5-nano',
            'gpt-4.1', 'gpt-4.1-mini', 'gpt-4.1-nano',
            'gpt-4o', 'gpt-4o-mini',
            'o4-mini', 'o3-mini',
            'gpt-4-turbo', 'gpt-4',
        );
        foreach ( $model_ids as $model_id ) {
            $config = Glimmr_AI_OpenAI::get_model_config( $model_id );
            if ( $config ) {
                $all_model_configs[ $model_id ] = array(
                    'id'               => $config['id'],
                    'reasoning_effort' => $config['reasoning_effort'] ?? array( 'supported' => false ),
                    'temperature'      => $config['temperature'] ?? array( 'supported' => true ),
                );
            }
        }
        $settings['_all_model_configs'] = $all_model_configs;

        // Add license status for the License tab.
        $settings['_license'] = $this->get_license_status_for_settings();

        return $settings;
    }

    /**
     * AJAX handler for syncing products.
     *
     * @since 1.0.0
     * @return void
     */
    public function ajax_sync_products() {
        check_ajax_referer( 'glimmr_ai_admin', 'nonce' );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'glimmr-ai' ) ) );
        }

        $glimmr_ai    = Glimmr_AI::get_instance();
        $vector_store = $glimmr_ai->get_vector_store();

        if ( ! $vector_store->is_ready() ) {
            wp_send_json_error(
                array(
                    'message' => __( 'Vector store not configured. Please set API key and vector store ID in Settings.', 'glimmr-ai' ),
                )
            );
        }

        $result = $vector_store->sync_products();

        wp_send_json_success(
            array(
                'message' => sprintf(
                    /* translators: 1: number of products synced, 2: number of errors */
                    __( 'Synced %1$d products. %2$d errors.', 'glimmr-ai' ),
                    $result['synced'],
                    $result['errors']
                ),
                'synced'  => $result['synced'],
                'errors'  => $result['errors'],
            )
        );
    }

    /**
     * AJAX handler for full product re-sync to vector store.
     *
     * Unlike ajax_sync_products() which only syncs new/modified products,
     * this forces a re-sync of ALL indexed products regardless of their
     * current sync status. Use after changing vector store attributes or
     * custom attribute mappings.
     *
     * @since 1.9.0
     * @return void
     */
    public function ajax_sync_full() {
        check_ajax_referer( 'glimmr_ai_admin', 'nonce' );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'glimmr-ai' ) ) );
        }

        $glimmr_ai    = Glimmr_AI::get_instance();
        $vector_store = $glimmr_ai->get_vector_store();

        if ( ! $vector_store->is_ready() ) {
            wp_send_json_error(
                array(
                    'message' => __( 'Vector store not configured. Please set API key and vector store ID in Settings.', 'glimmr-ai' ),
                )
            );
        }

        $result = $vector_store->sync_products( true );

        wp_send_json_success(
            array(
                'message' => sprintf(
                    /* translators: 1: number of products synced, 2: number of errors */
                    __( 'Full re-sync complete. Synced %1$d products. %2$d errors.', 'glimmr-ai' ),
                    $result['synced'],
                    $result['errors']
                ),
                'synced'  => $result['synced'],
                'errors'  => $result['errors'],
            )
        );
    }

    /**
     * AJAX handler for reindexing products to local SQL index.
     *
     * This is separate from sync_products which syncs to the OpenAI Vector Store.
     * This rebuilds the local product_index table for SQL-based product search.
     *
     * @since 1.0.0
     * @return void
     */
    public function ajax_reindex_products() {
        check_ajax_referer( 'glimmr_ai_admin', 'nonce' );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'glimmr-ai' ) ) );
        }

        $glimmr_ai       = Glimmr_AI::get_instance();
        $product_indexer = $glimmr_ai->get_product_indexer();

        $result = $product_indexer->full_sync( true );

        if ( $result['success'] ) {
            $total_processed = $result['created'] + $result['updated'];
            wp_send_json_success(
                array(
                    'message' => sprintf(
                        /* translators: 1: number of products indexed, 2: created count, 3: updated count, 4: errors count */
                        __( 'Indexed %1$d products (%2$d new, %3$d updated). %4$d errors.', 'glimmr-ai' ),
                        $total_processed,
                        $result['created'],
                        $result['updated'],
                        $result['errors']
                    ),
                    'created' => $result['created'],
                    'updated' => $result['updated'],
                    'deleted' => $result['deleted'],
                    'skipped' => $result['skipped'],
                    'errors'  => $result['errors'],
                )
            );
        } else {
            wp_send_json_error(
                array(
                    'message' => __( 'Product indexing failed. Check error logs.', 'glimmr-ai' ),
                    'errors'  => $result['errors'],
                )
            );
        }
    }

    /**
     * AJAX handler for syncing knowledge.
     *
     * @since 1.0.0
     * @return void
     */
    public function ajax_sync_knowledge() {
        check_ajax_referer( 'glimmr_ai_admin', 'nonce' );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'glimmr-ai' ) ) );
        }

        $glimmr_ai    = Glimmr_AI::get_instance();
        $vector_store = $glimmr_ai->get_vector_store();

        if ( ! $vector_store->is_ready() ) {
            wp_send_json_error(
                array(
                    'message' => __( 'Vector store not configured. Please set API key and vector store ID in Settings.', 'glimmr-ai' ),
                )
            );
        }

        $result = $vector_store->sync_knowledge();

        if ( $result['success'] ) {
            wp_send_json_success(
                array(
                    'message' => sprintf(
                        /* translators: 1: number of items synced, 2: number of errors */
                        __( 'Synced %1$d items. %2$d errors.', 'glimmr-ai' ),
                        $result['synced'],
                        $result['errors']
                    ),
                    'synced'  => $result['synced'],
                    'errors'  => $result['errors'],
                )
            );
        } else {
            wp_send_json_error(
                array(
                    'message' => $result['error'] ?? __( 'Sync failed.', 'glimmr-ai' ),
                )
            );
        }
    }

    /**
     * AJAX handler for getting conversations.
     *
     * Supports network admin view with optional site filtering.
     *
     * @since 1.0.0
     * @return void
     */
    public function ajax_get_conversations() {
        check_ajax_referer( 'glimmr_ai_admin', 'nonce' );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'glimmr-ai' ) ) );
        }

        $page     = isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 1;
        $per_page = isset( $_POST['per_page'] ) ? absint( $_POST['per_page'] ) : 20;
        $status   = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '';
        $offset   = ( $page - 1 ) * $per_page;

        // Handle site_id filtering for network admins.
        $site_id = Glimmr_AI_Database::get_current_site_id();

        // Network admins can view all sites or filter by specific site.
        if ( Glimmr_AI_Database::can_view_network_data() ) {
            if ( isset( $_POST['site_id'] ) ) {
                $requested_site = sanitize_text_field( wp_unslash( $_POST['site_id'] ) );
                // 'all' means no site filtering (view all sites).
                $site_id = ( 'all' === $requested_site ) ? null : absint( $requested_site );
            }
        }

        // Build query arguments.
        $query_args = array(
            'site_id' => $site_id,
            'status'  => ! empty( $status ) ? $status : null,
            'limit'   => $per_page,
            'offset'  => $offset,
            'orderby' => 'created_at',
            'order'   => 'DESC',
        );

        // Get conversations and count.
        $conversations = Glimmr_AI_Database::get_conversations( $query_args );
        $total = Glimmr_AI_Database::count_conversations( array(
            'site_id' => $site_id,
            'status'  => ! empty( $status ) ? $status : null,
        ) );

        // S11: Audit log - track admin access to conversation list.
        if ( class_exists( 'Glimmr_AI_Audit_Log' ) ) {
            Glimmr_AI_Audit_Log::log_conversations_list( array(
                'page'    => $page,
                'status'  => $status,
                'site_id' => $site_id,
                'count'   => count( $conversations ),
            ) );
        }

        // Response data.
        $response = array(
            'conversations' => $conversations,
            'total'         => (int) $total,
            'page'          => $page,
            'per_page'      => $per_page,
            'total_pages'   => ceil( $total / $per_page ),
        );

        // Include network admin info if applicable.
        if ( Glimmr_AI_Database::can_view_network_data() ) {
            $response['is_network_admin'] = true;
            $response['sites'] = Glimmr_AI_Database::get_sites_with_conversations();
            $response['current_site_filter'] = $site_id;
        }

        wp_send_json_success( $response );
    }

    /**
     * AJAX handler for getting conversation messages.
     *
     * Retrieves all messages for a specific conversation for admin viewing.
     *
     * @since 1.9.0
     * @return void
     */
    public function ajax_get_conversation_messages() {
        check_ajax_referer( 'glimmr_ai_admin', 'nonce' );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'glimmr-ai' ) ) );
        }

        $conversation_id = isset( $_POST['conversation_id'] ) ? sanitize_text_field( wp_unslash( $_POST['conversation_id'] ) ) : '';

        if ( empty( $conversation_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Conversation ID is required.', 'glimmr-ai' ) ) );
        }

        // S11: Audit log - track admin access to conversation messages.
        if ( class_exists( 'Glimmr_AI_Audit_Log' ) ) {
            Glimmr_AI_Audit_Log::log_conversation_view( $conversation_id );
        }

        // Get messages using the conversation manager (which doesn't filter by site_id).
        // This is intentional for admin view - admins should see the full conversation.
        $glimmr_ai            = Glimmr_AI::get_instance();
        $conversation_manager = $glimmr_ai->get_conversation_manager();
        $messages             = $conversation_manager->get_messages( $conversation_id, 500 );

        // Format messages for admin display.
        $formatted_messages = array();
        foreach ( $messages as $message ) {
            $formatted = array(
                'id'          => $message['id'] ?? 0,
                'role'        => $message['role'] ?? 'unknown',
                'content'     => $message['content'] ?? '',
                'tokens_used' => $message['tokens_used'] ?? 0,
                'created_at'  => $message['created_at'] ?? '',
            );

            // Include tool_calls if present.
            if ( ! empty( $message['tool_calls'] ) ) {
                $formatted['tool_calls'] = $message['tool_calls'];
            }

            // Include tool_results if present.
            if ( ! empty( $message['tool_results'] ) ) {
                $formatted['tool_results'] = $message['tool_results'];
            }

            $formatted_messages[] = $formatted;
        }

        wp_send_json_success( $formatted_messages );
    }

    /**
     * AJAX handler for getting analytics data.
     *
     * Supports network admin view with optional site filtering.
     *
     * @since 1.0.0
     * @return void
     */
    public function ajax_get_analytics() {
        check_ajax_referer( 'glimmr_ai_admin', 'nonce' );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'glimmr-ai' ) ) );
        }

        global $wpdb;

        $period = isset( $_POST['period'] ) ? sanitize_text_field( wp_unslash( $_POST['period'] ) ) : 'week';

        // Handle site_id filtering for network admins.
        $site_id = Glimmr_AI_Database::get_current_site_id();

        if ( Glimmr_AI_Database::can_view_network_data() ) {
            if ( isset( $_POST['site_id'] ) ) {
                $requested_site = sanitize_text_field( wp_unslash( $_POST['site_id'] ) );
                $site_id = ( 'all' === $requested_site ) ? null : absint( $requested_site );
            }
        }

        // Calculate date range.
        switch ( $period ) {
            case 'day':
                $start_date = gmdate( 'Y-m-d 00:00:00' );
                break;
            case 'month':
                $start_date = gmdate( 'Y-m-d 00:00:00', strtotime( '-30 days' ) );
                break;
            case '6months':
                $start_date = gmdate( 'Y-m-d 00:00:00', strtotime( '-6 months' ) );
                break;
            case 'year':
                $start_date = gmdate( 'Y-m-d 00:00:00', strtotime( '-1 year' ) );
                break;
            case '2years':
                $start_date = gmdate( 'Y-m-d 00:00:00', strtotime( '-2 years' ) );
                break;
            case '5years':
                $start_date = gmdate( 'Y-m-d 00:00:00', strtotime( '-5 years' ) );
                break;
            case 'all':
                $start_date = '1970-01-01 00:00:00';
                break;
            case 'week':
            default:
                $start_date = gmdate( 'Y-m-d 00:00:00', strtotime( '-7 days' ) );
                break;
        }

        $conversations_table = Glimmr_AI_Database::get_table_name( 'conversations' );
        $messages_table      = Glimmr_AI_Database::get_table_name( 'messages' );
        $analytics_table     = Glimmr_AI_Database::get_table_name( 'analytics' );
        $flagged_table       = Glimmr_AI_Database::get_table_name( 'flagged_issues' );

        // Build site filter clause.
        $site_filter = '';
        $site_params = array();
        if ( null !== $site_id ) {
            $site_filter = 'AND site_id = %d';
            $site_params = array( $site_id );
        }

        // Get conversation count.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $conversation_count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$conversations_table} WHERE created_at >= %s {$site_filter}",
                array_merge( array( $start_date ), $site_params )
            )
        );

        // Get message count.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $message_count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$messages_table} WHERE created_at >= %s {$site_filter}",
                array_merge( array( $start_date ), $site_params )
            )
        );

        // Get flagged issues count.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $flagged_count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$flagged_table} WHERE status = 'new' AND created_at >= %s {$site_filter}",
                array_merge( array( $start_date ), $site_params )
            )
        );

        // Get tool usage breakdown.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $tool_usage = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                    JSON_UNQUOTE(JSON_EXTRACT(properties, '$.tool_name')) as tool_name,
                    COUNT(*) as usage_count
                FROM {$analytics_table}
                WHERE event_type = 'tool_called' AND created_at >= %s {$site_filter}
                GROUP BY tool_name
                ORDER BY usage_count DESC",
                array_merge( array( $start_date ), $site_params )
            )
        );

        // Get daily conversation counts for chart.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $daily_counts = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                    DATE(created_at) as date,
                    COUNT(*) as count
                FROM {$conversations_table}
                WHERE created_at >= %s {$site_filter}
                GROUP BY DATE(created_at)
                ORDER BY date ASC",
                array_merge( array( $start_date ), $site_params )
            )
        );

        // Get conversion stats (revenue attribution).
        $conversion_stats = Glimmr_AI_Conversion_Tracker::get_conversion_stats( $period );

        // Get daily revenue for chart.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $daily_revenue = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                    DATE(created_at) as date,
                    SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(properties, '$.order_total')) AS DECIMAL(10,2))) as revenue,
                    COUNT(*) as orders
                FROM {$analytics_table}
                WHERE event_type = 'order_completed' AND created_at >= %s {$site_filter}
                GROUP BY DATE(created_at)
                ORDER BY date ASC",
                array_merge( array( $start_date ), $site_params )
            )
        );

        // Get top converting conversations (highest revenue).
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $top_conversations = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                    a.conversation_id,
                    c.user_id,
                    c.created_at,
                    c.status,
                    SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(a.properties, '$.order_total')) AS DECIMAL(10,2))) as total_revenue,
                    COUNT(DISTINCT JSON_UNQUOTE(JSON_EXTRACT(a.properties, '$.order_id'))) as order_count,
                    (SELECT COUNT(*) FROM {$messages_table} m WHERE m.conversation_id = a.conversation_id {$site_filter}) as message_count
                FROM {$analytics_table} a
                LEFT JOIN {$conversations_table} c ON a.conversation_id = c.conversation_id
                WHERE a.event_type = 'order_completed' AND a.created_at >= %s {$site_filter}
                GROUP BY a.conversation_id
                ORDER BY total_revenue DESC
                LIMIT 5",
                array_merge( array( $start_date ), $site_params )
            )
        );

        // Build response.
        $response = array(
            'period'            => $period,
            'conversationCount' => (int) $conversation_count,
            'messageCount'      => (int) $message_count,
            'flaggedCount'      => (int) $flagged_count,
            'toolUsage'         => $tool_usage,
            'dailyCounts'       => $daily_counts,
            'conversions'       => $conversion_stats,
            'dailyRevenue'      => $daily_revenue,
            'topConversations'  => $top_conversations,
        );

        // Include network admin info if applicable.
        if ( Glimmr_AI_Database::can_view_network_data() ) {
            $response['is_network_admin'] = true;
            $response['sites'] = Glimmr_AI_Database::get_sites_with_conversations();
            $response['current_site_filter'] = $site_id;
        }

        // S11: Audit log - track admin access to analytics.
        if ( class_exists( 'Glimmr_AI_Audit_Log' ) ) {
            Glimmr_AI_Audit_Log::log_analytics_access( $period, $site_id );
        }

        wp_send_json_success( $response );
    }

    // =========================================================================
    // Knowledge Base AJAX Handlers
    // =========================================================================

    /**
     * AJAX handler for getting knowledge base data.
     *
     * @since 1.0.0
     * @return void
     */
    public function ajax_get_knowledge() {
        check_ajax_referer( 'glimmr_ai_admin', 'nonce' );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'glimmr-ai' ) ) );
        }

        global $wpdb;

        $knowledge_table = Glimmr_AI_Database::get_table_name( 'knowledge' );

        // Get pages.
        $pages_query = new WP_Query(
            array(
                'post_type'      => 'page',
                'posts_per_page' => -1,
                'post_status'    => 'publish',
                'orderby'        => 'title',
                'order'          => 'ASC',
            )
        );

        $pages = array();
        foreach ( $pages_query->posts as $page ) {
            // Check if page is in knowledge table.
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $knowledge = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$knowledge_table} WHERE source_id = %d AND type = 'page'",
                    $page->ID
                )
            );

            $pages[] = array(
                'id'             => $page->ID,
                'title'          => $page->post_title,
                'excerpt'        => wp_trim_words( $page->post_content, 20 ),
                'edit_url'       => get_edit_post_link( $page->ID ),
                'included'       => ! empty( $knowledge ),
                'sync_status'    => $knowledge->sync_status ?? 'pending',
                'last_synced_at' => $knowledge->last_synced_at ?? null,
            );
        }
        wp_reset_postdata();

        // Get posts.
        $posts_query = new WP_Query(
            array(
                'post_type'      => 'post',
                'posts_per_page' => 50,
                'post_status'    => 'publish',
                'orderby'        => 'date',
                'order'          => 'DESC',
            )
        );

        $posts = array();
        foreach ( $posts_query->posts as $post ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $knowledge = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$knowledge_table} WHERE source_id = %d AND type = 'post_type'",
                    $post->ID
                )
            );

            $posts[] = array(
                'id'             => $post->ID,
                'title'          => $post->post_title,
                'excerpt'        => wp_trim_words( $post->post_content, 20 ),
                'edit_url'       => get_edit_post_link( $post->ID ),
                'source_type'    => 'post',
                'included'       => ! empty( $knowledge ),
                'sync_status'    => $knowledge->sync_status ?? 'pending',
                'last_synced_at' => $knowledge->last_synced_at ?? null,
            );
        }
        wp_reset_postdata();

        // Get custom content.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $custom = $wpdb->get_results(
            "SELECT * FROM {$knowledge_table} WHERE type = 'custom' ORDER BY created_at DESC"
        );

        $custom_items = array();
        foreach ( $custom as $item ) {
            // S10: Escape content to prevent XSS when displayed in admin.
            $custom_items[] = array(
                'id'             => absint( $item->id ),
                'title'          => esc_html( $item->title ),
                'content'        => esc_html( $item->content ),
                'sync_status'    => esc_attr( $item->sync_status ),
                'last_synced_at' => esc_html( $item->last_synced_at ),
            );
        }

        // Get custom post types.
        $post_types     = get_post_types( array( 'public' => true, '_builtin' => false ), 'objects' );
        $post_types_arr = array();
        foreach ( $post_types as $pt ) {
            if ( 'product' !== $pt->name ) { // Exclude products (handled separately).
                $post_types_arr[] = array(
                    'name'  => $pt->name,
                    'label' => $pt->label,
                );
            }
        }

        // Get sync stats.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $stats = array(
            'total'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$knowledge_table}" ),
            'synced'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$knowledge_table} WHERE sync_status = 'synced'" ),
            'pending' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$knowledge_table} WHERE sync_status = 'pending'" ),
            'error'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$knowledge_table} WHERE sync_status = 'error'" ),
        );

        wp_send_json_success(
            array(
                'pages'      => $pages,
                'posts'      => $posts,
                'custom'     => $custom_items,
                'post_types' => $post_types_arr,
                'stats'      => $stats,
            )
        );
    }

    /**
     * AJAX handler for getting posts of a specific type.
     *
     * @since 1.0.0
     * @return void
     */
    public function ajax_get_posts() {
        check_ajax_referer( 'glimmr_ai_admin', 'nonce' );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'glimmr-ai' ) ) );
        }

        global $wpdb;

        $post_type       = isset( $_POST['post_type'] ) ? sanitize_text_field( wp_unslash( $_POST['post_type'] ) ) : 'post';
        $knowledge_table = Glimmr_AI_Database::get_table_name( 'knowledge' );

        // S4: Validate post_type against registered post types.
        $valid_post_types = get_post_types( array( 'public' => true ), 'names' );
        if ( ! in_array( $post_type, $valid_post_types, true ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid post type.', 'glimmr-ai' ) ) );
        }

        $posts_query = new WP_Query(
            array(
                'post_type'      => $post_type,
                'posts_per_page' => 100,
                'post_status'    => 'publish',
                'orderby'        => 'date',
                'order'          => 'DESC',
            )
        );

        $posts = array();
        foreach ( $posts_query->posts as $post ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $knowledge = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$knowledge_table} WHERE source_id = %d AND type = 'post_type'",
                    $post->ID
                )
            );

            $posts[] = array(
                'id'             => $post->ID,
                'title'          => $post->post_title,
                'excerpt'        => wp_trim_words( $post->post_content, 20 ),
                'edit_url'       => get_edit_post_link( $post->ID ),
                'source_type'    => $post_type,
                'included'       => ! empty( $knowledge ),
                'sync_status'    => $knowledge->sync_status ?? 'pending',
                'last_synced_at' => $knowledge->last_synced_at ?? null,
            );
        }
        wp_reset_postdata();

        wp_send_json_success( $posts );
    }

    /**
     * AJAX handler for toggling knowledge inclusion.
     *
     * @since 1.0.0
     * @return void
     */
    public function ajax_toggle_knowledge() {
        check_ajax_referer( 'glimmr_ai_admin', 'nonce' );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'glimmr-ai' ) ) );
        }

        global $wpdb;

        $type       = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';
        $source_id  = isset( $_POST['source_id'] ) ? absint( $_POST['source_id'] ) : 0;
        $included   = isset( $_POST['included'] ) && '1' === $_POST['included'];
        $table_name = Glimmr_AI_Database::get_table_name( 'knowledge' );

        if ( empty( $type ) || empty( $source_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid parameters.', 'glimmr-ai' ) ) );
        }

        $db_type = 'page' === $type ? 'page' : 'post_type';

        if ( $included ) {
            // Add to knowledge table.
            $post = get_post( $source_id );
            if ( ! $post ) {
                wp_send_json_error( array( 'message' => __( 'Post not found.', 'glimmr-ai' ) ) );
            }

            $wpdb->replace(
                $table_name,
                array(
                    'type'        => $db_type,
                    'source_id'   => $source_id,
                    'source_type' => $post->post_type,
                    'title'       => $post->post_title,
                    'content'     => wp_strip_all_tags( $post->post_content ),
                    'sync_status' => 'pending',
                    'site_id'     => get_current_blog_id(),
                ),
                array( '%s', '%d', '%s', '%s', '%s', '%s', '%d' )
            );
        } else {
            // Remove from knowledge table.
            $wpdb->delete(
                $table_name,
                array(
                    'source_id' => $source_id,
                    'type'      => $db_type,
                ),
                array( '%d', '%s' )
            );
        }

        // Get updated stats.
        $stats = array(
            'total'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" ),
            'synced'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name} WHERE sync_status = 'synced'" ),
            'pending' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name} WHERE sync_status = 'pending'" ),
            'error'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name} WHERE sync_status = 'error'" ),
        );

        wp_send_json_success( array( 'stats' => $stats ) );
    }

    /**
     * AJAX handler for bulk toggling knowledge.
     *
     * @since 1.0.0
     * @return void
     */
    public function ajax_bulk_toggle_knowledge() {
        check_ajax_referer( 'glimmr_ai_admin', 'nonce' );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'glimmr-ai' ) ) );
        }

        global $wpdb;

        $type       = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';
        $post_type  = isset( $_POST['post_type'] ) ? sanitize_text_field( wp_unslash( $_POST['post_type'] ) ) : 'post';
        $included   = isset( $_POST['included'] ) && '1' === $_POST['included'];
        $table_name = Glimmr_AI_Database::get_table_name( 'knowledge' );

        $db_type    = 'page' === $type ? 'page' : 'post_type';
        $query_type = 'page' === $type ? 'page' : $post_type;

        if ( $included ) {
            // Add all posts of this type.
            $posts = get_posts(
                array(
                    'post_type'      => $query_type,
                    'posts_per_page' => -1,
                    'post_status'    => 'publish',
                )
            );

            foreach ( $posts as $post ) {
                $wpdb->replace(
                    $table_name,
                    array(
                        'type'        => $db_type,
                        'source_id'   => $post->ID,
                        'source_type' => $post->post_type,
                        'title'       => $post->post_title,
                        'content'     => wp_strip_all_tags( $post->post_content ),
                        'sync_status' => 'pending',
                        'site_id'     => get_current_blog_id(),
                    ),
                    array( '%s', '%d', '%s', '%s', '%s', '%s', '%d' )
                );
            }
        } else {
            // Remove all of this type.
            $wpdb->delete(
                $table_name,
                array( 'type' => $db_type ),
                array( '%s' )
            );
        }

        // Get updated stats.
        $stats = array(
            'total'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" ),
            'synced'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name} WHERE sync_status = 'synced'" ),
            'pending' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name} WHERE sync_status = 'pending'" ),
            'error'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name} WHERE sync_status = 'error'" ),
        );

        wp_send_json_success( array( 'stats' => $stats ) );
    }

    /**
     * AJAX handler for syncing a single knowledge item.
     *
     * @since 1.0.0
     * @return void
     */
    public function ajax_sync_knowledge_item() {
        check_ajax_referer( 'glimmr_ai_admin', 'nonce' );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'glimmr-ai' ) ) );
        }

        $id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

        if ( empty( $id ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid ID.', 'glimmr-ai' ) ) );
        }

        // Sync the item to vector store.
        $glimmr_ai    = Glimmr_AI::get_instance();
        $vector_store = $glimmr_ai->get_vector_store();

        if ( ! $vector_store->is_ready() ) {
            wp_send_json_error(
                array(
                    'message' => __( 'Vector store not configured. Please set API key and vector store ID in Settings.', 'glimmr-ai' ),
                )
            );
        }

        $result = $vector_store->sync_single_knowledge( $id );

        if ( $result ) {
            wp_send_json_success( array( 'message' => __( 'Item synced successfully.', 'glimmr-ai' ) ) );
        } else {
            wp_send_json_error( array( 'message' => __( 'Sync failed. Check API configuration.', 'glimmr-ai' ) ) );
        }
    }

    /**
     * AJAX handler for adding custom knowledge.
     *
     * Syncs to vector store first, only saves to DB if sync succeeds.
     *
     * @since 1.0.0
     * @return void
     */
    public function ajax_add_custom_knowledge() {
        check_ajax_referer( 'glimmr_ai_admin', 'nonce' );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'glimmr-ai' ) ) );
        }

        $title   = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
        $content = isset( $_POST['content'] ) ? wp_kses_post( wp_unslash( $_POST['content'] ) ) : '';

        if ( empty( $title ) || empty( $content ) ) {
            wp_send_json_error( array( 'message' => __( 'Title and content are required.', 'glimmr-ai' ) ) );
        }

        $glimmr_ai    = Glimmr_AI::get_instance();
        $vector_store = $glimmr_ai->get_vector_store();

        // Sync to vector store first, only save to DB if sync succeeds.
        $result = $vector_store->add_knowledge_with_sync(
            array(
                'type'    => 'custom',
                'title'   => $title,
                'content' => $content,
            )
        );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error(
                array(
                    'message' => sprintf(
                        /* translators: %s: error message */
                        __( 'Failed to sync: %s', 'glimmr-ai' ),
                        $result->get_error_message()
                    ),
                )
            );
        }

        // Audit log: Knowledge base addition.
        Glimmr_AI_Audit_Log::log_knowledge_change( 'add', array(
            'title'   => $title,
            'item_id' => $result['id'],
        ) );

        wp_send_json_success(
            array(
                'message' => __( 'Content added and synced to vector store.', 'glimmr-ai' ),
                'id'      => $result['id'],
            )
        );
    }

    /**
     * AJAX handler for editing custom knowledge.
     *
     * Syncs to vector store first (remove + add), only updates DB if sync succeeds.
     *
     * @since 1.0.0
     * @return void
     */
    public function ajax_edit_custom_knowledge() {
        check_ajax_referer( 'glimmr_ai_admin', 'nonce' );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'glimmr-ai' ) ) );
        }

        $id      = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
        $title   = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
        $content = isset( $_POST['content'] ) ? wp_kses_post( wp_unslash( $_POST['content'] ) ) : '';

        if ( empty( $id ) || empty( $title ) || empty( $content ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid parameters.', 'glimmr-ai' ) ) );
        }

        $glimmr_ai    = Glimmr_AI::get_instance();
        $vector_store = $glimmr_ai->get_vector_store();

        // Sync to vector store first (handles remove + add), only update DB if sync succeeds.
        $result = $vector_store->update_knowledge_with_sync(
            $id,
            array(
                'title'   => $title,
                'content' => $content,
            )
        );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error(
                array(
                    'message' => sprintf(
                        /* translators: %s: error message */
                        __( 'Failed to sync: %s', 'glimmr-ai' ),
                        $result->get_error_message()
                    ),
                )
            );
        }

        // Audit log: Knowledge base edit.
        Glimmr_AI_Audit_Log::log_knowledge_change( 'edit', array(
            'item_id' => $id,
            'title'   => $title,
        ) );

        wp_send_json_success(
            array(
                'message' => __( 'Content updated and synced to vector store.', 'glimmr-ai' ),
            )
        );
    }

    /**
     * AJAX handler for deleting custom knowledge.
     *
     * Removes from vector store first, only deletes from DB if API succeeds.
     *
     * @since 1.0.0
     * @return void
     */
    public function ajax_delete_custom_knowledge() {
        check_ajax_referer( 'glimmr_ai_admin', 'nonce' );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'glimmr-ai' ) ) );
        }

        $id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

        if ( empty( $id ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid ID.', 'glimmr-ai' ) ) );
        }

        $glimmr_ai    = Glimmr_AI::get_instance();
        $vector_store = $glimmr_ai->get_vector_store();

        // Delete from vector store first, only delete from DB if API succeeds.
        $result = $vector_store->delete_knowledge( $id );

        if ( $result ) {
            // Audit log: Knowledge base deletion.
            Glimmr_AI_Audit_Log::log_knowledge_change( 'delete', array( 'item_id' => $id ) );
            wp_send_json_success( array( 'message' => __( 'Content deleted.', 'glimmr-ai' ) ) );
        } else {
            wp_send_json_error( array( 'message' => __( 'Failed to delete. The item may still exist in the vector store.', 'glimmr-ai' ) ) );
        }
    }

    // =========================================================================
    // Prompts & Tools AJAX Handlers
    // =========================================================================

    /**
     * AJAX handler for getting prompts and tools configuration.
     *
     * @since 1.0.0
     * @return void
     */
    public function ajax_get_prompts_tools() {
        check_ajax_referer( 'glimmr_ai_admin', 'nonce' );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'glimmr-ai' ) ) );
        }

        $settings = Glimmr_AI_Settings::get_all();

        // Get context class for default values.
        $context = new Glimmr_AI_Context( new Glimmr_AI_Settings() );

        wp_send_json_success(
            array(
                'system_prompt'      => $settings['system_prompt'] ?? $context->get_default_system_prompt(),
                'agent_guardrails'   => $settings['agent_guardrails'] ?? $context->get_default_guardrails(),
                'default_guardrails' => $context->get_default_guardrails(),
                'enabled_tools'      => $settings['enabled_tools'] ?? array(),
                'tool_settings'      => array(
                    'coupon_visibility' => $settings['coupon_visibility'] ?? 'public',
                    'visible_coupons'   => $settings['visible_coupons'] ?? array(),
                ),
            )
        );
    }

    /**
     * AJAX handler for saving prompts and tools configuration.
     *
     * @since 1.0.0
     * @return void
     */
    public function ajax_save_prompts_tools() {
        check_ajax_referer( 'glimmr_ai_admin', 'nonce' );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'glimmr-ai' ) ) );
        }

        $system_prompt    = isset( $_POST['system_prompt'] ) ? wp_kses_post( wp_unslash( $_POST['system_prompt'] ) ) : '';
        $agent_guardrails = isset( $_POST['agent_guardrails'] ) ? wp_kses_post( wp_unslash( $_POST['agent_guardrails'] ) ) : '';

        // Decode and validate JSON inputs with proper error handling.
        $enabled_tools = array();
        if ( isset( $_POST['enabled_tools'] ) ) {
            $decoded = json_decode( wp_unslash( $_POST['enabled_tools'] ), true );
            if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
                $enabled_tools = $decoded;
            }
        }

        $tool_settings = array();
        if ( isset( $_POST['tool_settings'] ) ) {
            $decoded = json_decode( wp_unslash( $_POST['tool_settings'] ), true );
            if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
                $tool_settings = $decoded;
            }
        }

        // Validate and sanitize enabled_tools (already validated as array above).
        if ( ! is_array( $enabled_tools ) ) {
            $enabled_tools = array();
        }

        $sanitized_tools = array();
        foreach ( $enabled_tools as $tool => $enabled ) {
            $sanitized_tools[ sanitize_key( $tool ) ] = (bool) $enabled;
        }

        // Capture old settings for audit comparison.
        $old_settings = Glimmr_AI_Settings::get_all( true );

        // Save settings.
        $update_data = array(
            'system_prompt'    => $system_prompt,
            'agent_guardrails' => $agent_guardrails,
            'enabled_tools'    => $sanitized_tools,
        );

        // Add tool-specific settings.
        if ( is_array( $tool_settings ) ) {
            if ( isset( $tool_settings['coupon_visibility'] ) ) {
                $update_data['coupon_visibility'] = sanitize_text_field( $tool_settings['coupon_visibility'] );
            }
            if ( isset( $tool_settings['visible_coupons'] ) && is_array( $tool_settings['visible_coupons'] ) ) {
                $update_data['visible_coupons'] = array_map( 'sanitize_text_field', $tool_settings['visible_coupons'] );
            }
        }

        $result = Glimmr_AI_Settings::update( $update_data );

        if ( $result ) {
            // Audit log: Track prompt and tool configuration changes.
            $prompt_changed = ( $old_settings['system_prompt'] ?? '' ) !== $system_prompt;
            if ( $prompt_changed ) {
                Glimmr_AI_Audit_Log::log_prompt_change( true );
            }

            // Detect tool enable/disable changes.
            $old_tools    = $old_settings['enabled_tools'] ?? array();
            $tool_changes = array();
            foreach ( $sanitized_tools as $tool => $enabled ) {
                $was_enabled = $old_tools[ $tool ] ?? true;
                if ( (bool) $was_enabled !== $enabled ) {
                    $tool_changes[ $tool ] = $enabled;
                }
            }
            if ( ! empty( $tool_changes ) ) {
                Glimmr_AI_Audit_Log::log_tools_change( $tool_changes );
            }

            wp_send_json_success( array( 'message' => __( 'Configuration saved successfully.', 'glimmr-ai' ) ) );
        } else {
            wp_send_json_error( array( 'message' => __( 'Failed to save configuration.', 'glimmr-ai' ) ) );
        }
    }

    // =========================================================================
    // Logging AJAX Handlers
    // =========================================================================

    /**
     * AJAX handler for getting recent log entries.
     *
     * @since 1.0.0
     * @return void
     */
    public function ajax_get_logs() {
        check_ajax_referer( 'glimmr_ai_admin', 'nonce' );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'glimmr-ai' ) ) );
        }

        $lines = isset( $_POST['lines'] ) ? absint( $_POST['lines'] ) : 100;
        $lines = min( $lines, 500 ); // Cap at 500 lines.

        $log_file = Glimmr_AI_Logger::get_current_log_file();
        $log_dir  = Glimmr_AI_Logger::get_log_directory();

        $logs = array(
            'entries'    => array(),
            'file_size'  => 0,
            'file_name'  => '',
            'file_date'  => '',
            'log_level'  => Glimmr_AI_Logger::get_log_level(),
            'log_dir'    => $log_dir ? basename( $log_dir ) : '',
        );

        if ( $log_file && file_exists( $log_file ) ) {
            $logs['file_size'] = filesize( $log_file );
            $logs['file_name'] = basename( $log_file );
            $logs['file_date'] = gmdate( 'Y-m-d H:i:s', filemtime( $log_file ) );

            // Read last N lines efficiently.
            $logs['entries'] = $this->tail_log_file( $log_file, $lines );
        }

        wp_send_json_success( $logs );
    }

    /**
     * Read the last N lines of a log file efficiently.
     *
     * @param string $file  Path to log file.
     * @param int    $lines Number of lines to read.
     * @return array Array of log entries.
     */
    private function tail_log_file( $file, $lines ) {
        $result = array();

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $handle = fopen( $file, 'r' );
        if ( ! $handle ) {
            return $result;
        }

        // Get file size.
        fseek( $handle, 0, SEEK_END );
        $file_size = ftell( $handle );

        // Start from end and work backwards.
        $chunk_size = 4096;
        $position   = $file_size;
        $buffer     = '';
        $line_count = 0;

        while ( $position > 0 && $line_count < $lines ) {
            $read_size = min( $chunk_size, $position );
            $position  -= $read_size;

            fseek( $handle, $position );
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
            $chunk  = fread( $handle, $read_size );
            $buffer = $chunk . $buffer;

            // Count newlines in buffer.
            $line_count = substr_count( $buffer, "\n" );
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose( $handle );

        // Parse buffer into lines.
        $all_lines = explode( "\n", $buffer );

        // Take last N lines.
        $all_lines = array_filter( $all_lines );
        $all_lines = array_slice( $all_lines, -$lines );

        // Parse each line into structured data.
        foreach ( $all_lines as $line ) {
            $entry = $this->parse_log_line( $line );
            if ( $entry ) {
                $result[] = $entry;
            }
        }

        return $result;
    }

    /**
     * Parse a log line into structured data.
     *
     * @param string $line Raw log line.
     * @return array|null Parsed entry or null.
     */
    private function parse_log_line( $line ) {
        $line = trim( $line );
        if ( empty( $line ) ) {
            return null;
        }

        // Expected format: [2025-01-15 10:30:45] [INFO] [context] Message
        $pattern = '/^\[([^\]]+)\]\s*\[([^\]]+)\]\s*(?:\[([^\]]*)\])?\s*(.*)$/';

        if ( preg_match( $pattern, $line, $matches ) ) {
            return array(
                'timestamp' => $matches[1],
                'level'     => strtolower( $matches[2] ),
                'context'   => $matches[3] ?? '',
                'message'   => $matches[4],
            );
        }

        // Fallback for non-standard lines.
        return array(
            'timestamp' => '',
            'level'     => 'info',
            'context'   => '',
            'message'   => $line,
        );
    }

    /**
     * AJAX handler for downloading the log file.
     *
     * @since 1.0.0
     * @return void
     */
    public function ajax_download_logs() {
        check_ajax_referer( 'glimmr_ai_admin', 'nonce' );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_die( 'Permission denied.' );
        }

        $log_file = Glimmr_AI_Logger::get_current_log_file();

        if ( ! $log_file || ! file_exists( $log_file ) ) {
            wp_die( 'Log file not found.' );
        }

        $filename = basename( $log_file );

        // Set headers for download.
        header( 'Content-Type: text/plain' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Content-Length: ' . filesize( $log_file ) );
        header( 'Cache-Control: no-cache, must-revalidate' );

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
        readfile( $log_file );
        exit;
    }

    /**
     * AJAX handler for clearing the log file.
     *
     * @since 1.0.0
     * @return void
     */
    public function ajax_clear_logs() {
        check_ajax_referer( 'glimmr_ai_admin', 'nonce' );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'glimmr-ai' ) ) );
        }

        $log_file = Glimmr_AI_Logger::get_current_log_file();

        if ( $log_file && file_exists( $log_file ) ) {
            // Truncate the file.
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            file_put_contents( $log_file, '' );

            // Add a note that logs were cleared.
            Glimmr_AI_Logger::info( 'Logs cleared by admin user.', array(), 'admin' );

            wp_send_json_success( array( 'message' => __( 'Logs cleared successfully.', 'glimmr-ai' ) ) );
        } else {
            wp_send_json_success( array( 'message' => __( 'No log file to clear.', 'glimmr-ai' ) ) );
        }
    }

    /**
     * AJAX handler for purging all conversation history.
     *
     * Deletes all conversations, messages, analytics, and flagged issues.
     * Used for testing/development cleanup.
     *
     * @since 1.0.0
     * @return void
     */
    public function ajax_purge_conversation_history() {
        check_ajax_referer( 'glimmr_ai_admin', 'nonce' );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'glimmr-ai' ) ) );
        }

        global $wpdb;

        $tables = array(
            'conversations',
            'messages',
            'analytics',
            'flagged_issues',
            'rate_limits',
        );

        $deleted_counts = array();

        foreach ( $tables as $table ) {
            $table_name = Glimmr_AI_Database::get_table_name( $table );

            // Get count before deletion.
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" );

            // Truncate the table.
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query( "TRUNCATE TABLE {$table_name}" );

            $deleted_counts[ $table ] = $count;
        }

        $total_deleted = array_sum( $deleted_counts );

        Glimmr_AI_Logger::info(
            'Conversation history purged by admin.',
            array( 'deleted_counts' => $deleted_counts ),
            'admin'
        );

        wp_send_json_success(
            array(
                'message' => sprintf(
                    /* translators: %d: number of records deleted */
                    __( 'Successfully purged %d records from conversation history.', 'glimmr-ai' ),
                    $total_deleted
                ),
                'deleted_counts' => $deleted_counts,
            )
        );
    }

    // =========================================================================
    // Product Sync AJAX Handlers (with Progress Tracking)
    // =========================================================================

    /**
     * AJAX handler for getting product sync status.
     *
     * Returns counts of total, synced, pending, and failed products,
     * plus last sync timestamp and vector store readiness.
     *
     * @since 1.0.0
     * @return void
     */
    public function ajax_get_product_sync_status() {
        check_ajax_referer( 'glimmr_ai_admin', 'nonce' );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'glimmr-ai' ) ) );
        }

        global $wpdb;

        // Get WooCommerce product count.
        $total_products = 0;
        if ( class_exists( 'WooCommerce' ) ) {
            $total_products = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->posts}
                 WHERE post_type = 'product'
                 AND post_status = 'publish'"
            );
        }

        // Get product index stats.
        $product_index_table = Glimmr_AI_Database::get_table_name( 'product_index' );
        $sync_log_table      = Glimmr_AI_Database::get_table_name( 'sync_log' );

        // Count synced products (those with vector_file_id).
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $synced_count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$product_index_table} WHERE vector_file_id IS NOT NULL AND vector_file_id != ''"
        );

        // Count indexed products (in product_index table).
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $indexed_count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$product_index_table}"
        );

        // Pending = indexed but not synced to vector store.
        $pending_count = $indexed_count - $synced_count;

        // Get last sync info from sync_log.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $last_sync = $wpdb->get_row(
            "SELECT * FROM {$sync_log_table}
             WHERE sync_type = 'products'
             ORDER BY started_at DESC
             LIMIT 1"
        );

        // Check if vector store is ready.
        $glimmr_ai    = Glimmr_AI::get_instance();
        $vector_store = $glimmr_ai->get_vector_store();
        $is_ready     = $vector_store->is_ready();

        // Check if sync is currently running.
        $is_syncing = (bool) get_transient( 'glimmr_ai_product_sync_running' );

        // Get any sync errors from the last sync.
        $errors = array();
        if ( $last_sync && 'error' === $last_sync->status ) {
            $errors[] = $last_sync->error_message ?? __( 'Unknown error occurred during sync.', 'glimmr-ai' );
        }

        // Get stored sync errors from transient.
        $stored_errors = get_transient( 'glimmr_ai_product_sync_errors' );
        if ( is_array( $stored_errors ) ) {
            $errors = array_merge( $errors, $stored_errors );
        }

        // Calculate last sync duration if available.
        $last_sync_duration = null;
        if ( $last_sync && $last_sync->started_at && $last_sync->completed_at ) {
            $start = strtotime( $last_sync->started_at );
            $end   = strtotime( $last_sync->completed_at );
            if ( $start && $end ) {
                $last_sync_duration = $end - $start;
            }
        }

        wp_send_json_success(
            array(
                'total_products'     => $total_products,
                'indexed_products'   => $indexed_count,
                'synced_products'    => $synced_count,
                'pending_products'   => max( 0, $pending_count ),
                'failed_products'    => count( $errors ),
                'last_sync'          => $last_sync ? $last_sync->completed_at : null,
                'last_sync_status'   => $last_sync ? $last_sync->status : null,
                'last_sync_duration' => $last_sync_duration,
                'vector_store_id'    => Glimmr_AI_Settings::get( 'openai_vector_store_id' ),
                'sync_enabled'       => (bool) Glimmr_AI_Settings::get( 'product_sync_enabled', false ),
                'is_syncing'         => $is_syncing,
                'is_ready'           => $is_ready,
                'errors'             => $errors,
            )
        );
    }

    /**
     * AJAX handler for getting current sync progress.
     *
     * Used for polling during active sync operations.
     *
     * @since 1.0.0
     * @return void
     */
    public function ajax_get_product_sync_progress() {
        check_ajax_referer( 'glimmr_ai_admin', 'nonce' );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'glimmr-ai' ) ) );
        }

        // Get progress from transient.
        $progress = get_transient( 'glimmr_ai_product_sync_progress' );
        $running  = (bool) get_transient( 'glimmr_ai_product_sync_running' );

        // Get stored errors.
        $stored_errors = get_transient( 'glimmr_ai_product_sync_errors' );

        if ( ! $progress ) {
            $response = array(
                'total'      => 0,
                'current'    => 0,
                'status'     => 'idle',
                'message'    => '',
                'errors'     => is_array( $stored_errors ) ? $stored_errors : array(),
                'started_at' => null,
                'running'    => $running,
            );
        } else {
            $response = array(
                'total'      => $progress['total'] ?? 0,
                'current'    => $progress['processed'] ?? 0,
                'status'     => $progress['status'] ?? 'idle',
                'message'    => $progress['message'] ?? '',
                'errors'     => is_array( $stored_errors ) ? $stored_errors : array(),
                'started_at' => $progress['startedAt'] ?? null,
                'running'    => $running,
            );
        }

        wp_send_json_success( $response );
    }

    /**
     * AJAX handler for starting batch product sync.
     *
     * Syncs products to the OpenAI vector store with progress tracking.
     *
     * @since 1.0.0
     * @return void
     */
    public function ajax_sync_products_batch() {
        check_ajax_referer( 'glimmr_ai_admin', 'nonce' );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'glimmr-ai' ) ) );
        }

        // Check if already syncing.
        if ( get_transient( 'glimmr_ai_product_sync_running' ) ) {
            wp_send_json_error( array( 'message' => __( 'A sync is already in progress.', 'glimmr-ai' ) ) );
        }

        $glimmr_ai    = Glimmr_AI::get_instance();
        $vector_store = $glimmr_ai->get_vector_store();

        if ( ! $vector_store->is_ready() ) {
            wp_send_json_error(
                array(
                    'message' => __( 'Vector store not configured. Please set API key in Settings.', 'glimmr-ai' ),
                )
            );
        }

        // Get sync mode: 'incremental' or 'full'.
        $mode = isset( $_POST['mode'] ) ? sanitize_text_field( wp_unslash( $_POST['mode'] ) ) : 'incremental';

        // Set running flag (expires in 30 minutes to prevent stale locks).
        set_transient( 'glimmr_ai_product_sync_running', true, 30 * MINUTE_IN_SECONDS );

        // Clear any existing errors.
        delete_transient( 'glimmr_ai_product_sync_errors' );

        // Initialize progress.
        $progress = array(
            'total'     => 0,
            'processed' => 0,
            'errors'    => 0,
            'status'    => 'starting',
            'message'   => __( 'Preparing to sync products...', 'glimmr-ai' ),
            'startedAt' => current_time( 'mysql' ),
        );
        set_transient( 'glimmr_ai_product_sync_progress', $progress, 30 * MINUTE_IN_SECONDS );

        // Get products to sync.
        global $wpdb;
        $product_index_table = Glimmr_AI_Database::get_table_name( 'product_index' );

        if ( 'full' === $mode ) {
            // Full re-sync: all products.
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $products = $wpdb->get_results(
                "SELECT product_id, name FROM {$product_index_table} ORDER BY product_id ASC"
            );
        } else {
            // Incremental: only products without vector_file_id or recently updated.
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $products = $wpdb->get_results(
                "SELECT product_id, name FROM {$product_index_table}
                 WHERE vector_file_id IS NULL OR vector_file_id = ''
                 ORDER BY product_id ASC"
            );
        }

        $total      = count( $products );
        $processed  = 0;
        $errors     = 0;
        $error_list = array();
        $batch_size = (int) Glimmr_AI_Settings::get( 'product_sync_batch_size', 50 );

        // Update progress with total.
        $progress['total']   = $total;
        $progress['status']  = 'syncing';
        $progress['message'] = sprintf(
            /* translators: %d: total products */
            __( 'Starting sync of %d products...', 'glimmr-ai' ),
            $total
        );
        set_transient( 'glimmr_ai_product_sync_progress', $progress, 30 * MINUTE_IN_SECONDS );

        // Process in batches.
        $batches = array_chunk( $products, $batch_size );

        foreach ( $batches as $batch_index => $batch ) {
            // Check for cancellation.
            if ( get_transient( 'glimmr_ai_product_sync_cancel' ) ) {
                delete_transient( 'glimmr_ai_product_sync_cancel' );

                $progress['status']  = 'cancelled';
                $progress['message'] = __( 'Sync cancelled by user.', 'glimmr-ai' );
                set_transient( 'glimmr_ai_product_sync_progress', $progress, 30 * MINUTE_IN_SECONDS );

                delete_transient( 'glimmr_ai_product_sync_running' );

                wp_send_json_success(
                    array(
                        'message'   => __( 'Sync cancelled.', 'glimmr-ai' ),
                        'processed' => $processed,
                        'errors'    => $errors,
                        'cancelled' => true,
                    )
                );
                return;
            }

            // Process batch.
            $product_ids = wp_list_pluck( $batch, 'product_id' );

            try {
                $result = $vector_store->sync_products_batch( $product_ids );

                if ( isset( $result['synced'] ) ) {
                    $processed += $result['synced'];
                }
                if ( isset( $result['errors'] ) && $result['errors'] > 0 ) {
                    $errors += $result['errors'];
                    if ( isset( $result['error_messages'] ) ) {
                        $error_list = array_merge( $error_list, $result['error_messages'] );
                    }
                }
            } catch ( \Throwable $e ) {
                $errors++;
                $error_list[] = sprintf(
                    /* translators: 1: batch number, 2: error message */
                    __( 'Batch %1$d error: %2$s', 'glimmr-ai' ),
                    $batch_index + 1,
                    $e->getMessage()
                );
                Glimmr_AI_Logger::error( 'Product sync batch error: ' . $e->getMessage(), array(), 'sync' );
            }

            // Update progress.
            $progress['processed'] = $processed;
            $progress['errors']    = $errors;
            $progress['message']   = sprintf(
                /* translators: 1: processed count, 2: total count */
                __( 'Synced %1$d of %2$d products...', 'glimmr-ai' ),
                $processed,
                $total
            );
            set_transient( 'glimmr_ai_product_sync_progress', $progress, 30 * MINUTE_IN_SECONDS );

            // Small delay to prevent rate limiting.
            usleep( 100000 ); // 100ms
        }

        // Complete.
        delete_transient( 'glimmr_ai_product_sync_running' );

        // Store errors if any.
        if ( ! empty( $error_list ) ) {
            set_transient( 'glimmr_ai_product_sync_errors', array_slice( $error_list, 0, 50 ), HOUR_IN_SECONDS );
        }

        // Final progress update.
        $progress['status']      = 'complete';
        $progress['message']     = sprintf(
            /* translators: 1: synced count, 2: error count */
            __( 'Sync complete. Synced %1$d products. %2$d errors.', 'glimmr-ai' ),
            $processed,
            $errors
        );
        $progress['completedAt'] = current_time( 'mysql' );
        set_transient( 'glimmr_ai_product_sync_progress', $progress, 30 * MINUTE_IN_SECONDS );

        // Log sync to sync_log table.
        $sync_log_table = Glimmr_AI_Database::get_table_name( 'sync_log' );
        $wpdb->insert(
            $sync_log_table,
            array(
                'sync_type'       => 'products',
                'status'          => $errors > 0 ? 'partial' : 'success',
                'items_processed' => $processed,
                'items_total'     => $total,
                'started_at'      => $progress['startedAt'],
                'completed_at'    => $progress['completedAt'],
                'error_details'   => $errors > 0 ? wp_json_encode( array_slice( $error_list, 0, 10 ) ) : null,
                'triggered_by'    => 'ajax',
                'site_id'         => get_current_blog_id(),
            ),
            array( '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%d' )
        );

        wp_send_json_success(
            array(
                'message'   => $progress['message'],
                'synced'    => $processed,
                'errors'    => $errors,
                'total'     => $total,
            )
        );
    }

    /**
     * AJAX handler for cancelling a running product sync.
     *
     * @since 1.0.0
     * @return void
     */
    public function ajax_cancel_product_sync() {
        check_ajax_referer( 'glimmr_ai_admin', 'nonce' );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'glimmr-ai' ) ) );
        }

        // Check if sync is running.
        if ( ! get_transient( 'glimmr_ai_product_sync_running' ) ) {
            wp_send_json_error( array( 'message' => __( 'No sync is currently running.', 'glimmr-ai' ) ) );
        }

        // Set cancellation flag.
        set_transient( 'glimmr_ai_product_sync_cancel', true, 5 * MINUTE_IN_SECONDS );

        wp_send_json_success( array( 'message' => __( 'Cancel signal sent. Sync will stop after current batch.', 'glimmr-ai' ) ) );
    }

    /**
     * AJAX handler for clearing product sync errors.
     *
     * @since 1.0.0
     * @return void
     */
    public function ajax_clear_product_sync_errors() {
        check_ajax_referer( 'glimmr_ai_admin', 'nonce' );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'glimmr-ai' ) ) );
        }

        // Clear error transient.
        delete_transient( 'glimmr_ai_product_sync_errors' );

        // Also clear any error status from progress.
        $progress = get_transient( 'glimmr_ai_product_sync_progress' );
        if ( $progress && is_array( $progress ) ) {
            $progress['errors'] = 0;
            set_transient( 'glimmr_ai_product_sync_progress', $progress, 30 * MINUTE_IN_SECONDS );
        }

        wp_send_json_success( array( 'message' => __( 'Errors cleared.', 'glimmr-ai' ) ) );
    }

    /**
     * AJAX handler: Purge all products from vector store.
     *
     * Deletes all product files from the OpenAI vector store and clears
     * vector_file_id from the product index. This is a destructive operation.
     *
     * @since 1.0.0
     * @return void
     */
    public function ajax_purge_products() {
        check_ajax_referer( 'glimmr_ai_admin', 'nonce' );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'glimmr-ai' ) ) );
        }

        $glimmr_ai    = Glimmr_AI::get_instance();
        $vector_store = $glimmr_ai->get_vector_store();

        if ( ! $vector_store->is_ready() ) {
            wp_send_json_error(
                array(
                    'message' => __( 'Vector store not configured.', 'glimmr-ai' ),
                )
            );
        }

        $result = $vector_store->purge_all_products();

        if ( ! $result['success'] ) {
            wp_send_json_error(
                array(
                    'message' => $result['error'] ?? __( 'Failed to purge products.', 'glimmr-ai' ),
                )
            );
        }

        wp_send_json_success(
            array(
                'message' => sprintf(
                    /* translators: 1: number of files deleted, 2: number of errors */
                    __( 'Purged %1$d product files from vector store. %2$d errors.', 'glimmr-ai' ),
                    $result['deleted'],
                    $result['errors']
                ),
                'deleted' => $result['deleted'],
                'errors'  => $result['errors'],
            )
        );
    }

    /**
     * AJAX handler: Purge everything from vector store (products + knowledge).
     *
     * @since 1.0.0
     * @return void
     */
    public function ajax_purge_everything() {
        check_ajax_referer( 'glimmr_ai_admin', 'nonce' );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'glimmr-ai' ) ) );
        }

        $glimmr_ai    = Glimmr_AI::get_instance();
        $vector_store = $glimmr_ai->get_vector_store();

        if ( ! $vector_store->is_ready() ) {
            wp_send_json_error(
                array(
                    'message' => __( 'Vector store not configured.', 'glimmr-ai' ),
                )
            );
        }

        $result = $vector_store->purge_everything();

        if ( ! $result['success'] ) {
            wp_send_json_error(
                array(
                    'message' => __( 'Failed to purge vector store.', 'glimmr-ai' ),
                )
            );
        }

        wp_send_json_success(
            array(
                'message' => sprintf(
                    /* translators: 1: products deleted, 2: knowledge deleted, 3: total errors */
                    __( 'Purged %1$d products and %2$d knowledge items. %3$d errors.', 'glimmr-ai' ),
                    $result['products_deleted'],
                    $result['knowledge_deleted'],
                    $result['total_errors']
                ),
                'products_deleted'  => $result['products_deleted'],
                'knowledge_deleted' => $result['knowledge_deleted'],
                'total_deleted'     => $result['total_deleted'],
                'total_errors'      => $result['total_errors'],
            )
        );
    }

    /**
     * AJAX handler: Purge vector store directly via API.
     *
     * This bypasses the database and deletes all files from OpenAI directly.
     * Use when database is out of sync with vector store.
     *
     * @since 1.0.0
     * @return void
     */
    public function ajax_purge_vector_store_direct() {
        check_ajax_referer( 'glimmr_ai_admin', 'nonce' );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'glimmr-ai' ) ) );
        }

        $glimmr_ai    = Glimmr_AI::get_instance();
        $vector_store = $glimmr_ai->get_vector_store();

        if ( ! $vector_store->is_ready() ) {
            wp_send_json_error(
                array(
                    'message' => __( 'Vector store not configured.', 'glimmr-ai' ),
                )
            );
        }

        $result = $vector_store->purge_vector_store_direct();

        if ( ! $result['success'] ) {
            wp_send_json_error(
                array(
                    'message' => $result['error'] ?? __( 'Failed to purge vector store.', 'glimmr-ai' ),
                )
            );
        }

        wp_send_json_success(
            array(
                'message' => sprintf(
                    /* translators: 1: files deleted, 2: errors */
                    __( 'Direct purge complete. Deleted %1$d files from vector store. %2$d errors.', 'glimmr-ai' ),
                    $result['deleted'],
                    $result['errors']
                ),
                'deleted' => $result['deleted'],
                'errors'  => $result['errors'],
                'details' => $result['details'] ?? array(),
            )
        );
    }

    /**
     * AJAX handler: Sync everything (knowledge + products).
     *
     * @since 1.0.0
     * @return void
     */
    public function ajax_sync_everything() {
        check_ajax_referer( 'glimmr_ai_admin', 'nonce' );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'glimmr-ai' ) ) );
        }

        $glimmr_ai    = Glimmr_AI::get_instance();
        $vector_store = $glimmr_ai->get_vector_store();

        if ( ! $vector_store->is_ready() ) {
            wp_send_json_error(
                array(
                    'message' => __( 'Vector store not configured. Please set API key in Settings.', 'glimmr-ai' ),
                )
            );
        }

        // Sync knowledge (pages, posts, custom content).
        $knowledge_result = $vector_store->sync_knowledge();

        // Sync products.
        $product_result = $vector_store->sync_products();

        wp_send_json_success(
            array(
                'message' => sprintf(
                    /* translators: 1: knowledge synced, 2: products synced */
                    __( 'Synced %1$d knowledge items and %2$d products.', 'glimmr-ai' ),
                    $knowledge_result['synced'] ?? 0,
                    $product_result['synced'] ?? 0
                ),
                'knowledge_synced' => $knowledge_result['synced'] ?? 0,
                'knowledge_errors' => $knowledge_result['errors'] ?? 0,
                'products_synced'  => $product_result['synced'] ?? 0,
                'products_errors'  => $product_result['errors'] ?? 0,
            )
        );
    }

    // =========================================================================
    // Conversation Export AJAX Handlers (v1.6.0)
    // =========================================================================

    /**
     * AJAX handler for exporting conversations as CSV.
     *
     * @since 1.6.0
     * @return void
     */
    public function ajax_export_conversations() {
        check_ajax_referer( 'glimmr_ai_admin', 'nonce' );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'glimmr-ai' ) ) );
        }

        $format = isset( $_POST['format'] ) ? sanitize_text_field( wp_unslash( $_POST['format'] ) ) : 'csv';
        $period = isset( $_POST['period'] ) ? sanitize_text_field( wp_unslash( $_POST['period'] ) ) : 'week';
        $conversation_id = isset( $_POST['conversation_id'] ) ? sanitize_text_field( wp_unslash( $_POST['conversation_id'] ) ) : '';

        // Calculate date filter.
        switch ( $period ) {
            case 'day':
                $start_date = gmdate( 'Y-m-d 00:00:00' );
                break;
            case 'week':
                $start_date = gmdate( 'Y-m-d 00:00:00', strtotime( '-7 days' ) );
                break;
            case 'month':
                $start_date = gmdate( 'Y-m-d 00:00:00', strtotime( '-30 days' ) );
                break;
            case 'all':
            default:
                $start_date = null;
                break;
        }

        global $wpdb;

        // Get site filter.
        $site_id = Glimmr_AI_Database::get_current_site_id();

        // Build query.
        $conversations_table = Glimmr_AI_Database::get_table_name( 'conversations' );
        $messages_table = Glimmr_AI_Database::get_table_name( 'messages' );

        if ( ! empty( $conversation_id ) ) {
            // Export single conversation.
            $query = "SELECT m.*, c.user_id, c.session_id, c.created_at as conversation_started
                     FROM {$messages_table} m
                     JOIN {$conversations_table} c ON m.conversation_id = c.conversation_id
                     WHERE m.conversation_id = %s";
            $params = array( $conversation_id );

            if ( $site_id ) {
                $query .= ' AND c.site_id = %d';
                $params[] = $site_id;
            }

            $query .= ' ORDER BY m.created_at ASC';

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $messages = $wpdb->get_results( $wpdb->prepare( $query, $params ), ARRAY_A );
        } else {
            // Export all conversations in period.
            $query = "SELECT m.*, c.user_id, c.session_id, c.created_at as conversation_started
                     FROM {$messages_table} m
                     JOIN {$conversations_table} c ON m.conversation_id = c.conversation_id
                     WHERE 1=1";
            $params = array();

            if ( $start_date ) {
                $query .= ' AND c.created_at >= %s';
                $params[] = $start_date;
            }

            if ( $site_id ) {
                $query .= ' AND c.site_id = %d';
                $params[] = $site_id;
            }

            $query .= ' ORDER BY m.conversation_id, m.created_at ASC';

            if ( ! empty( $params ) ) {
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $messages = $wpdb->get_results( $wpdb->prepare( $query, $params ), ARRAY_A );
            } else {
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $messages = $wpdb->get_results( $query, ARRAY_A );
            }
        }

        if ( empty( $messages ) ) {
            wp_send_json_error( array( 'message' => __( 'No conversations found for export.', 'glimmr-ai' ) ) );
        }

        // Log export for audit.
        if ( class_exists( 'Glimmr_AI_Audit_Log' ) ) {
            Glimmr_AI_Audit_Log::log_data_export( $format, array(
                'period'          => $period,
                'conversation_id' => $conversation_id,
                'message_count'   => count( $messages ),
            ) );
        }

        // Generate CSV.
        if ( 'csv' === $format ) {
            $csv_data = $this->generate_csv_export( $messages );
            wp_send_json_success( array(
                'data'     => $csv_data,
                'filename' => 'glimmr-ai-conversations-' . gmdate( 'Y-m-d' ) . '.csv',
                'format'   => 'csv',
            ) );
        }

        // Generate JSON (alternate format).
        if ( 'json' === $format ) {
            wp_send_json_success( array(
                'data'     => $messages,
                'filename' => 'glimmr-ai-conversations-' . gmdate( 'Y-m-d' ) . '.json',
                'format'   => 'json',
            ) );
        }

        wp_send_json_error( array( 'message' => __( 'Invalid export format.', 'glimmr-ai' ) ) );
    }

    /**
     * Generate CSV export from messages.
     *
     * @param array $messages Messages array.
     * @return string CSV content.
     */
    private function generate_csv_export( $messages ) {
        $output = fopen( 'php://temp', 'r+' );

        // CSV headers.
        fputcsv( $output, array(
            'Conversation ID',
            'Message ID',
            'Timestamp',
            'Role',
            'Content',
            'Tokens Used',
            'User ID',
            'Session ID',
            'Conversation Started',
        ) );

        // CSV rows.
        foreach ( $messages as $message ) {
            // Clean content - remove HTML and limit length.
            $content = wp_strip_all_tags( $message['content'] ?? '' );
            $content = preg_replace( '/\s+/', ' ', $content );
            $content = mb_substr( $content, 0, 5000 );

            fputcsv( $output, array(
                $message['conversation_id'] ?? '',
                $message['id'] ?? '',
                $message['created_at'] ?? '',
                $message['role'] ?? '',
                $content,
                $message['tokens_used'] ?? 0,
                $message['user_id'] ?? '',
                $message['session_id'] ?? '',
                $message['conversation_started'] ?? '',
            ) );
        }

        rewind( $output );
        $csv = stream_get_contents( $output );
        fclose( $output );

        return $csv;
    }

    // =========================================================================
    // Response Time Analytics AJAX Handler (v1.6.0)
    // =========================================================================

    /**
     * AJAX handler for getting response time analytics.
     *
     * @since 1.6.0
     * @return void
     */
    public function ajax_get_response_time_analytics() {
        check_ajax_referer( 'glimmr_ai_admin', 'nonce' );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'glimmr-ai' ) ) );
        }

        global $wpdb;

        $period = isset( $_POST['period'] ) ? sanitize_text_field( wp_unslash( $_POST['period'] ) ) : 'week';

        // Calculate date filter.
        switch ( $period ) {
            case 'day':
                $start_date = gmdate( 'Y-m-d 00:00:00' );
                break;
            case 'week':
                $start_date = gmdate( 'Y-m-d 00:00:00', strtotime( '-7 days' ) );
                break;
            case 'month':
                $start_date = gmdate( 'Y-m-d 00:00:00', strtotime( '-30 days' ) );
                break;
            default:
                $start_date = gmdate( 'Y-m-d 00:00:00', strtotime( '-7 days' ) );
        }

        $analytics_table = Glimmr_AI_Database::get_table_name( 'analytics' );
        $site_id = Glimmr_AI_Database::get_current_site_id();

        // Build query with optional site filter.
        $site_filter = $site_id ? ' AND site_id = %d' : '';
        $site_params = $site_id ? array( $site_id ) : array();

        // Get response time stats.
        $query = "SELECT
                    COUNT(*) as total_responses,
                    AVG(CAST(JSON_UNQUOTE(JSON_EXTRACT(properties, '$.response_time')) AS DECIMAL(10,3))) as avg_response_time,
                    MIN(CAST(JSON_UNQUOTE(JSON_EXTRACT(properties, '$.response_time')) AS DECIMAL(10,3))) as min_response_time,
                    MAX(CAST(JSON_UNQUOTE(JSON_EXTRACT(properties, '$.response_time')) AS DECIMAL(10,3))) as max_response_time,
                    SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(properties, '$.tokens_used')) AS UNSIGNED)) as total_tokens
                 FROM {$analytics_table}
                 WHERE event_type = 'message_received'
                 AND created_at >= %s {$site_filter}
                 AND JSON_EXTRACT(properties, '$.response_time') IS NOT NULL";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $stats = $wpdb->get_row(
            $wpdb->prepare( $query, array_merge( array( $start_date ), $site_params ) ),
            ARRAY_A
        );

        // Get daily breakdown.
        $query = "SELECT
                    DATE(created_at) as date,
                    COUNT(*) as responses,
                    AVG(CAST(JSON_UNQUOTE(JSON_EXTRACT(properties, '$.response_time')) AS DECIMAL(10,3))) as avg_time,
                    SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(properties, '$.tokens_used')) AS UNSIGNED)) as tokens
                 FROM {$analytics_table}
                 WHERE event_type = 'message_received'
                 AND created_at >= %s {$site_filter}
                 AND JSON_EXTRACT(properties, '$.response_time') IS NOT NULL
                 GROUP BY DATE(created_at)
                 ORDER BY date ASC";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $daily = $wpdb->get_results(
            $wpdb->prepare( $query, array_merge( array( $start_date ), $site_params ) ),
            ARRAY_A
        );

        wp_send_json_success( array(
            'stats' => array(
                'total_responses'    => (int) ( $stats['total_responses'] ?? 0 ),
                'avg_response_time'  => round( (float) ( $stats['avg_response_time'] ?? 0 ), 3 ),
                'min_response_time'  => round( (float) ( $stats['min_response_time'] ?? 0 ), 3 ),
                'max_response_time'  => round( (float) ( $stats['max_response_time'] ?? 0 ), 3 ),
                'total_tokens'       => (int) ( $stats['total_tokens'] ?? 0 ),
            ),
            'daily' => $daily,
        ) );
    }

    // =========================================================================
    // Health Monitoring AJAX Handler (v1.6.0)
    // =========================================================================

    /**
     * AJAX handler for getting system health status.
     *
     * @since 1.6.0
     * @return void
     */
    public function ajax_get_health_status() {
        check_ajax_referer( 'glimmr_ai_admin', 'nonce' );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'glimmr-ai' ) ) );
        }

        global $wpdb;

        $analytics_table = Glimmr_AI_Database::get_table_name( 'analytics' );
        $last_24h = gmdate( 'Y-m-d H:i:s', strtotime( '-24 hours' ) );
        $last_hour = gmdate( 'Y-m-d H:i:s', strtotime( '-1 hour' ) );

        $site_id = Glimmr_AI_Database::get_current_site_id();
        $site_filter = $site_id ? $wpdb->prepare( ' AND site_id = %d', $site_id ) : '';

        // Error count (last 24 hours).
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $error_count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$analytics_table}
                 WHERE event_type = 'error' AND created_at >= %s" . $site_filter,
                $last_24h
            )
        );

        // Recent errors (last hour).
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $recent_errors = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$analytics_table}
                 WHERE event_type = 'error' AND created_at >= %s" . $site_filter,
                $last_hour
            )
        );

        // Token usage (last 24 hours).
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $token_usage = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(properties, '$.tokens_used')) AS UNSIGNED))
                 FROM {$analytics_table}
                 WHERE event_type = 'message_received' AND created_at >= %s" . $site_filter,
                $last_24h
            )
        );

        // API success rate (last 24 hours).
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $total_api_calls = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$analytics_table}
                 WHERE event_type IN ('message_received', 'error')
                 AND created_at >= %s" . $site_filter,
                $last_24h
            )
        );

        $success_rate = $total_api_calls > 0
            ? round( ( ( $total_api_calls - $error_count ) / $total_api_calls ) * 100, 1 )
            : 100;

        // Get error types breakdown.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $error_types = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                    JSON_UNQUOTE(JSON_EXTRACT(properties, '$.error_type')) as error_type,
                    COUNT(*) as count
                 FROM {$analytics_table}
                 WHERE event_type = 'error' AND created_at >= %s" . $site_filter . "
                 GROUP BY error_type
                 ORDER BY count DESC
                 LIMIT 10",
                $last_24h
            ),
            ARRAY_A
        );

        // Get recent error messages.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $recent_error_messages = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                    created_at,
                    JSON_UNQUOTE(JSON_EXTRACT(properties, '$.error_type')) as error_type,
                    JSON_UNQUOTE(JSON_EXTRACT(properties, '$.error_message')) as error_message
                 FROM {$analytics_table}
                 WHERE event_type = 'error' AND created_at >= %s" . $site_filter . "
                 ORDER BY created_at DESC
                 LIMIT 10",
                $last_hour
            ),
            ARRAY_A
        );

        // Check API key status.
        $api_key = Glimmr_AI_Settings::get_api_key();
        $api_configured = ! empty( $api_key );

        // Check vector store status.
        $vector_store_id = Glimmr_AI_Settings::get( 'openai_vector_store_id' );
        $vector_store_configured = ! empty( $vector_store_id );

        // Determine overall health status.
        $health_status = 'healthy';
        $health_issues = array();

        if ( ! $api_configured ) {
            $health_status = 'critical';
            $health_issues[] = __( 'API key not configured', 'glimmr-ai' );
        }

        if ( $recent_errors > 10 ) {
            $health_status = 'critical';
            $health_issues[] = sprintf(
                /* translators: %d: error count */
                __( '%d errors in the last hour', 'glimmr-ai' ),
                $recent_errors
            );
        } elseif ( $recent_errors > 3 ) {
            if ( 'critical' !== $health_status ) {
                $health_status = 'warning';
            }
            $health_issues[] = sprintf(
                /* translators: %d: error count */
                __( '%d errors in the last hour', 'glimmr-ai' ),
                $recent_errors
            );
        }

        if ( $success_rate < 90 && 'critical' !== $health_status ) {
            $health_status = 'warning';
            $health_issues[] = sprintf(
                /* translators: %s: success rate */
                __( 'API success rate is %s%%', 'glimmr-ai' ),
                $success_rate
            );
        }

        // Token budget check.
        $daily_limit = (int) Glimmr_AI_Settings::get( 'daily_token_limit', 100000 );
        $token_percentage = $daily_limit > 0 ? round( ( $token_usage / $daily_limit ) * 100, 1 ) : 0;

        if ( $token_percentage > 90 && 'critical' !== $health_status ) {
            $health_status = 'warning';
            $health_issues[] = sprintf(
                /* translators: %s: percentage */
                __( 'Token usage at %s%% of daily limit', 'glimmr-ai' ),
                $token_percentage
            );
        }

        wp_send_json_success( array(
            'status'  => $health_status,
            'issues'  => $health_issues,
            'metrics' => array(
                'errors_24h'        => (int) $error_count,
                'errors_1h'         => (int) $recent_errors,
                'tokens_24h'        => (int) ( $token_usage ?? 0 ),
                'token_limit'       => $daily_limit,
                'token_percentage'  => $token_percentage,
                'success_rate'      => $success_rate,
                'api_configured'    => $api_configured,
                'vector_configured' => $vector_store_configured,
            ),
            'error_types'       => $error_types,
            'recent_errors'     => $recent_error_messages,
        ) );
    }

    // =========================================================================
    // Get Started / Setup Wizard AJAX Handlers
    // =========================================================================

    /**
     * AJAX handler for getting setup status.
     *
     * Returns the status of all setup steps for the Get Started wizard.
     *
     * @since 1.8.0
     * @return void
     */
    public function ajax_get_setup_status() {
        check_ajax_referer( 'glimmr_ai_admin', 'nonce' );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'glimmr-ai' ) ) );
        }

        global $wpdb;

        $glimmr_ai       = Glimmr_AI::get_instance();
        $vector_store    = $glimmr_ai->get_vector_store();
        $product_indexer = $glimmr_ai->get_product_indexer();
        $settings        = new Glimmr_AI_Settings();

        // Step 1: API Key.
        $api_key            = Glimmr_AI_Settings::get_api_key();
        $api_key_configured = ! empty( $api_key );
        $masked_key         = '';
        $openai_model       = $settings->get( 'openai_model', 'gpt-4o' );

        if ( $api_key_configured ) {
            $length = strlen( $api_key );
            if ( $length > 11 ) {
                $masked_key = substr( $api_key, 0, 7 ) . str_repeat( '*', min( $length - 11, 10 ) ) . substr( $api_key, -4 );
            } else {
                $masked_key = str_repeat( '*', $length );
            }
        }

        // Step 2: Vector Store.
        $vector_store_id = $settings->get( 'openai_vector_store_id' );
        $vector_store_ready = $vector_store->is_ready();

        // Step 3: Products.
        $product_index_table = Glimmr_AI_Database::get_table_name( 'product_index' );

        // Count total WooCommerce products.
        $total_products = 0;
        if ( class_exists( 'WooCommerce' ) ) {
            $total_products = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->posts}
                 WHERE post_type = 'product'
                 AND post_status = 'publish'"
            );
        }

        // Count indexed products.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $indexed_count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$product_index_table}"
        );

        // Count synced products (have vector_file_id).
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $synced_count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$product_index_table} WHERE vector_file_id IS NOT NULL AND vector_file_id != ''"
        );

        // Step 4: Knowledge Base.
        $knowledge_table = Glimmr_AI_Database::get_table_name( 'knowledge' );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $knowledge_total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$knowledge_table}"
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $knowledge_synced = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$knowledge_table} WHERE sync_status = 'synced'"
        );

        // Step 5: Widget.
        $widget_enabled = (bool) $settings->get( 'widget_enabled', false );

        // Calculate overall progress.
        $steps_complete = 0;
        if ( $api_key_configured ) {
            $steps_complete++;
        }
        if ( $vector_store_ready ) {
            $steps_complete++;
        }
        if ( $synced_count > 0 && $synced_count >= $indexed_count ) {
            $steps_complete++;
        }
        // Knowledge is optional, count as complete if no items OR all items synced.
        if ( $knowledge_total === 0 || ( $knowledge_synced > 0 && $knowledge_synced >= $knowledge_total ) ) {
            $steps_complete++;
        }
        if ( $widget_enabled ) {
            $steps_complete++;
        }

        wp_send_json_success( array(
            'steps' => array(
                'api_key' => array(
                    'complete' => $api_key_configured,
                    'status'   => $api_key_configured ? 'connected' : 'not_configured',
                    'details'  => array(
                        'masked_key' => $masked_key,
                        'model'      => $openai_model,
                    ),
                ),
                'vector_store' => array(
                    'complete' => $vector_store_ready,
                    'status'   => $vector_store_ready ? 'ready' : ( ! empty( $vector_store_id ) ? 'invalid' : 'not_created' ),
                    'details'  => array(
                        'store_id' => $vector_store_id,
                    ),
                ),
                'products' => array(
                    'complete' => $synced_count > 0 && $synced_count >= $indexed_count && $indexed_count > 0,
                    'status'   => $synced_count > 0 ? 'synced' : ( $indexed_count > 0 ? 'indexed' : 'pending' ),
                    'details'  => array(
                        'total'   => $total_products,
                        'indexed' => $indexed_count,
                        'synced'  => $synced_count,
                    ),
                ),
                'knowledge' => array(
                    'complete' => $knowledge_total === 0 || ( $knowledge_synced > 0 && $knowledge_synced >= $knowledge_total ),
                    'status'   => $knowledge_synced > 0 ? 'synced' : ( $knowledge_total > 0 ? 'pending' : 'empty' ),
                    'details'  => array(
                        'total'  => $knowledge_total,
                        'synced' => $knowledge_synced,
                    ),
                ),
                'widget' => array(
                    'complete' => $widget_enabled,
                    'status'   => $widget_enabled ? 'enabled' : 'disabled',
                    'details'  => array(
                        'enabled'  => $widget_enabled,
                        'position' => $settings->get( 'widget_position', 'bottom-right' ),
                    ),
                ),
            ),
            'overall_progress' => $steps_complete,
            'total_steps'      => 5,
            'ready'            => $steps_complete === 5,
        ) );
    }

    /**
     * AJAX handler for testing OpenAI API key.
     *
     * Tests the API key without saving it.
     *
     * @since 1.8.0
     * @return void
     */
    public function ajax_test_api_key() {
        check_ajax_referer( 'glimmr_ai_admin', 'nonce' );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'glimmr-ai' ) ) );
        }

        $api_key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';

        if ( empty( $api_key ) ) {
            wp_send_json_error( array( 'message' => __( 'API key is required.', 'glimmr-ai' ) ) );
        }

        // Test the API key by making a simple models request.
        $response = wp_remote_get(
            'https://api.openai.com/v1/models',
            array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type'  => 'application/json',
                ),
                'timeout' => 15,
            )
        );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( array(
                'message' => sprintf(
                    /* translators: %s: error message */
                    __( 'Connection failed: %s', 'glimmr-ai' ),
                    $response->get_error_message()
                ),
            ) );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( 200 !== $code ) {
            $error_message = isset( $body['error']['message'] )
                ? sanitize_text_field( $body['error']['message'] )
                : __( 'Invalid API key.', 'glimmr-ai' );
            wp_send_json_error( array( 'message' => $error_message ) );
        }

        wp_send_json_success( array( 'message' => __( 'API key is valid.', 'glimmr-ai' ) ) );
    }

    /**
     * AJAX handler for saving API key inline (from Get Started page).
     *
     * @since 1.8.0
     * @return void
     */
    public function ajax_save_api_key_inline() {
        check_ajax_referer( 'glimmr_ai_admin', 'nonce' );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'glimmr-ai' ) ) );
        }

        $api_key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';
        $model   = isset( $_POST['model'] ) ? sanitize_text_field( wp_unslash( $_POST['model'] ) ) : 'gpt-4o';

        if ( empty( $api_key ) ) {
            wp_send_json_error( array( 'message' => __( 'API key is required.', 'glimmr-ai' ) ) );
        }

        // Validate model.
        $valid_models = array(
            'gpt-5.2', 'gpt-5.1', 'gpt-5', 'gpt-5-mini', 'gpt-5-nano',
            'gpt-4.1', 'gpt-4.1-mini', 'gpt-4.1-nano',
            'gpt-4o', 'gpt-4o-mini',
            'o4-mini', 'o3-mini',
            'gpt-4-turbo', 'gpt-4',
        );
        if ( ! in_array( $model, $valid_models, true ) ) {
            $model = 'gpt-4o';
        }

        // Test the API key first.
        $response = wp_remote_get(
            'https://api.openai.com/v1/models',
            array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type'  => 'application/json',
                ),
                'timeout' => 15,
            )
        );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( array(
                'message' => sprintf(
                    /* translators: %s: error message */
                    __( 'Connection failed: %s', 'glimmr-ai' ),
                    $response->get_error_message()
                ),
            ) );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( 200 !== $code ) {
            $error_message = isset( $body['error']['message'] )
                ? sanitize_text_field( $body['error']['message'] )
                : __( 'Invalid API key.', 'glimmr-ai' );
            wp_send_json_error( array( 'message' => $error_message ) );
        }

        // Save the API key and model.
        Glimmr_AI_Settings::update( array(
            'openai_api_key' => $api_key,
            'openai_model'   => $model,
        ) );

        // Audit log: API key and model change.
        Glimmr_AI_Audit_Log::log_settings_change( 'openai_api_key', null, null );
        Glimmr_AI_Audit_Log::log_settings_change( 'openai_model', null, $model );

        // Return masked key.
        $length = strlen( $api_key );
        if ( $length > 11 ) {
            $masked_key = substr( $api_key, 0, 7 ) . str_repeat( '*', min( $length - 11, 10 ) ) . substr( $api_key, -4 );
        } else {
            $masked_key = str_repeat( '*', $length );
        }

        wp_send_json_success( array(
            'message'    => __( 'API key saved successfully.', 'glimmr-ai' ),
            'masked_key' => $masked_key,
            'model'      => $model,
        ) );
    }

    /**
     * AJAX handler for creating vector store.
     *
     * @since 1.8.0
     * @return void
     */
    public function ajax_create_vector_store() {
        check_ajax_referer( 'glimmr_ai_admin', 'nonce' );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'glimmr-ai' ) ) );
        }

        $glimmr_ai    = Glimmr_AI::get_instance();
        $vector_store = $glimmr_ai->get_vector_store();

        $result = $vector_store->initialize_vector_store();

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( array(
            'message'  => __( 'Vector store created successfully.', 'glimmr-ai' ),
            'store_id' => $result,
        ) );
    }

    /**
     * AJAX handler for toggling widget enabled state.
     *
     * @since 1.8.0
     * @return void
     */
    public function ajax_toggle_widget() {
        check_ajax_referer( 'glimmr_ai_admin', 'nonce' );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'glimmr-ai' ) ) );
        }

        $enabled = isset( $_POST['enabled'] ) ? filter_var( wp_unslash( $_POST['enabled'] ), FILTER_VALIDATE_BOOLEAN ) : false;

        Glimmr_AI_Settings::update( array(
            'widget_enabled' => $enabled,
        ) );

        wp_send_json_success( array(
            'message' => $enabled ? __( 'Widget enabled.', 'glimmr-ai' ) : __( 'Widget disabled.', 'glimmr-ai' ),
            'enabled' => $enabled,
        ) );
    }

    /**
     * AJAX handler for running quick setup.
     *
     * Runs all setup steps in sequence:
     * 1. Create vector store (if needed)
     * 2. Index products
     * 3. Sync products to vector store
     * 4. Sync knowledge base
     * 5. Enable widget
     *
     * @since 1.8.0
     * @return void
     */
    public function ajax_run_quick_setup() {
        check_ajax_referer( 'glimmr_ai_admin', 'nonce' );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'glimmr-ai' ) ) );
        }

        // Check API key is configured.
        $api_key = Glimmr_AI_Settings::get_api_key();
        if ( empty( $api_key ) ) {
            wp_send_json_error( array(
                'message' => __( 'Please configure your OpenAI API key first.', 'glimmr-ai' ),
                'step'    => 'api_key',
            ) );
        }

        $glimmr_ai       = Glimmr_AI::get_instance();
        $vector_store    = $glimmr_ai->get_vector_store();
        $product_indexer = $glimmr_ai->get_product_indexer();

        $results = array(
            'steps'    => array(),
            'errors'   => array(),
            'complete' => true,
        );

        // Step 1: Create vector store.
        if ( ! $vector_store->is_ready() ) {
            $store_result = $vector_store->initialize_vector_store();
            if ( is_wp_error( $store_result ) ) {
                $results['errors'][] = sprintf(
                    /* translators: %s: error message */
                    __( 'Vector store creation failed: %s', 'glimmr-ai' ),
                    $store_result->get_error_message()
                );
                $results['complete'] = false;
            } else {
                $results['steps']['vector_store'] = array(
                    'success' => true,
                    'message' => __( 'Vector store created.', 'glimmr-ai' ),
                );
            }
        } else {
            $results['steps']['vector_store'] = array(
                'success' => true,
                'message' => __( 'Vector store already exists.', 'glimmr-ai' ),
            );
        }

        // Only continue if vector store is ready.
        if ( $vector_store->is_ready() ) {
            // Step 2: Index products.
            $index_result = $product_indexer->full_sync( true );
            $results['steps']['product_index'] = array(
                'success' => $index_result['success'],
                'message' => sprintf(
                    /* translators: 1: created count, 2: updated count */
                    __( 'Indexed %1$d new, updated %2$d products.', 'glimmr-ai' ),
                    $index_result['created'],
                    $index_result['updated']
                ),
            );

            // Step 3: Sync products to vector store.
            $sync_result = $vector_store->sync_products( true );
            $results['steps']['product_sync'] = array(
                'success' => $sync_result['success'] ?? ( $sync_result['synced'] > 0 ),
                'message' => sprintf(
                    /* translators: 1: synced count, 2: errors count */
                    __( 'Synced %1$d products to AI. %2$d errors.', 'glimmr-ai' ),
                    $sync_result['synced'],
                    $sync_result['errors']
                ),
            );
            if ( $sync_result['errors'] > 0 ) {
                $results['errors'][] = sprintf(
                    /* translators: %d: error count */
                    __( '%d products failed to sync.', 'glimmr-ai' ),
                    $sync_result['errors']
                );
            }

            // Step 4: Sync knowledge base.
            $knowledge_result = $vector_store->sync_knowledge();
            $results['steps']['knowledge_sync'] = array(
                'success' => $knowledge_result['success'] ?? true,
                'message' => sprintf(
                    /* translators: 1: synced count, 2: errors count */
                    __( 'Synced %1$d knowledge items. %2$d errors.', 'glimmr-ai' ),
                    $knowledge_result['synced'],
                    $knowledge_result['errors']
                ),
            );
        }

        // Step 5: Enable widget.
        Glimmr_AI_Settings::update( array( 'widget_enabled' => true ) );
        $results['steps']['widget'] = array(
            'success' => true,
            'message' => __( 'Chat widget enabled.', 'glimmr-ai' ),
        );

        $message = $results['complete']
            ? __( 'Setup complete! Your AI shopping assistant is ready.', 'glimmr-ai' )
            : __( 'Setup completed with some errors. Please review and try again.', 'glimmr-ai' );

        wp_send_json_success( array(
            'message'  => $message,
            'results'  => $results,
            'complete' => $results['complete'],
        ) );
    }

    // =========================================================================
    // Contact Requests AJAX Handlers (v1.8.0)
    // =========================================================================

    /**
     * AJAX handler: Get contact requests list.
     *
     * Returns paginated list of contact requests with optional filtering.
     *
     * @since 1.8.0
     * @return void
     */
    public function ajax_get_contact_requests() {
        check_ajax_referer( 'glimmr_ai_admin', 'nonce' );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'glimmr-ai' ) ) );
        }

        // Get filter parameters.
        $page      = isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 1;
        $per_page  = isset( $_POST['per_page'] ) ? absint( $_POST['per_page'] ) : 20;
        $status    = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '';
        $category  = isset( $_POST['category'] ) ? sanitize_text_field( wp_unslash( $_POST['category'] ) ) : '';
        $priority  = isset( $_POST['priority'] ) ? sanitize_text_field( wp_unslash( $_POST['priority'] ) ) : '';
        $search    = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
        $date_from = isset( $_POST['date_from'] ) ? sanitize_text_field( wp_unslash( $_POST['date_from'] ) ) : '';
        $date_to   = isset( $_POST['date_to'] ) ? sanitize_text_field( wp_unslash( $_POST['date_to'] ) ) : '';
        $orderby   = isset( $_POST['orderby'] ) ? sanitize_text_field( wp_unslash( $_POST['orderby'] ) ) : 'created_at';
        $order     = isset( $_POST['order'] ) ? sanitize_text_field( wp_unslash( $_POST['order'] ) ) : 'DESC';

        // Sanitize per_page.
        $per_page = min( max( $per_page, 5 ), 100 );

        // Build query args.
        $args = array(
            'status'    => empty( $status ) ? null : $status,
            'category'  => empty( $category ) ? null : $category,
            'priority'  => empty( $priority ) ? null : $priority,
            'search'    => empty( $search ) ? null : $search,
            'date_from' => empty( $date_from ) ? null : $date_from,
            'date_to'   => empty( $date_to ) ? null : $date_to,
            'limit'     => $per_page,
            'offset'    => ( $page - 1 ) * $per_page,
            'orderby'   => $orderby,
            'order'     => $order,
        );

        // Get requests.
        $requests = Glimmr_AI_Database::get_contact_requests( $args );

        // Get total count with same filters (minus pagination).
        $count_args = $args;
        unset( $count_args['limit'], $count_args['offset'], $count_args['orderby'], $count_args['order'] );
        $total = Glimmr_AI_Database::count_contact_requests( $count_args );

        // Get stats.
        $stats = Glimmr_AI_Database::get_contact_request_stats();

        // Enrich requests with additional info.
        foreach ( $requests as &$request ) {
            // Add assigned user name.
            if ( ! empty( $request->assigned_to ) ) {
                $user = get_userdata( $request->assigned_to );
                $request->assigned_to_name = $user ? $user->display_name : '';
            } else {
                $request->assigned_to_name = '';
            }

            // Format dates.
            $request->created_at_formatted = human_time_diff( strtotime( $request->created_at ), current_time( 'timestamp' ) ) . ' ' . __( 'ago', 'glimmr-ai' );
        }

        wp_send_json_success( array(
            'requests'   => $requests,
            'total'      => (int) $total,
            'page'       => $page,
            'per_page'   => $per_page,
            'total_pages' => ceil( $total / $per_page ),
            'stats'      => $stats,
        ) );
    }

    /**
     * AJAX handler: Get single contact request detail.
     *
     * Returns full request details including responses and conversation context.
     *
     * @since 1.8.0
     * @return void
     */
    public function ajax_get_contact_request_detail() {
        check_ajax_referer( 'glimmr_ai_admin', 'nonce' );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'glimmr-ai' ) ) );
        }

        $request_id = isset( $_POST['request_id'] ) ? sanitize_text_field( wp_unslash( $_POST['request_id'] ) ) : '';

        if ( empty( $request_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Request ID is required.', 'glimmr-ai' ) ) );
        }

        // Get request.
        $request = Glimmr_AI_Database::get_contact_request( $request_id );

        if ( ! $request ) {
            wp_send_json_error( array( 'message' => __( 'Contact request not found.', 'glimmr-ai' ) ) );
        }

        // Get responses.
        $responses = Glimmr_AI_Database::get_contact_responses( $request_id );

        // Get conversation messages if conversation_id exists.
        $conversation_messages = array();
        if ( ! empty( $request->conversation_id ) ) {
            $messages = Glimmr_AI_Database::get_messages( $request->conversation_id, 50 );
            $conversation_messages = $messages ?: array();
        }

        // Enrich request with additional info.
        if ( ! empty( $request->assigned_to ) ) {
            $user = get_userdata( $request->assigned_to );
            $request->assigned_to_name = $user ? $user->display_name : '';
        } else {
            $request->assigned_to_name = '';
        }

        // Get customer info if user_id exists.
        $customer_info = null;
        if ( ! empty( $request->user_id ) ) {
            $user = get_userdata( $request->user_id );
            if ( $user ) {
                $customer_info = array(
                    'id'           => $user->ID,
                    'display_name' => $user->display_name,
                    'email'        => $user->user_email,
                );
            }
        }

        // Get order info if order_id exists.
        $order_info = null;
        if ( ! empty( $request->order_id ) && function_exists( 'wc_get_order' ) ) {
            $order = wc_get_order( $request->order_id );
            if ( $order ) {
                $order_info = array(
                    'id'     => $order->get_id(),
                    'number' => $order->get_order_number(),
                    'status' => $order->get_status(),
                    'total'  => $order->get_formatted_order_total(),
                    'date'   => $order->get_date_created() ? $order->get_date_created()->date_i18n( get_option( 'date_format' ) ) : '',
                    'url'    => $order->get_edit_order_url(),
                );
            }
        }

        // Get product info if product_id exists.
        $product_info = null;
        if ( ! empty( $request->product_id ) && function_exists( 'wc_get_product' ) ) {
            $product = wc_get_product( $request->product_id );
            if ( $product ) {
                $product_info = array(
                    'id'    => $product->get_id(),
                    'name'  => $product->get_name(),
                    'sku'   => $product->get_sku(),
                    'price' => $product->get_price_html(),
                    'url'   => get_edit_post_link( $product->get_id(), 'raw' ),
                );
            }
        }

        // Format category and priority.
        $request->category_display = Glimmr_AI_Contact_Response::get_category_name( $request->category );
        $request->priority_info    = Glimmr_AI_Contact_Response::get_priority_info( $request->priority );
        $request->status_info      = Glimmr_AI_Contact_Response::get_status_info( $request->status );

        // Get admin users for assignment dropdown.
        $admins = get_users( array(
            'role__in' => array( 'administrator', 'shop_manager' ),
            'fields'   => array( 'ID', 'display_name' ),
        ) );

        wp_send_json_success( array(
            'request'               => $request,
            'responses'             => $responses,
            'conversation_messages' => $conversation_messages,
            'customer_info'         => $customer_info,
            'order_info'            => $order_info,
            'product_info'          => $product_info,
            'admins'                => $admins,
        ) );
    }

    /**
     * AJAX handler: Update contact request.
     *
     * Updates status, priority, or assignment.
     *
     * @since 1.8.0
     * @return void
     */
    public function ajax_update_contact_request() {
        check_ajax_referer( 'glimmr_ai_admin', 'nonce' );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'glimmr-ai' ) ) );
        }

        $request_id = isset( $_POST['request_id'] ) ? sanitize_text_field( wp_unslash( $_POST['request_id'] ) ) : '';

        if ( empty( $request_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Request ID is required.', 'glimmr-ai' ) ) );
        }

        // Check request exists.
        $request = Glimmr_AI_Database::get_contact_request( $request_id );
        if ( ! $request ) {
            wp_send_json_error( array( 'message' => __( 'Contact request not found.', 'glimmr-ai' ) ) );
        }

        // Build update data.
        $data = array();

        if ( isset( $_POST['status'] ) ) {
            $status = sanitize_text_field( wp_unslash( $_POST['status'] ) );
            $valid_statuses = array( 'new', 'in_progress', 'resolved' );
            if ( in_array( $status, $valid_statuses, true ) ) {
                $data['status'] = $status;
            }
        }

        if ( isset( $_POST['priority'] ) ) {
            $priority = sanitize_text_field( wp_unslash( $_POST['priority'] ) );
            $valid_priorities = array( 'low', 'normal', 'high', 'urgent' );
            if ( in_array( $priority, $valid_priorities, true ) ) {
                $data['priority'] = $priority;
            }
        }

        if ( isset( $_POST['assigned_to'] ) ) {
            $assigned_to = absint( $_POST['assigned_to'] );
            $data['assigned_to'] = $assigned_to > 0 ? $assigned_to : null;
        }

        if ( empty( $data ) ) {
            wp_send_json_error( array( 'message' => __( 'No valid fields to update.', 'glimmr-ai' ) ) );
        }

        // Update.
        $result = Glimmr_AI_Database::update_contact_request( $request_id, $data );

        if ( ! $result ) {
            wp_send_json_error( array( 'message' => __( 'Failed to update contact request.', 'glimmr-ai' ) ) );
        }

        // Log audit event.
        if ( class_exists( 'Glimmr_AI_Audit_Log' ) ) {
            Glimmr_AI_Audit_Log::log_analytics_access( 'contact_request_update', array(
                'request_id' => $request_id,
                'changes'    => $data,
            ) );
        }

        wp_send_json_success( array(
            'message' => __( 'Contact request updated.', 'glimmr-ai' ),
        ) );
    }

    /**
     * AJAX handler: Send response to contact request.
     *
     * Sends email to customer and stores response record.
     *
     * @since 1.8.0
     * @return void
     */
    public function ajax_send_contact_response() {
        check_ajax_referer( 'glimmr_ai_admin', 'nonce' );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'glimmr-ai' ) ) );
        }

        $request_id    = isset( $_POST['request_id'] ) ? sanitize_text_field( wp_unslash( $_POST['request_id'] ) ) : '';
        $response_text = isset( $_POST['response_text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['response_text'] ) ) : '';
        $update_status = isset( $_POST['update_status'] ) ? sanitize_text_field( wp_unslash( $_POST['update_status'] ) ) : '';

        if ( empty( $request_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Request ID is required.', 'glimmr-ai' ) ) );
        }

        if ( empty( $response_text ) ) {
            wp_send_json_error( array( 'message' => __( 'Response text is required.', 'glimmr-ai' ) ) );
        }

        // Get request.
        $request = Glimmr_AI_Database::get_contact_request( $request_id );
        if ( ! $request ) {
            wp_send_json_error( array( 'message' => __( 'Contact request not found.', 'glimmr-ai' ) ) );
        }

        // Build options.
        $options = array(
            'send_email' => true,
        );

        // Validate status.
        if ( ! empty( $update_status ) ) {
            $valid_statuses = array( 'new', 'in_progress', 'resolved' );
            if ( in_array( $update_status, $valid_statuses, true ) ) {
                $options['update_status'] = $update_status;
            }
        }

        // Send response.
        $result = Glimmr_AI_Contact_Response::send_response( $request, $response_text, $options );

        if ( ! $result['success'] ) {
            wp_send_json_error( array( 'message' => $result['message'] ) );
        }

        // Log audit event.
        if ( class_exists( 'Glimmr_AI_Audit_Log' ) ) {
            Glimmr_AI_Audit_Log::log_analytics_access( 'contact_response_sent', array(
                'request_id' => $request_id,
                'email_sent' => $result['email_sent'],
            ) );
        }

        wp_send_json_success( array(
            'message'     => $result['message'],
            'response_id' => $result['response_id'],
            'email_sent'  => $result['email_sent'],
        ) );
    }

    /**
     * AJAX handler: Export contact requests.
     *
     * Exports contact requests as CSV or JSON.
     *
     * @since 1.8.0
     * @return void
     */
    public function ajax_export_contact_requests() {
        check_ajax_referer( 'glimmr_ai_admin', 'nonce' );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'glimmr-ai' ) ) );
        }

        $format = isset( $_POST['format'] ) ? sanitize_text_field( wp_unslash( $_POST['format'] ) ) : 'csv';
        $period = isset( $_POST['period'] ) ? sanitize_text_field( wp_unslash( $_POST['period'] ) ) : 'all';

        // Calculate date filter.
        $date_from = null;
        switch ( $period ) {
            case 'day':
                $date_from = gmdate( 'Y-m-d' );
                break;
            case 'week':
                $date_from = gmdate( 'Y-m-d', strtotime( '-7 days' ) );
                break;
            case 'month':
                $date_from = gmdate( 'Y-m-d', strtotime( '-30 days' ) );
                break;
            case 'all':
            default:
                $date_from = null;
                break;
        }

        // Get all requests for the period.
        $requests = Glimmr_AI_Database::get_contact_requests( array(
            'date_from' => $date_from,
            'limit'     => 10000, // Reasonable limit for export.
            'offset'    => 0,
        ) );

        if ( empty( $requests ) ) {
            wp_send_json_error( array( 'message' => __( 'No contact requests found for the selected period.', 'glimmr-ai' ) ) );
        }

        // Format output based on type.
        if ( 'json' === $format ) {
            $data = json_encode( $requests, JSON_PRETTY_PRINT );
            $filename = 'contact-requests-' . gmdate( 'Y-m-d' ) . '.json';
            $mime_type = 'application/json';
        } else {
            // CSV format.
            $data = $this->format_contact_requests_csv( $requests );
            $filename = 'contact-requests-' . gmdate( 'Y-m-d' ) . '.csv';
            $mime_type = 'text/csv';
        }

        wp_send_json_success( array(
            'data'      => $data,
            'filename'  => $filename,
            'mime_type' => $mime_type,
            'count'     => count( $requests ),
        ) );
    }

    /**
     * Format contact requests as CSV.
     *
     * @since 1.8.0
     * @param array $requests Array of request objects.
     * @return string CSV data.
     */
    private function format_contact_requests_csv( $requests ) {
        $output = fopen( 'php://temp', 'r+' );

        // Header row.
        fputcsv( $output, array(
            'Reference',
            'Name',
            'Email',
            'Phone',
            'Subject',
            'Category',
            'Priority',
            'Status',
            'Message',
            'Order ID',
            'Product ID',
            'Created At',
            'Updated At',
            'Resolved At',
        ) );

        // Data rows.
        foreach ( $requests as $request ) {
            fputcsv( $output, array(
                $request->request_id,
                $request->name,
                $request->email,
                $request->phone ?? '',
                $request->subject,
                $request->category,
                $request->priority,
                $request->status,
                $request->message,
                $request->order_id ?? '',
                $request->product_id ?? '',
                $request->created_at,
                $request->updated_at,
                $request->resolved_at ?? '',
            ) );
        }

        rewind( $output );
        $csv = stream_get_contents( $output );
        fclose( $output );

        return $csv;
    }

    // =========================================================================
    // Conversation Flagging AJAX Handlers (v1.9.0)
    // =========================================================================

    /**
     * AJAX handler: Get flagged issues list.
     *
     * Returns paginated list of flagged conversation issues.
     *
     * @since 1.9.0
     * @return void
     */
    public function ajax_get_flagged_issues() {
        check_ajax_referer( 'glimmr_ai_admin', 'nonce' );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'glimmr-ai' ) ) );
        }

        // Get filter parameters.
        $page     = isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 1;
        $per_page = isset( $_POST['per_page'] ) ? absint( $_POST['per_page'] ) : 20;
        $status   = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '';

        // Sanitize per_page.
        $per_page = min( max( $per_page, 5 ), 100 );

        // Build query args.
        $args = array(
            'status'  => empty( $status ) ? null : $status,
            'limit'   => $per_page,
            'offset'  => ( $page - 1 ) * $per_page,
            'orderby' => 'created_at',
            'order'   => 'DESC',
        );

        // Get flagged issues.
        $issues = Glimmr_AI_Database::get_flagged_issues( $args );

        // Get total count.
        $count_args = array( 'status' => empty( $status ) ? null : $status );
        $total      = Glimmr_AI_Database::count_flagged_issues( $count_args );

        // Enrich issues with conversation info.
        foreach ( $issues as &$issue ) {
            // Add reviewer name if reviewed.
            if ( ! empty( $issue->reviewed_by ) ) {
                $user = get_userdata( $issue->reviewed_by );
                $issue->reviewed_by_name = $user ? $user->display_name : '';
            } else {
                $issue->reviewed_by_name = '';
            }

            // Format dates.
            $issue->created_at_formatted = human_time_diff( strtotime( $issue->created_at ), current_time( 'timestamp' ) ) . ' ' . __( 'ago', 'glimmr-ai' );
        }

        wp_send_json_success( array(
            'issues'      => $issues,
            'total'       => (int) $total,
            'page'        => $page,
            'per_page'    => $per_page,
            'total_pages' => ceil( $total / $per_page ),
        ) );
    }

    /**
     * AJAX handler: Flag a conversation.
     *
     * Creates a new flagged issue record for a conversation.
     *
     * @since 1.9.0
     * @return void
     */
    public function ajax_flag_conversation() {
        check_ajax_referer( 'glimmr_ai_admin', 'nonce' );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'glimmr-ai' ) ) );
        }

        $conversation_id = isset( $_POST['conversation_id'] ) ? sanitize_text_field( wp_unslash( $_POST['conversation_id'] ) ) : '';
        $issue_type      = isset( $_POST['issue_type'] ) ? sanitize_text_field( wp_unslash( $_POST['issue_type'] ) ) : '';
        $feedback        = isset( $_POST['feedback'] ) ? sanitize_textarea_field( wp_unslash( $_POST['feedback'] ) ) : '';

        if ( empty( $conversation_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Conversation ID is required.', 'glimmr-ai' ) ) );
        }

        if ( empty( $issue_type ) ) {
            wp_send_json_error( array( 'message' => __( 'Issue type is required.', 'glimmr-ai' ) ) );
        }

        // Validate issue type.
        $valid_types = array( 'incorrect_response', 'inappropriate_content', 'technical_error', 'poor_quality', 'other' );
        if ( ! in_array( $issue_type, $valid_types, true ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid issue type.', 'glimmr-ai' ) ) );
        }

        // Create the flagged issue.
        $result = Glimmr_AI_Database::flag_conversation( $conversation_id, $issue_type, $feedback );

        if ( $result ) {
            // Log audit event.
            if ( class_exists( 'Glimmr_AI_Audit_Log' ) ) {
                Glimmr_AI_Audit_Log::log_event( 'conversation_flagged', array(
                    'conversation_id' => $conversation_id,
                    'issue_type'      => $issue_type,
                ) );
            }

            wp_send_json_success( array(
                'message'  => __( 'Conversation flagged successfully.', 'glimmr-ai' ),
                'issue_id' => $result,
            ) );
        } else {
            wp_send_json_error( array( 'message' => __( 'Failed to flag conversation.', 'glimmr-ai' ) ) );
        }
    }

    /**
     * AJAX handler: Resolve a flagged issue.
     *
     * Updates the status of a flagged issue to resolved or dismissed.
     *
     * @since 1.9.0
     * @return void
     */
    public function ajax_resolve_issue() {
        check_ajax_referer( 'glimmr_ai_admin', 'nonce' );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'glimmr-ai' ) ) );
        }

        $issue_id    = isset( $_POST['issue_id'] ) ? absint( $_POST['issue_id'] ) : 0;
        $status      = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : 'resolved';
        $admin_notes = isset( $_POST['admin_notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['admin_notes'] ) ) : '';

        if ( empty( $issue_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Issue ID is required.', 'glimmr-ai' ) ) );
        }

        // Validate status.
        $valid_statuses = array( 'resolved', 'dismissed', 'in_progress' );
        if ( ! in_array( $status, $valid_statuses, true ) ) {
            $status = 'resolved';
        }

        // Resolve the issue.
        $result = Glimmr_AI_Database::resolve_flagged_issue( $issue_id, $status, $admin_notes );

        if ( $result ) {
            // Log audit event.
            if ( class_exists( 'Glimmr_AI_Audit_Log' ) ) {
                Glimmr_AI_Audit_Log::log_event( 'flagged_issue_resolved', array(
                    'issue_id' => $issue_id,
                    'status'   => $status,
                ) );
            }

            wp_send_json_success( array( 'message' => __( 'Issue resolved successfully.', 'glimmr-ai' ) ) );
        } else {
            wp_send_json_error( array( 'message' => __( 'Failed to resolve issue.', 'glimmr-ai' ) ) );
        }
    }

    /**
     * Initialize license-only mode.
     *
     * Registers a minimal admin menu that only shows the license activation page.
     * No settings, conversations, analytics, or other plugin features are loaded.
     *
     * @since 1.9.0
     * @return void
     */
    public function init_license_only_mode() {
        add_action( 'admin_menu', array( $this, 'add_license_only_menu' ) );
        add_action( 'wp_ajax_glimmr_ai_activate_license', array( $this, 'ajax_activate_license' ) );
        add_action( 'wp_ajax_glimmr_ai_deactivate_license', array( $this, 'ajax_deactivate_license' ) );
    }

    /**
     * Add a minimal admin menu for license entry only.
     *
     * @since 1.9.0
     * @return void
     */
    public function add_license_only_menu() {
        add_menu_page(
            __( 'Glimmr AI', 'glimmr-ai' ),
            __( 'Glimmr AI', 'glimmr-ai' ),
            self::CAPABILITY,
            self::MENU_SLUG,
            array( $this, 'render_license_page' ),
            'dashicons-format-chat',
            56
        );
    }

    /**
     * Render the license activation page.
     *
     * @since 1.9.0
     * @return void
     */
    public function render_license_page() {
        require_once GLIMMR_AI_PLUGIN_DIR . 'admin/partials/license-activation.php';
    }

    /**
     * AJAX handler: Activate a license key.
     *
     * @since 1.9.0
     * @return void
     */
    public function ajax_activate_license() {
        check_ajax_referer( 'glimmr_ai_license_nonce', '_wpnonce' );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'glimmr-ai' ) ) );
        }

        $license_key = isset( $_POST['license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['license_key'] ) ) : '';

        $license = Glimmr_AI_License::get_instance();
        $result  = $license->activate( $license_key );

        if ( $result['success'] ) {
            wp_send_json_success( $result );
        } else {
            wp_send_json_error( $result );
        }
    }

    /**
     * AJAX handler: Deactivate the current license.
     *
     * @since 1.9.0
     * @return void
     */
    public function ajax_deactivate_license() {
        check_ajax_referer( 'glimmr_ai_license_nonce', '_wpnonce' );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'glimmr-ai' ) ) );
        }

        $license = Glimmr_AI_License::get_instance();
        $result  = $license->deactivate();

        if ( $result['success'] ) {
            wp_send_json_success( $result );
        } else {
            wp_send_json_error( $result );
        }
    }

    /**
     * Get license status data for the admin settings page.
     *
     * Returns the current license status, plan, and usage info for display
     * in the License tab of the settings page.
     *
     * @since 1.9.0
     * @return array License status data.
     */
    public function get_license_status_for_settings() {
        if ( ! class_exists( 'Glimmr_AI_License' ) ) {
            require_once GLIMMR_AI_PLUGIN_DIR . 'includes/class-glimmr-ai-license.php';
        }

        return Glimmr_AI_License::get_instance()->get_status();
    }
}
