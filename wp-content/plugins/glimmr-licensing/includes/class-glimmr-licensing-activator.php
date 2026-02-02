<?php
/**
 * Plugin activator for Glimmr Licensing.
 *
 * @package Glimmr_Licensing
 * @since   1.0.0
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Class Glimmr_Licensing_Activator
 *
 * Creates database tables and sets default options on activation.
 */
class Glimmr_Licensing_Activator {

    /**
     * Run activation tasks.
     *
     * @return void
     */
    public static function activate() {
        require_once GLIMMR_LICENSING_PLUGIN_DIR . 'includes/class-glimmr-licensing-database.php';
        Glimmr_Licensing_Database::create_tables();

        // Set default options.
        if ( false === get_option( 'glimmr_licensing_settings' ) ) {
            update_option( 'glimmr_licensing_settings', array(
                'rate_limit_per_minute' => 60,
                'auto_email_license'    => true,
            ) );
        }

        // Schedule daily cron.
        if ( ! wp_next_scheduled( 'glimmr_licensing_daily_check' ) ) {
            wp_schedule_event( time(), 'daily', 'glimmr_licensing_daily_check' );
        }

        // Register the My Account endpoint and flush rewrite rules so it works immediately.
        add_rewrite_endpoint( 'licenses', EP_ROOT | EP_PAGES );
        flush_rewrite_rules();
    }
}
