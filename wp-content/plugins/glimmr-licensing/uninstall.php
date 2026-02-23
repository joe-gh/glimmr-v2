<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * Drops all database tables and removes options created by the plugin.
 *
 * @package Glimmr_Licensing
 * @since   1.0.0
 */

// If uninstall not called from WordPress, abort.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    die;
}

// Drop database tables.
global $wpdb;

// phpcs:disable WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}glimmr_license_logs" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}glimmr_activations" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}glimmr_licenses" );
// phpcs:enable

// Remove options.
delete_option( 'glimmr_licensing_db_version' );
delete_option( 'glimmr_licensing_settings' );
delete_option( 'glimmr_licensing_dev_keys' );
delete_option( 'glimmr_licensing_products_seeded' );
delete_option( 'glimmr_licensing_needs_product_seed' );

// Clean up transients.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_glimmr_rate_%' OR option_name LIKE '_transient_timeout_glimmr_rate_%'" );

// Clear scheduled events.
wp_clear_scheduled_hook( 'glimmr_licensing_daily_check' );
