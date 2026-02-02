<?php
/**
 * Settings admin page.
 *
 * @package Glimmr_Licensing
 * @since   1.0.0
 *
 * @var array $settings Current settings.
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}
?>
<div class="wrap">
    <h1><?php esc_html_e( 'Licensing Settings', 'glimmr-licensing' ); ?></h1>

    <form method="post" class="glimmr-settings-form">
        <?php wp_nonce_field( 'glimmr_licensing_settings' ); ?>

        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="rate_limit_per_minute"><?php esc_html_e( 'API Rate Limit', 'glimmr-licensing' ); ?></label>
                </th>
                <td>
                    <input type="number" id="rate_limit_per_minute" name="rate_limit_per_minute"
                           value="<?php echo esc_attr( $settings['rate_limit_per_minute'] ?? 60 ); ?>"
                           min="10" max="600" class="small-text" />
                    <p class="description"><?php esc_html_e( 'Maximum API requests per minute per IP address.', 'glimmr-licensing' ); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Email Notifications', 'glimmr-licensing' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="auto_email_license" value="1"
                            <?php checked( ! empty( $settings['auto_email_license'] ) ); ?> />
                        <?php esc_html_e( 'Automatically email license key to customer on purchase.', 'glimmr-licensing' ); ?>
                    </label>
                </td>
            </tr>
        </table>

        <div class="glimmr-card" style="margin-top: 20px;">
            <h3><?php esc_html_e( 'API Information', 'glimmr-licensing' ); ?></h3>
            <dl class="glimmr-detail-grid">
                <dt><?php esc_html_e( 'API Base URL', 'glimmr-licensing' ); ?></dt>
                <dd><code><?php echo esc_html( rest_url( 'glimmr-licensing/v1/' ) ); ?></code></dd>

                <dt><?php esc_html_e( 'Endpoints', 'glimmr-licensing' ); ?></dt>
                <dd>
                    <code>POST /activate</code><br>
                    <code>POST /deactivate</code><br>
                    <code>POST /validate</code><br>
                    <code>GET /ping</code>
                </dd>

                <dt><?php esc_html_e( 'Plugin Version', 'glimmr-licensing' ); ?></dt>
                <dd><?php echo esc_html( GLIMMR_LICENSING_VERSION ); ?></dd>

                <dt><?php esc_html_e( 'Database Version', 'glimmr-licensing' ); ?></dt>
                <dd><?php echo esc_html( get_option( 'glimmr_licensing_db_version', 'N/A' ) ); ?></dd>
            </dl>
        </div>

        <p class="submit">
            <input type="submit" name="glimmr_licensing_save_settings" class="button button-primary"
                   value="<?php esc_attr_e( 'Save Settings', 'glimmr-licensing' ); ?>" />
        </p>
    </form>
</div>
