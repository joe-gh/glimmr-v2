<?php
/**
 * The core plugin class.
 *
 * This is used to define internationalization, admin-specific hooks,
 * and public-facing site hooks.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Class Glimmr_AI
 *
 * The main plugin orchestrator class.
 */
class Glimmr_AI {

    /**
     * The loader that's responsible for maintaining and registering all hooks.
     *
     * @var array
     */
    protected $actions = array();

    /**
     * The filters registered with WordPress.
     *
     * @var array
     */
    protected $filters = array();

    /**
     * Plugin instance.
     *
     * @var Glimmr_AI|null
     */
    private static $instance = null;

    /**
     * Get plugin instance.
     *
     * @return Glimmr_AI
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Settings instance.
     *
     * @var Glimmr_AI_Settings
     */
    private $settings;

    /**
     * Database instance.
     *
     * @var Glimmr_AI_Database
     */
    private $database;

    /**
     * OpenAI client instance.
     *
     * @var Glimmr_AI_OpenAI
     */
    private $openai;

    /**
     * Cron handler instance.
     *
     * @var Glimmr_AI_Cron
     */
    private $cron;

    /**
     * Tool registry instance.
     *
     * @var Glimmr_AI_Tool_Registry
     */
    private $tool_registry;

    /**
     * Conversion tracker instance.
     *
     * @var Glimmr_AI_Conversion_Tracker
     */
    private $conversion_tracker;

    /**
     * Define the core functionality of the plugin.
     *
     * @since 1.0.0
     */
    public function __construct() {
        $this->load_dependencies();
        $this->init_core_services();
        $this->set_locale();
        $this->define_admin_hooks();
        $this->define_public_hooks();
        $this->define_cron_hooks();
    }

    /**
     * Load the required dependencies for this plugin.
     *
     * @since 1.0.0
     * @access private
     * @return void
     */
    private function load_dependencies() {
        // Core classes.
        require_once GLIMMR_AI_PLUGIN_DIR . 'includes/class-glimmr-ai-database.php';
        require_once GLIMMR_AI_PLUGIN_DIR . 'includes/class-glimmr-ai-settings.php';
        require_once GLIMMR_AI_PLUGIN_DIR . 'includes/class-glimmr-ai-pii-masker.php';
        require_once GLIMMR_AI_PLUGIN_DIR . 'includes/class-glimmr-ai-audit-log.php';

        // HTTP client with retry logic (must load before OpenAI).
        require_once GLIMMR_AI_PLUGIN_DIR . 'includes/class-glimmr-ai-http-client.php';

        // Content moderation (v1.7.0).
        require_once GLIMMR_AI_PLUGIN_DIR . 'includes/class-glimmr-ai-moderation.php';

        // OpenAI integration classes.
        require_once GLIMMR_AI_PLUGIN_DIR . 'includes/class-glimmr-ai-openai.php';
        require_once GLIMMR_AI_PLUGIN_DIR . 'includes/class-glimmr-ai-vector-store.php';
        require_once GLIMMR_AI_PLUGIN_DIR . 'includes/class-glimmr-ai-conversation.php';
        require_once GLIMMR_AI_PLUGIN_DIR . 'includes/class-glimmr-ai-context.php';
        require_once GLIMMR_AI_PLUGIN_DIR . 'includes/class-glimmr-ai-rate-limiter.php';
        require_once GLIMMR_AI_PLUGIN_DIR . 'includes/class-glimmr-ai-product-indexer.php';
        require_once GLIMMR_AI_PLUGIN_DIR . 'includes/class-glimmr-ai-cron.php';
        require_once GLIMMR_AI_PLUGIN_DIR . 'includes/class-glimmr-ai-tool-registry.php';

        // Slot-filling agent architecture (v1.1.0).
        require_once GLIMMR_AI_PLUGIN_DIR . 'includes/class-glimmr-ai-controller-schema.php';
        require_once GLIMMR_AI_PLUGIN_DIR . 'includes/class-glimmr-ai-workspace.php';
        require_once GLIMMR_AI_PLUGIN_DIR . 'includes/class-glimmr-ai-parameter-validator.php';

        // LLM-based reference resolution (v1.8.0).
        require_once GLIMMR_AI_PLUGIN_DIR . 'includes/class-glimmr-ai-entity-card.php';
        require_once GLIMMR_AI_PLUGIN_DIR . 'includes/class-glimmr-ai-focus-frame.php';
        require_once GLIMMR_AI_PLUGIN_DIR . 'includes/class-glimmr-ai-reference-validator.php';

        // Tool result summarizer for context efficiency (v1.9.0).
        require_once GLIMMR_AI_PLUGIN_DIR . 'includes/class-glimmr-ai-tool-summarizer.php';

        // Phase 6: Analytics, Conversion Tracking, Logging.
        require_once GLIMMR_AI_PLUGIN_DIR . 'includes/class-glimmr-ai-analytics.php';
        require_once GLIMMR_AI_PLUGIN_DIR . 'includes/class-glimmr-ai-conversion-tracker.php';
        require_once GLIMMR_AI_PLUGIN_DIR . 'includes/class-glimmr-ai-logger.php';

        // SEO Integration (v1.8.0).
        require_once GLIMMR_AI_PLUGIN_DIR . 'includes/class-glimmr-ai-seo.php';

        // Contact Response Handler (v1.8.0).
        require_once GLIMMR_AI_PLUGIN_DIR . 'includes/class-glimmr-ai-contact-response.php';

        // Check for database upgrades.
        Glimmr_AI_Database::maybe_upgrade();

        // Admin classes (only in admin context).
        if ( is_admin() ) {
            require_once GLIMMR_AI_PLUGIN_DIR . 'admin/class-glimmr-ai-admin.php';

            // Network admin class (multisite only).
            if ( is_multisite() ) {
                require_once GLIMMR_AI_PLUGIN_DIR . 'admin/class-glimmr-ai-network-admin.php';
            }
        }

        // Public classes (only on frontend).
        if ( ! is_admin() || wp_doing_ajax() ) {
            require_once GLIMMR_AI_PLUGIN_DIR . 'public/class-glimmr-ai-public.php';
        }

        // REST API (always loaded).
        require_once GLIMMR_AI_PLUGIN_DIR . 'includes/class-glimmr-ai-rest-api.php';
    }

