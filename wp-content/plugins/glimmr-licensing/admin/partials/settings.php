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

    <?php
    $dev_keys = Glimmr_Licensing_Admin::get_dev_keys();
    ?>
    <div class="glimmr-card" style="margin-top: 24px;">
        <h3><?php esc_html_e( 'Development Keys', 'glimmr-licensing' ); ?></h3>
        <p class="description" style="margin-bottom: 16px;">
            <?php esc_html_e( 'Development keys are permanent, free license keys for your own dev/staging sites. They bypass WooCommerce entirely.', 'glimmr-licensing' ); ?>
        </p>

        <table class="glimmr-table" id="dev-keys-table" <?php if ( empty( $dev_keys ) ) echo 'style="display:none;"'; ?>>
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Label', 'glimmr-licensing' ); ?></th>
                    <th><?php esc_html_e( 'License Key', 'glimmr-licensing' ); ?></th>
                    <th><?php esc_html_e( 'Created', 'glimmr-licensing' ); ?></th>
                    <th style="width: 100px;"><?php esc_html_e( 'Actions', 'glimmr-licensing' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $dev_keys as $key => $data ) : ?>
                <tr data-key="<?php echo esc_attr( $key ); ?>">
                    <td><?php echo esc_html( $data['label'] ); ?></td>
                    <td>
                        <code><?php echo esc_html( $key ); ?></code>
                        <button type="button" class="glimmr-copy-btn" data-copy="<?php echo esc_attr( $key ); ?>">
                            <?php esc_html_e( 'Copy', 'glimmr-licensing' ); ?>
                        </button>
                    </td>
                    <td><?php echo esc_html( $data['created_at'] ?? '' ); ?></td>
                    <td>
                        <button type="button" class="button button-small glimmr-delete-dev-key" data-key="<?php echo esc_attr( $key ); ?>">
                            <?php esc_html_e( 'Delete', 'glimmr-licensing' ); ?>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <p id="dev-keys-empty" <?php if ( ! empty( $dev_keys ) ) echo 'style="display:none;"'; ?>>
            <?php esc_html_e( 'No development keys yet.', 'glimmr-licensing' ); ?>
        </p>

        <div style="margin-top: 16px; display: flex; gap: 10px; align-items: end; flex-wrap: wrap;">
            <div>
                <label for="dev-key-label" style="display: block; font-weight: 600; font-size: 13px; margin-bottom: 4px;">
                    <?php esc_html_e( 'Label', 'glimmr-licensing' ); ?>
                </label>
                <input type="text" id="dev-key-label" placeholder="<?php esc_attr_e( 'e.g. Local Dev, Staging', 'glimmr-licensing' ); ?>"
                       style="min-width: 250px; padding: 6px 10px; border: 1px solid #8c8f94; border-radius: 4px; font-size: 13px;" />
            </div>
            <button type="button" class="button button-secondary" id="add-dev-key">
                <?php esc_html_e( 'Generate Development Key', 'glimmr-licensing' ); ?>
            </button>
        </div>
    </div>

    <script>
    jQuery(function($) {
        var nonce = '<?php echo esc_js( wp_create_nonce( 'glimmr_licensing_admin' ) ); ?>';

        // Copy to clipboard with fallback for non-HTTPS (local dev).
        function copyText(text) {
            if (navigator.clipboard && window.isSecureContext) {
                return navigator.clipboard.writeText(text);
            }
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.left = '-9999px';
            document.body.appendChild(ta);
            ta.select();
            try {
                document.execCommand('copy');
            } catch (e) {}
            document.body.removeChild(ta);
            return Promise.resolve();
        }

        // Copy button.
        $(document).on('click', '.glimmr-copy-btn', function() {
            var text = $(this).data('copy');
            var btn = $(this);
            copyText(text).then(function() {
                var original = btn.text();
                btn.text('<?php echo esc_js( __( 'Copied!', 'glimmr-licensing' ) ); ?>').css('color', '#0e6027');
                setTimeout(function() { btn.text(original).css('color', ''); }, 2000);
            });
        });

        // Add dev key.
        $('#add-dev-key').on('click', function() {
            var label = $('#dev-key-label').val().trim();
            if (!label) {
                alert('<?php echo esc_js( __( 'Please enter a label.', 'glimmr-licensing' ) ); ?>');
                return;
            }

            var btn = $(this);
            btn.prop('disabled', true).text('<?php echo esc_js( __( 'Generating...', 'glimmr-licensing' ) ); ?>');

            $.post(ajaxurl, {
                action: 'glimmr_licensing_add_dev_key',
                nonce: nonce,
                label: label
            }, function(res) {
                btn.prop('disabled', false).text('<?php echo esc_js( __( 'Generate Development Key', 'glimmr-licensing' ) ); ?>');
                if (res.success) {
                    var row = '<tr data-key="' + res.data.key + '">' +
                        '<td>' + $('<span>').text(res.data.label).html() + '</td>' +
                        '<td><code>' + res.data.key + '</code> ' +
                        '<button type="button" class="glimmr-copy-btn" data-copy="' + res.data.key + '"><?php echo esc_js( __( 'Copy', 'glimmr-licensing' ) ); ?></button></td>' +
                        '<td><?php echo esc_js( current_time( 'mysql' ) ); ?></td>' +
                        '<td><button type="button" class="button button-small glimmr-delete-dev-key" data-key="' + res.data.key + '"><?php echo esc_js( __( 'Delete', 'glimmr-licensing' ) ); ?></button></td>' +
                        '</tr>';
                    $('#dev-keys-table tbody').append(row);
                    $('#dev-keys-table').show();
                    $('#dev-keys-empty').hide();
                    $('#dev-key-label').val('');
                } else {
                    alert(res.data.message || '<?php echo esc_js( __( 'Error creating key.', 'glimmr-licensing' ) ); ?>');
                }
            });
        });

        // Delete dev key.
        $(document).on('click', '.glimmr-delete-dev-key', function() {
            if (!confirm('<?php echo esc_js( __( 'Delete this development key? Any sites using it will lose access.', 'glimmr-licensing' ) ); ?>')) {
                return;
            }

            var key = $(this).data('key');
            var row = $(this).closest('tr');

            $.post(ajaxurl, {
                action: 'glimmr_licensing_delete_dev_key',
                nonce: nonce,
                dev_key: key
            }, function(res) {
                if (res.success) {
                    row.fadeOut(300, function() {
                        row.remove();
                        if ($('#dev-keys-table tbody tr').length === 0) {
                            $('#dev-keys-table').hide();
                            $('#dev-keys-empty').show();
                        }
                    });
                } else {
                    alert(res.data.message || '<?php echo esc_js( __( 'Error deleting key.', 'glimmr-licensing' ) ); ?>');
                }
            });
        });
    });
    </script>
</div>
