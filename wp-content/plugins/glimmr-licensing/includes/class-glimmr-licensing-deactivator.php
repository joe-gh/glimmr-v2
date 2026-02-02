<?php
/**
 * Plugin deactivator for Glimmr Licensing.
 *
 * @package Glimmr_Licensing
 * @since   1.0.0
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Class Glimmr_Licensing_Deactivator
 *
 * Cleans up scheduled events on deactivation. Does NOT drop tables.
 */
class Glimmr_Licensing_Deactivator {

    /**
     * Run deactivation tasks.
     *
     * @return void
     */
    public static function deactivate() {
        wp_clear_scheduled_hook( 'glimmr_licensing_daily_check' );

        // Flush rewrite rules to remove the My Account "licenses" endpoint.
        flush_rewrite_rules();
    }
}