    /**
     * Initialize core service instances.
     *
     * @since 1.0.0
     * @access private
     * @return void
     */
    private function init_core_services() {
        // Initialize logger first for error tracking.
        $debug_mode = defined( 'WP_DEBUG' ) && WP_DEBUG;
        Glimmr_AI_Logger::init( $debug_mode );

        $this->database = new Glimmr_AI_Database();
        $this->settings = new Glimmr_AI_Settings();

        // Apply log level from settings (overrides WP_DEBUG default).
        $log_level = Glimmr_AI_Settings::get( 'log_level', 'warning' );
        Glimmr_AI_Logger::set_log_level( $log_level );

        $this->openai   = new Glimmr_AI_OpenAI( $this->settings );
        $this->cron     = new Glimmr_AI_Cron( $this->settings );

        // Initialize tool registry with all dependencies.
        $this->tool_registry = Glimmr_AI_Tool_Registry::get_instance(
            $this->settings,
            $this->database,
            $this->openai
        );

        // Initialize conversion tracker for WooCommerce hooks.
        $this->conversion_tracker = new Glimmr_AI_Conversion_Tracker();

        // Initialize SEO integration (v1.8.0).
        Glimmr_AI_SEO::get_instance();

        Glimmr_AI_Logger::info( 'Glimmr AI initialized', array(), 'core' );
    }

    /**
     * Get settings instance.
     *
     * @return Glimmr_AI_Settings
     */
    public function get_settings() {
        return $this->settings;
    }

    /**
     * Get database instance.
     *
     * @return Glimmr_AI_Database
     */
    public function get_database() {
        return $this->database;
    }

    /**
     * Get OpenAI instance.
     *
     * @return Glimmr_AI_OpenAI
     */
    public function get_openai() {
        return $this->openai;
    }

    /**
     * Get tool registry instance.
     *
     * @return Glimmr_AI_Tool_Registry
     */
    public function get_tool_registry() {
        return $this->tool_registry;
    }

    /**
     * Define the locale for internationalization.
     *
     * @since 1.0.0
     * @access private
     * @return void
     */
    private function set_locale() {
        add_action( 'init', array( $this, 'load_textdomain' ) );
    }

