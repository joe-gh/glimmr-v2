<?php
/**
 * My Account — Licenses tab template.
 *
 * @package Glimmr_Licensing
 * @since   1.0.0
 *
 * @var array $licenses License rows for the current user.
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

$plan_labels = array(
    'plan_1'         => __( '1 Site', 'glimmr-licensing' ),
    'plan_10'        => __( '10 Sites', 'glimmr-licensing' ),
    'plan_100'       => __( '100 Sites', 'glimmr-licensing' ),
    'plan_unlimited' => __( 'Unlimited Sites', 'glimmr-licensing' ),
);
?>

<?php if ( empty( $licenses ) ) : ?>
    <div class="woocommerce-info">
        <?php esc_html_e( 'You don\'t have any licenses yet.', 'glimmr-licensing' ); ?>
    </div>
<?php else : ?>
    <table class="woocommerce-orders-table woocommerce-MyAccount-orders shop_table shop_table_responsive my_account_orders account-orders-table">
        <thead>
            <tr>
                <th><?php esc_html_e( 'License Key', 'glimmr-licensing' ); ?></th>
                <th><?php esc_html_e( 'Plan', 'glimmr-licensing' ); ?></th>
                <th><?php esc_html_e( 'Status', 'glimmr-licensing' ); ?></th>
                <th><?php esc_html_e( 'Sites Used', 'glimmr-licensing' ); ?></th>
                <th><?php esc_html_e( 'Expiry', 'glimmr-licensing' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ( $licenses as $license ) : ?>
                <?php
                $manager       = new Glimmr_Licensing_Manager();
                $active_sites  = $manager->get_active_activation_count( $license->id );
                $masked_key    = substr( $license->license_key, 0, 4 ) . '-****-****-****-' . substr( $license->license_key, -4 );
                ?>
                <tr>
                    <td data-title="<?php esc_attr_e( 'License Key', 'glimmr-licensing' ); ?>">
                        <code><?php echo esc_html( $masked_key ); ?></code>
                        <button type="button" class="button button-small"
                                onclick="navigator.clipboard.writeText('<?php echo esc_js( $license->license_key ); ?>').then(function() { alert('Copied!'); });"
                                style="margin-left: 8px; font-size: 11px; padding: 0 8px; line-height: 1.8;">
                            <?php esc_html_e( 'Copy', 'glimmr-licensing' ); ?>
                        </button>
                    </td>
                    <td data-title="<?php esc_attr_e( 'Plan', 'glimmr-licensing' ); ?>">
                        <?php echo esc_html( $plan_labels[ $license->plan ] ?? $license->plan ); ?>
                    </td>
                    <td data-title="<?php esc_attr_e( 'Status', 'glimmr-licensing' ); ?>">
                        <?php
                        $status_labels = array(
                            'active'    => __( 'Active', 'glimmr-licensing' ),
                            'expired'   => __( 'Expired', 'glimmr-licensing' ),
                            'cancelled' => __( 'Cancelled', 'glimmr-licensing' ),
                            'suspended' => __( 'Suspended', 'glimmr-licensing' ),
                        );
                        echo esc_html( $status_labels[ $license->status ] ?? ucfirst( $license->status ) );
                        ?>
                    </td>
                    <td data-title="<?php esc_attr_e( 'Sites Used', 'glimmr-licensing' ); ?>">
                        <?php
                        $limit = (int) $license->site_limit === 0
                            ? __( 'Unlimited', 'glimmr-licensing' )
                            : $license->site_limit;
                        echo esc_html( $active_sites . ' / ' . $limit );
                        ?>
                    </td>
                    <td data-title="<?php esc_attr_e( 'Expiry', 'glimmr-licensing' ); ?>">
                        <?php
                        echo $license->expiry_date
                            ? esc_html( wp_date( 'F j, Y', strtotime( $license->expiry_date ) ) )
                            : esc_html__( 'Lifetime', 'glimmr-licensing' );
                        ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
