<?php
/**
 * Plugin Name: Glimmr AI Shopping Assistant
 * Plugin URI: https://glimmr.com/ai
 * Description: AI-powered shopping assistant for WooCommerce with OpenAI integration, product recommendations, order tracking, and intelligent customer support.
 * Version: 1.0.0
 * Author: Joseph DiGiovanna - Vimpact Consulting LLC
 * Author URI: mailto:joseph.p.digiovanna@gmail.com
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: glimmr-ai
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * WC requires at least: 8.0
 * WC tested up to: 9.0
 *
 * @package Glimmr_AI
 * @author Joseph DiGiovanna <joseph.p.digiovanna@gmail.com>
 * @copyright 2025 Vimpact Consulting LLC
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Current plugin version.
 */
define( 'GLIMMR_AI_VERSION', '1.0.2' );

/**
 * Plugin base path.
 */
define( 'GLIMMR_AI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Plugin base URL.
 */
define( 'GLIMMR_AI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Plugin basename.
 */
define( 'GLIMMR_AI_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Database table prefix for this plugin.
 */
define( 'GLIMMR_AI_TABLE_PREFIX', 'glimmr_ai_' );

/**
 * Register WP-CLI commands if available.
 */
if ( defined( 'WP_CLI' ) && WP_CLI ) {
    require_once GLIMMR_AI_PLUGIN_DIR . 'includes/class-glimmr-ai-cli.php';
    WP_CLI::add_hook( 'after_wp_load', array( 'Glimmr_AI_CLI', 'register_commands' ) );

    // Load agent test runner.
    $test_runner_file = GLIMMR_AI_PLUGIN_DIR . 'bin/run-agent-tests.php';
    if ( file_exists( $test_runner_file ) ) {
        require_once $test_runner_file;
    }
}

/**
 * Check if WooCommerce is active.
 *
 * @return bool
 */
function glimmr_ai_is_woocommerce_active() {
    return class_exists( 'WooCommerce' );
}

/**
 * Display admin notice if WooCommerce is not active.
 */
function glimmr_ai_woocommerce_missing_notice() {
    ?>
    <div class="notice notice-error">
        <p><?php esc_html_e( 'Glimmr AI Shopping Assistant requires WooCommerce to be installed and activated.', 'glimmr-ai' ); ?></p>
    </div>
    <?php
}

/**
 * The code that runs during plugin activation.
 *
 * @param bool $network_wide Whether the plugin is being activated network-wide.
 */
function glimmr_ai_activate( $network_wide = false ) {
    if ( ! glimmr_ai_is_woocommerce_active() ) {
        deactivate_plugins( plugin_basename( __FILE__ ) );
        wp_die(
            esc_html__( 'Glimmr AI Shopping Assistant requires WooCommerce to be installed and activated.', 'glimmr-ai' ),
            'Plugin Activation Error',
            array( 'back_link' => true )
        );
    }

    require_once GLIMMR_AI_PLUGIN_DIR . 'includes/class-glimmr-ai-activator.php';

    if ( $network_wide && is_multisite() ) {
        // Network activation: provision all existing sites.
        $sites = get_sites( array( 'fields' => 'ids' ) );
        foreach ( $sites as $blog_id ) {
            switch_to_blog( $blog_id );
            Glimmr_AI_Activator::activate();
            restore_current_blog();
        }
    } else {
        Glimmr_AI_Activator::activate();
    }
}

/**
 * The code that runs during plugin deactivation.
 */
function glimmr_ai_deactivate() {
    require_once GLIMMR_AI_PLUGIN_DIR . 'includes/class-glimmr-ai-deactivator.php';
    Glimmr_AI_Deactivator::deactivate();
}

register_activation_hook( __FILE__, 'glimmr_ai_activate' );
register_deactivation_hook( __FILE__, 'glimmr_ai_deactivate' );

/**
 * Provision new sites created after network activation.
 *
 * When the plugin is network-activated and a new site is created,
 * this hook ensures the new site gets database tables and default settings.
 *
 * @param WP_Site $new_site New site object.
 */
add_action( 'wp_initialize_site', function( $new_site ) {
    if ( ! is_plugin_active_for_network( plugin_basename( __FILE__ ) ) ) {
        return;
    }

    switch_to_blog( $new_site->blog_id );
    require_once GLIMMR_AI_PLUGIN_DIR . 'includes/class-glimmr-ai-activator.php';
    Glimmr_AI_Activator::activate();
    restore_current_blog();
}, 100 );

/**
 * Display OpenSSL missing warning.
 *
 * S17: Security Warning - Alert admins when API key encryption is weakened.
 */
function glimmr_ai_openssl_missing_notice() {
    // Only show to administrators.
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    ?>
    <div class="notice notice-warning is-dismissible">
        <p>
            <strong><?php esc_html_e( 'Glimmr AI:', 'glimmr-ai' ); ?></strong>
            <?php esc_html_e( 'OpenSSL extension not available. API key encryption will use weak obfuscation. Please enable OpenSSL for stronger security.', 'glimmr-ai' ); ?>
        </p>
    </div>
    <?php
}

/**
 * Begins execution of the plugin.
 *
 * @since 1.0.0
 */
function glimmr_ai_init() {
    // Check WooCommerce dependency.
    if ( ! glimmr_ai_is_woocommerce_active() ) {
        add_action( 'admin_notices', 'glimmr_ai_woocommerce_missing_notice' );
        return;
    }

    // S17: Check for OpenSSL and warn if not available.
    if ( ! function_exists( 'openssl_encrypt' ) ) {
        add_action( 'admin_notices', 'glimmr_ai_openssl_missing_notice' );
    }

    // Load the main plugin class.
    require_once GLIMMR_AI_PLUGIN_DIR . 'includes/class-glimmr-ai.php';

    // Initialize the plugin.
    $plugin = new Glimmr_AI();
    $plugin->run();
}
add_action( 'plugins_loaded', 'glimmr_ai_init' );

/**
 * Declare HPOS compatibility for WooCommerce.
 */
add_action( 'before_woocommerce_init', function() {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
} );