    /**
     * Load the plugin text domain for translation.
     *
     * @since 1.0.0
     * @return void
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'glimmr-ai',
            false,
            dirname( GLIMMR_AI_PLUGIN_BASENAME ) . '/languages/'
        );
    }

    /**
     * Register all admin-related hooks.
     *
     * @since 1.0.0
     * @access private
     * @return void
     */
    private function define_admin_hooks() {
        if ( ! is_admin() ) {
            return;
        }

        $admin = new Glimmr_AI_Admin();

        // Admin menu.
        add_action( 'admin_menu', array( $admin, 'add_admin_menu' ) );
        add_action( 'network_admin_menu', array( $admin, 'add_network_admin_menu' ) );

        // Admin scripts and styles.
        add_action( 'admin_enqueue_scripts', array( $admin, 'enqueue_styles' ) );
        add_action( 'admin_enqueue_scripts', array( $admin, 'enqueue_scripts' ) );

        // AJAX handlers.
        add_action( 'wp_ajax_glimmr_ai_get_settings', array( $admin, 'ajax_get_settings' ) );
        add_action( 'wp_ajax_glimmr_ai_save_settings', array( $admin, 'ajax_save_settings' ) );
        add_action( 'wp_ajax_glimmr_ai_get_categories', array( $admin, 'ajax_get_categories' ) );
        add_action( 'wp_ajax_glimmr_ai_sync_products', array( $admin, 'ajax_sync_products' ) );
        add_action( 'wp_ajax_glimmr_ai_reindex_products', array( $admin, 'ajax_reindex_products' ) );
        add_action( 'wp_ajax_glimmr_ai_sync_knowledge', array( $admin, 'ajax_sync_knowledge' ) );
        add_action( 'wp_ajax_glimmr_ai_get_conversations', array( $admin, 'ajax_get_conversations' ) );
        add_action( 'wp_ajax_glimmr_ai_get_conversation_messages', array( $admin, 'ajax_get_conversation_messages' ) );
        add_action( 'wp_ajax_glimmr_ai_get_analytics', array( $admin, 'ajax_get_analytics' ) );

        // Knowledge Base AJAX handlers.
        add_action( 'wp_ajax_glimmr_ai_get_knowledge', array( $admin, 'ajax_get_knowledge' ) );
        add_action( 'wp_ajax_glimmr_ai_get_posts', array( $admin, 'ajax_get_posts' ) );
        add_action( 'wp_ajax_glimmr_ai_toggle_knowledge', array( $admin, 'ajax_toggle_knowledge' ) );
        add_action( 'wp_ajax_glimmr_ai_bulk_toggle_knowledge', array( $admin, 'ajax_bulk_toggle_knowledge' ) );
        add_action( 'wp_ajax_glimmr_ai_sync_knowledge_item', array( $admin, 'ajax_sync_knowledge_item' ) );
        add_action( 'wp_ajax_glimmr_ai_add_custom_knowledge', array( $admin, 'ajax_add_custom_knowledge' ) );
        add_action( 'wp_ajax_glimmr_ai_edit_custom_knowledge', array( $admin, 'ajax_edit_custom_knowledge' ) );
        add_action( 'wp_ajax_glimmr_ai_delete_custom_knowledge', array( $admin, 'ajax_delete_custom_knowledge' ) );

        // Product Sync AJAX handlers (with progress tracking).
        add_action( 'wp_ajax_glimmr_ai_get_product_sync_status', array( $admin, 'ajax_get_product_sync_status' ) );
        add_action( 'wp_ajax_glimmr_ai_get_product_sync_progress', array( $admin, 'ajax_get_product_sync_progress' ) );
        add_action( 'wp_ajax_glimmr_ai_sync_products_batch', array( $admin, 'ajax_sync_products_batch' ) );
        add_action( 'wp_ajax_glimmr_ai_cancel_product_sync', array( $admin, 'ajax_cancel_product_sync' ) );
        add_action( 'wp_ajax_glimmr_ai_clear_product_sync_errors', array( $admin, 'ajax_clear_product_sync_errors' ) );
        add_action( 'wp_ajax_glimmr_ai_purge_products', array( $admin, 'ajax_purge_products' ) );
        add_action( 'wp_ajax_glimmr_ai_purge_everything', array( $admin, 'ajax_purge_everything' ) );
        add_action( 'wp_ajax_glimmr_ai_purge_vector_store_direct', array( $admin, 'ajax_purge_vector_store_direct' ) );
        add_action( 'wp_ajax_glimmr_ai_sync_full', array( $admin, 'ajax_sync_full' ) );
        add_action( 'wp_ajax_glimmr_ai_sync_everything', array( $admin, 'ajax_sync_everything' ) );

        // Prompts & Tools AJAX handlers.
        add_action( 'wp_ajax_glimmr_ai_get_prompts_tools', array( $admin, 'ajax_get_prompts_tools' ) );
        add_action( 'wp_ajax_glimmr_ai_save_prompts_tools', array( $admin, 'ajax_save_prompts_tools' ) );

        // Logging AJAX handlers.
        add_action( 'wp_ajax_glimmr_ai_get_logs', array( $admin, 'ajax_get_logs' ) );
        add_action( 'wp_ajax_glimmr_ai_download_logs', array( $admin, 'ajax_download_logs' ) );
        add_action( 'wp_ajax_glimmr_ai_clear_logs', array( $admin, 'ajax_clear_logs' ) );

        // Developer/maintenance AJAX handlers.
        add_action( 'wp_ajax_glimmr_ai_purge_conversation_history', array( $admin, 'ajax_purge_conversation_history' ) );

        // Export, analytics, and health AJAX handlers (v1.6.0).
        add_action( 'wp_ajax_glimmr_ai_export_conversations', array( $admin, 'ajax_export_conversations' ) );
        add_action( 'wp_ajax_glimmr_ai_get_response_time_analytics', array( $admin, 'ajax_get_response_time_analytics' ) );
        add_action( 'wp_ajax_glimmr_ai_get_health_status', array( $admin, 'ajax_get_health_status' ) );

        // Get Started / Setup Wizard AJAX handlers (v1.8.0).
        add_action( 'wp_ajax_glimmr_ai_get_setup_status', array( $admin, 'ajax_get_setup_status' ) );
        add_action( 'wp_ajax_glimmr_ai_test_api_key', array( $admin, 'ajax_test_api_key' ) );
        add_action( 'wp_ajax_glimmr_ai_save_api_key_inline', array( $admin, 'ajax_save_api_key_inline' ) );
        add_action( 'wp_ajax_glimmr_ai_create_vector_store', array( $admin, 'ajax_create_vector_store' ) );
        add_action( 'wp_ajax_glimmr_ai_toggle_widget', array( $admin, 'ajax_toggle_widget' ) );
        add_action( 'wp_ajax_glimmr_ai_run_quick_setup', array( $admin, 'ajax_run_quick_setup' ) );

        // Contact Requests AJAX handlers (v1.8.0).
        add_action( 'wp_ajax_glimmr_ai_get_contact_requests', array( $admin, 'ajax_get_contact_requests' ) );
        add_action( 'wp_ajax_glimmr_ai_get_contact_request_detail', array( $admin, 'ajax_get_contact_request_detail' ) );
        add_action( 'wp_ajax_glimmr_ai_update_contact_request', array( $admin, 'ajax_update_contact_request' ) );
        add_action( 'wp_ajax_glimmr_ai_send_contact_response', array( $admin, 'ajax_send_contact_response' ) );
        add_action( 'wp_ajax_glimmr_ai_export_contact_requests', array( $admin, 'ajax_export_contact_requests' ) );

        // Conversation Flagging AJAX handlers (v1.9.0).
        add_action( 'wp_ajax_glimmr_ai_get_flagged_issues', array( $admin, 'ajax_get_flagged_issues' ) );
        add_action( 'wp_ajax_glimmr_ai_flag_conversation', array( $admin, 'ajax_flag_conversation' ) );
        add_action( 'wp_ajax_glimmr_ai_resolve_issue', array( $admin, 'ajax_resolve_issue' ) );

        // License management AJAX handlers (v1.9.0).
        add_action( 'wp_ajax_glimmr_ai_activate_license', array( $admin, 'ajax_activate_license' ) );
        add_action( 'wp_ajax_glimmr_ai_deactivate_license', array( $admin, 'ajax_deactivate_license' ) );

        // Activation redirect.
        add_action( 'admin_init', array( $admin, 'activation_redirect' ) );

        // Plugin action links.
        add_filter(
            'plugin_action_links_' . GLIMMR_AI_PLUGIN_BASENAME,
            array( $admin, 'add_action_links' )
        );

        // Network admin (multisite only).
        if ( is_multisite() ) {
            $network_admin = new Glimmr_AI_Network_Admin();
            $network_admin->init();
        }
    }

