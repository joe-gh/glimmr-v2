<?php
/**
 * Fired during plugin deactivation.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Class Glimmr_AI_Deactivator
 *
 * This class defines all code necessary to run during the plugin's deactivation.
 */
class Glimmr_AI_Deactivator {

    /**
     * Deactivate the plugin.
     *
     * Clears scheduled cron jobs and cleans up transients.
     * Note: Database tables and options are NOT deleted on deactivation.
     * Use uninstall.php for complete cleanup.
     *
     * @since 1.0.0
     * @return void
     */
    public static function deactivate() {
        // Clear scheduled cron jobs.
        self::clear_cron_jobs();

        // Clear transients.
        self::clear_transients();

        // Flush rewrite rules.
        flush_rewrite_rules();
    }

    /**
     * Clear all scheduled cron jobs.
     *
     * @since 1.0.0
     * @return void
     */
    private static function clear_cron_jobs() {
        // Load required classes for cron unschedule.
        require_once GLIMMR_AI_PLUGIN_DIR . 'includes/class-glimmr-ai-settings.php';
        require_once GLIMMR_AI_PLUGIN_DIR . 'includes/class-glimmr-ai-cron.php';

        // Use the Cron class to unschedule events.
        $settings = new Glimmr_AI_Settings();
        $cron = new Glimmr_AI_Cron( $settings );
        $cron->unschedule_events();

        // Also clear any legacy hook names (for backwards compatibility).
        $legacy_hooks = array(
            'glimmr_ai_product_sync',
            'glimmr_ai_knowledge_sync',
            'glimmr_ai_cleanup',
            'glimmr_ai_expire_conversations',
        );

        foreach ( $legacy_hooks as $hook ) {
            wp_clear_scheduled_hook( $hook );
        }
    }

    /**
     * Clear plugin transients.
     *
     * @since 1.0.0
     * @return void
     */
    private static function clear_transients() {
        global $wpdb;

        // Delete all transients with our prefix.
        $wpdb->query(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_glimmr_ai_%'"
        );
        $wpdb->query(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_glimmr_ai_%'"
        );

        // For multisite.
        if ( is_multisite() ) {
            $wpdb->query(
                "DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE '_site_transient_glimmr_ai_%'"
            );
            $wpdb->query(
                "DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE '_site_transient_timeout_glimmr_ai_%'"
            );
        }
    }
}
