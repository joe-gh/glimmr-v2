<?php
/**
 * Dashboard admin page.
 *
 * @package Glimmr_Licensing
 * @since   1.0.0
 *
 * @var array $stats Stats from Glimmr_Licensing_Manager::get_stats().
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}
?>
<div class="wrap">
    <h1><?php esc_html_e( 'Glimmr Licensing Dashboard', 'glimmr-licensing' ); ?></h1>

    <div class="glimmr-stats">
        <div class="glimmr-stat-card">
            <div class="stat-value"><?php echo esc_html( number_format_i18n( $stats['total'] ) ); ?></div>
            <div class="stat-label"><?php esc_html_e( 'Total Licenses', 'glimmr-licensing' ); ?></div>
        </div>
        <div class="glimmr-stat-card">
            <div class="stat-value"><?php echo esc_html( number_format_i18n( $stats['active'] ) ); ?></div>
            <div class="stat-label"><?php esc_html_e( 'Active', 'glimmr-licensing' ); ?></div>
        </div>
        <div class="glimmr-stat-card">
            <div class="stat-value"><?php echo esc_html( number_format_i18n( $stats['expired'] ) ); ?></div>
            <div class="stat-label"><?php esc_html_e( 'Expired', 'glimmr-licensing' ); ?></div>
        </div>
        <div class="glimmr-stat-card">
            <div class="stat-value"><?php echo esc_html( number_format_i18n( $stats['suspended'] ) ); ?></div>
            <div class="stat-label"><?php esc_html_e( 'Suspended', 'glimmr-licensing' ); ?></div>
        </div>
        <div class="glimmr-stat-card">
            <div class="stat-value"><?php echo esc_html( number_format_i18n( $stats['total_activations'] ) ); ?></div>
            <div class="stat-label"><?php esc_html_e( 'Active Sites', 'glimmr-licensing' ); ?></div>
        </div>
    </div>

</div>