    /**
     * Register all public-facing hooks.
     *
     * @since 1.0.0
     * @access private
     * @return void
     */
    private function define_public_hooks() {
        if ( is_admin() && ! wp_doing_ajax() ) {
            return;
        }

        $public = new Glimmr_AI_Public();

        // Enqueue scripts and styles.
        add_action( 'wp_enqueue_scripts', array( $public, 'enqueue_styles' ) );
        add_action( 'wp_enqueue_scripts', array( $public, 'enqueue_scripts' ) );

        // Output chat widget.
        add_action( 'wp_footer', array( $public, 'render_chat_widget' ) );

        // WooCommerce hooks for product sync.
        add_action( 'save_post_product', array( $this, 'on_product_save' ), 10, 3 );
        add_action( 'woocommerce_update_product', array( $this, 'on_product_update' ) );
        add_action( 'before_delete_post', array( $this, 'on_product_delete' ) );
    }

    /**
     * Register cron-related hooks.
     *
     * @since 1.0.0
     * @access private
     * @return void
     */
    private function define_cron_hooks() {
        // Initialize the cron handler (registers its own hooks).
        $this->cron->init();

        // Product indexer hooks for real-time updates.
        $indexer = new Glimmr_AI_Product_Indexer( $this->database, $this->settings );
        $indexer->init_hooks();
    }

