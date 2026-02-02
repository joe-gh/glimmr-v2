<?php
/**
 * Dashboard admin page.
 *
 * @package Glimmr_Licensing
 * @since   1.0.0
 *
 * @var array $stats  Stats from Glimmr_Licensing_Manager::get_stats().
 * @var array $recent Recent licenses result array.
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

    <div class="glimmr-card">
        <h3><?php esc_html_e( 'Recent Licenses', 'glimmr-licensing' ); ?></h3>

        <?php if ( empty( $recent['items'] ) ) : ?>
            <p><?php esc_html_e( 'No licenses yet.', 'glimmr-licensing' ); ?></p>
        <?php else : ?>
            <table class="glimmr-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Key', 'glimmr-licensing' ); ?></th>
                        <th><?php esc_html_e( 'Customer', 'glimmr-licensing' ); ?></th>
                        <th><?php esc_html_e( 'Plan', 'glimmr-licensing' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'glimmr-licensing' ); ?></th>
                        <th><?php esc_html_e( 'Sites', 'glimmr-licensing' ); ?></th>
                        <th><?php esc_html_e( 'Created', 'glimmr-licensing' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $recent['items'] as $license ) : ?>
                        <tr>
                            <td>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=glimmr-licensing-licenses&license_id=' . $license->id ) ); ?>">
                                    <code><?php echo esc_html( Glimmr_Licensing_Admin::mask_key( $license->license_key ) ); ?></code>
                                </a>
                            </td>
                            <td><?php echo esc_html( $license->customer_email ); ?></td>
                            <td><?php echo esc_html( Glimmr_Licensing_Admin::plan_label( $license->plan ) ); ?></td>
                            <td><?php echo Glimmr_Licensing_Admin::status_badge( $license->status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
                            <td>
                                <?php
                                $sites = isset( $license->active_sites ) ? $license->active_sites : 0;
                                if ( (int) $license->site_limit === 0 ) {
                                    echo esc_html( $sites ) . ' / &infin;';
                                } else {
                                    echo esc_html( $sites ) . ' / ' . esc_html( $license->site_limit );
                                }
                                ?>
                            </td>
                            <td><?php echo esc_html( wp_date( 'M j, Y', strtotime( $license->created_at ) ) ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
