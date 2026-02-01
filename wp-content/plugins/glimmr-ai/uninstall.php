<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

// If uninstall not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

/**
 * Clean up all plugin data on uninstall.
 */
function glimmr_ai_uninstall() {
    global $wpdb;

    // Check if we should delete all data.
    // You might want to add an option to preserve data on uninstall.
    $delete_data = apply_filters( 'glimmr_ai_delete_data_on_uninstall', true );

    if ( ! $delete_data ) {
        return;
    }

    // Delete options.
    delete_option( 'glimmr_ai_settings' );
    delete_option( 'glimmr_ai_db_version' );

    // Delete network options for multisite.
    if ( is_multisite() ) {
        delete_site_option( 'glimmr_ai_network_settings' );

        // Delete options from all sites in the network.
        $sites = get_sites( array( 'number' => 0 ) );
        foreach ( $sites as $site ) {
            switch_to_blog( $site->blog_id );
            delete_option( 'glimmr_ai_settings' );
            delete_option( 'glimmr_ai_db_version' );
            glimmr_ai_drop_tables();
            restore_current_blog();
        }
    } else {
        // Drop tables for single site.
        glimmr_ai_drop_tables();
    }

    // Clear scheduled cron jobs.
    $cron_hooks = array(
        'glimmr_ai_product_sync',
        'glimmr_ai_knowledge_sync',
        'glimmr_ai_cleanup',
        'glimmr_ai_expire_conversations',
    );

    foreach ( $cron_hooks as $hook ) {
        wp_clear_scheduled_hook( $hook );
    }

    // Clear transients.
    $wpdb->query(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_glimmr_ai_%'"
    );
    $wpdb->query(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_glimmr_ai_%'"
    );

    // For multisite, also clear sitemeta.
    if ( is_multisite() ) {
        $wpdb->query(
            "DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE '_site_transient_glimmr_ai_%'"
        );
        $wpdb->query(
            "DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE '_site_transient_timeout_glimmr_ai_%'"
        );
    }

    // Clear any cached data.
    wp_cache_flush();
}

/**
 * Drop all plugin database tables.
 */
function glimmr_ai_drop_tables() {
    global $wpdb;

    $table_prefix = $wpdb->prefix . 'glimmr_ai_';

    $tables = array(
        'messages',
        'flagged_issues',
        'analytics',
        'knowledge',
        'rate_limits',
        'product_index',
        'sync_log',
        'conversations', // Drop last due to foreign key constraints.
    );

    foreach ( $tables as $table ) {
        $table_name = $table_prefix . $table;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );
    }
}

// Run uninstall.
glimmr_ai_uninstall();