    /**
     * Run the plugin.
     *
     * @since 1.0.0
     * @return void
     */
    public function run() {
        // Initialize REST API.
        add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
    }

    /**
     * Register REST API routes.
     *
     * @since 1.0.0
     * @return void
     */
    public function register_rest_routes() {
        $rest_api = new Glimmr_AI_REST_API();
        $rest_api->register_routes();
    }

    /**
     * Handle product save for immediate index update.
     *
     * @param int     $post_id The post ID.
     * @param WP_Post $post    The post object.
     * @param bool    $update  Whether this is an update.
     * @return void
     */
    public function on_product_save( $post_id, $post, $update ) {
        // Skip autosaves and revisions.
        if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
            return;
        }

        // Skip if not a published product.
        if ( 'publish' !== $post->post_status ) {
            return;
        }

        // Queue for index update.
        $this->queue_product_for_sync( $post_id );
    }

    /**
     * Handle WooCommerce product update.
     *
     * @param int $product_id The product ID.
     * @return void
     */
    public function on_product_update( $product_id ) {
        $this->queue_product_for_sync( $product_id );
    }

    /**
     * Handle product deletion.
     *
     * @param int $post_id The post ID.
     * @return void
     */
    public function on_product_delete( $post_id ) {
        if ( 'product' !== get_post_type( $post_id ) ) {
            return;
        }

        // Remove from product index.
        global $wpdb;
        $table_name = Glimmr_AI_Database::get_table_name( 'product_index' );
        $site_id    = Glimmr_AI_Database::get_current_site_id();

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->delete(
            $table_name,
            array(
                'product_id' => $post_id,
                'site_id'    => $site_id,
            ),
            array( '%d', '%d' )
        );
    }

    /**
     * Queue a product for sync.
     *
     * @param int $product_id The product ID.
     * @return void
     */
    private function queue_product_for_sync( $product_id ) {
        // The product indexer hooks handle this automatically.
        // This method is kept for backward compatibility with direct calls.
        $indexer = new Glimmr_AI_Product_Indexer( $this->database, $this->settings );
        $indexer->index_product( $product_id );
    }

    /**
     * Get the product indexer instance.
     *
     * @return Glimmr_AI_Product_Indexer
     */
    public function get_product_indexer() {
        return new Glimmr_AI_Product_Indexer( $this->database, $this->settings );
    }

    /**
     * Get the vector store instance.
     *
     * @return Glimmr_AI_Vector_Store
     */
    public function get_vector_store() {
        return new Glimmr_AI_Vector_Store( $this->openai, $this->database, $this->settings );
    }

    /**
     * Get the conversation manager instance.
     *
     * @return Glimmr_AI_Conversation
     */
    public function get_conversation_manager() {
        return new Glimmr_AI_Conversation( $this->database, $this->settings, $this->openai );
    }

    /**
     * Get the context builder instance.
     *
     * @return Glimmr_AI_Context
     */
    public function get_context_builder() {
        return new Glimmr_AI_Context( $this->settings );
    }

    /**
     * Get the rate limiter instance.
     *
     * @return Glimmr_AI_Rate_Limiter
     */
    public function get_rate_limiter() {
        return new Glimmr_AI_Rate_Limiter( $this->database, $this->settings );
    }

    /**
     * Get the cron handler instance.
     *
     * @return Glimmr_AI_Cron
     */
    public function get_cron() {
        return $this->cron;
    }
}
