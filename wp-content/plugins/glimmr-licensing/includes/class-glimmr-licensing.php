<?php
/**
 * Main orchestrator class for the Glimmr Licensing plugin.
 *
 * @package Glimmr_Licensing
 * @since   1.0.0
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Class Glimmr_Licensing
 *
 * Singleton orchestrator that bootstraps all plugin components:
 * database, REST API, admin UI, and WooCommerce integration.
 */
class Glimmr_Licensing {

    /**
     * Singleton instance.
     *
     * @var Glimmr_Licensing|null
     */
    private static $instance = null;

    /**
     * Get the singleton instance.
     *
     * @return Glimmr_Licensing
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor.
     */
    private function __construct() {}

    /**
     * Run the plugin — load dependencies and register hooks.
     *
     * @return void
     */
    public function run() {
        $this->load_dependencies();
        $this->register_hooks();
    }

    /**
     * Load required class files.
     *
     * @return void
     */
    private function load_dependencies() {
        $dir = GLIMMR_LICENSING_PLUGIN_DIR . 'includes/';

        require_once $dir . 'class-glimmr-licensing-database.php';
        require_once $dir . 'class-glimmr-licensing-key-generator.php';
        require_once $dir . 'class-glimmr-licensing-manager.php';
        require_once $dir . 'class-glimmr-licensing-api.php';
        require_once $dir . 'class-glimmr-licensing-woocommerce.php';

        if ( is_admin() ) {
            require_once $dir . 'class-glimmr-licensing-activator.php';
            require_once GLIMMR_LICENSING_PLUGIN_DIR . 'admin/class-glimmr-licensing-admin.php';
        }
    }

    /**
     * Register all hooks with WordPress.
     *
     * @return void
     */
    private function register_hooks() {
        // Check for database schema migrations on admin loads.
        if ( is_admin() ) {
            Glimmr_Licensing_Database::maybe_migrate();
        }

        // REST API.
        $api = new Glimmr_Licensing_API();
        add_action( 'rest_api_init', array( $api, 'register_routes' ) );

        // WooCommerce integration.
        $woo = new Glimmr_Licensing_WooCommerce();
        $woo->register_hooks();

        // Admin.
        if ( is_admin() ) {
            add_action( 'admin_init', array( 'Glimmr_Licensing_Activator', 'maybe_seed_products' ) );

            $admin = new Glimmr_Licensing_Admin();
            $admin->register_hooks();
        }

        // Daily cron for expiry checks — self-healing if event was lost.
        add_action( 'glimmr_licensing_daily_check', array( $this, 'check_expired_licenses' ) );
        if ( ! wp_next_scheduled( 'glimmr_licensing_daily_check' ) ) {
            wp_schedule_event( time(), 'daily', 'glimmr_licensing_daily_check' );
        }
    }

    /**
     * Check for licenses that have passed their expiry date and mark them expired.
     *
     * @return void
     */
    public function check_expired_licenses() {
        $manager = new Glimmr_Licensing_Manager();
        $manager->expire_past_due_licenses();
    }
}
