<?php
/**
 * Plugin Name: Glimmr Licensing
 * Plugin URI: https://glimmr.us
 * Description: License management server for Glimmr plugins. Manages license keys, activation limits, and integrates with WooCommerce for automatic license generation on purchase.
 * Version: 1.0.0
 * Author: Joseph DiGiovanna - Vimpact Consulting LLC
 * Author URI: mailto:joseph.p.digiovanna@gmail.com
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: glimmr-licensing
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * WC requires at least: 8.0
 * WC tested up to: 9.0
 *
 * @package Glimmr_Licensing
 * @author Joseph DiGiovanna <joseph.p.digiovanna@gmail.com>
 * @copyright 2026 Vimpact Consulting LLC
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Current plugin version.
 */
define( 'GLIMMR_LICENSING_VERSION', '1.0.0' );

/**
 * Plugin base path.
 */
define( 'GLIMMR_LICENSING_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Plugin base URL.
 */
define( 'GLIMMR_LICENSING_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Plugin basename.
 */
define( 'GLIMMR_LICENSING_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * The code that runs during plugin activation.
 */
function glimmr_licensing_activate() {
    require_once GLIMMR_LICENSING_PLUGIN_DIR . 'includes/class-glimmr-licensing-activator.php';
    Glimmr_Licensing_Activator::activate();
}

/**
 * The code that runs during plugin deactivation.
 */
function glimmr_licensing_deactivate() {
    require_once GLIMMR_LICENSING_PLUGIN_DIR . 'includes/class-glimmr-licensing-deactivator.php';
    Glimmr_Licensing_Deactivator::deactivate();
}

register_activation_hook( __FILE__, 'glimmr_licensing_activate' );
register_deactivation_hook( __FILE__, 'glimmr_licensing_deactivate' );

/**
 * Load the main plugin class.
 */
require_once GLIMMR_LICENSING_PLUGIN_DIR . 'includes/class-glimmr-licensing.php';

/**
 * Begin execution of the plugin.
 */
function glimmr_licensing_init() {
    $plugin = Glimmr_Licensing::get_instance();
    $plugin->run();
}
add_action( 'plugins_loaded', 'glimmr_licensing_init' );

/**
 * Declare HPOS compatibility for WooCommerce.
 */
add_action( 'before_woocommerce_init', function() {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
} );
