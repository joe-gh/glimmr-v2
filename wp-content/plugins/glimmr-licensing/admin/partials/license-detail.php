<?php
/**
 * License detail admin page.
 *
 * @package Glimmr_Licensing
 * @since   1.0.0
 *
 * @var object $license     License row.
 * @var array  $activations Activation rows.
 * @var array  $logs        Log entries.
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

$nonce = wp_create_nonce( 'glimmr_licensing_admin' );
?>
<div class="wrap">
    <h1>
        <?php esc_html_e( 'License Detail', 'glimmr-licensing' ); ?>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=glimmr-licensing-licenses' ) ); ?>" class="page-title-action">
            <?php esc_html_e( 'Back to Licenses', 'glimmr-licensing' ); ?>
        </a>
    </h1>

    <!-- License Info -->
    <div class="glimmr-card">
        <h3><?php esc_html_e( 'License Information', 'glimmr-licensing' ); ?></h3>
        <dl class="glimmr-detail-grid">
            <dt><?php esc_html_e( 'License Key', 'glimmr-licensing' ); ?></dt>
            <dd>
                <code style="font-size: 14px;"><?php echo esc_html( $license->license_key ); ?></code>
                <button type="button" class="glimmr-copy-btn" onclick="navigator.clipboard.writeText('<?php echo esc_js( $license->license_key ); ?>')">
                    <?php esc_html_e( 'Copy', 'glimmr-licensing' ); ?>
                </button>
            </dd>

            <dt><?php esc_html_e( 'Status', 'glimmr-licensing' ); ?></dt>
            <dd><?php echo Glimmr_Licensing_Admin::status_badge( $license->status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></dd>

            <dt><?php esc_html_e( 'Customer', 'glimmr-licensing' ); ?></dt>
            <dd><?php echo esc_html( $license->customer_name ); ?> (<?php echo esc_html( $license->customer_email ); ?>)</dd>

            <dt><?php esc_html_e( 'Plan', 'glimmr-licensing' ); ?></dt>
            <dd><?php echo esc_html( Glimmr_Licensing_Admin::plan_label( $license->plan ) ); ?></dd>

            <dt><?php esc_html_e( 'Site Limit', 'glimmr-licensing' ); ?></dt>
            <dd><?php echo (int) $license->site_limit === 0 ? esc_html__( 'Unlimited', 'glimmr-licensing' ) : esc_html( $license->site_limit ); ?></dd>

            <?php if ( $license->order_id ) : ?>
                <dt><?php esc_html_e( 'Order', 'glimmr-licensing' ); ?></dt>
                <dd>
                    <?php
                    // HPOS-compatible order URL.
                    $order_obj = wc_get_order( $license->order_id );
                    $order_url = $order_obj ? $order_obj->get_edit_order_url() : admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $license->order_id );
                    ?>
                    <a href="<?php echo esc_url( $order_url ); ?>">
                        #<?php echo esc_html( $license->order_id ); ?>
                    </a>
                </dd>
            <?php endif; ?>

            <?php if ( $license->subscription_id ) : ?>
                <dt><?php esc_html_e( 'Subscription', 'glimmr-licensing' ); ?></dt>
                <dd>
                    <?php
                    // Subscriptions may still use CPT or HPOS — try the object URL first.
                    $sub_url = admin_url( 'post.php?post=' . $license->subscription_id . '&action=edit' );
                    if ( function_exists( 'wcs_get_subscription' ) ) {
                        $sub_obj = wcs_get_subscription( $license->subscription_id );
                        if ( $sub_obj && method_exists( $sub_obj, 'get_edit_order_url' ) ) {
                            $sub_url = $sub_obj->get_edit_order_url();
                        }
                    }
                    ?>
                    <a href="<?php echo esc_url( $sub_url ); ?>">
                        #<?php echo esc_html( $license->subscription_id ); ?>
                    </a>
                </dd>
            <?php endif; ?>

            <dt><?php esc_html_e( 'Expiry', 'glimmr-licensing' ); ?></dt>
            <dd>
                <?php
                echo $license->expiry_date
                    ? esc_html( wp_date( 'F j, Y g:i A', strtotime( $license->expiry_date ) ) )
                    : '<em>' . esc_html__( 'Lifetime (no expiry)', 'glimmr-licensing' ) . '</em>';
                ?>
            </dd>

            <dt><?php esc_html_e( 'Created', 'glimmr-licensing' ); ?></dt>
            <dd><?php echo esc_html( wp_date( 'F j, Y g:i A', strtotime( $license->created_at ) ) ); ?></dd>
        </dl>

        <div style="margin-top: 16px;" class="glimmr-actions">
            <?php if ( 'active' === $license->status ) : ?>
                <button class="button" id="btn-suspend" data-id="<?php echo esc_attr( $license->id ); ?>">
                    <?php esc_html_e( 'Suspend License', 'glimmr-licensing' ); ?>
                </button>
            <?php elseif ( in_array( $license->status, array( 'suspended', 'expired', 'cancelled' ), true ) ) : ?>
                <button class="button button-primary" id="btn-reactivate" data-id="<?php echo esc_attr( $license->id ); ?>">
                    <?php esc_html_e( 'Reactivate License', 'glimmr-licensing' ); ?>
                </button>
            <?php endif; ?>
            <button class="button" id="btn-delete" data-id="<?php echo esc_attr( $license->id ); ?>" style="color: #b32d2e; border-color: #b32d2e;">
                <?php esc_html_e( 'Delete License', 'glimmr-licensing' ); ?>
            </button>
        </div>
    </div>

    <!-- Edit License -->
    <div class="glimmr-card">
        <h3><?php esc_html_e( 'Edit License', 'glimmr-licensing' ); ?></h3>
        <div class="glimmr-form-row">
            <div class="glimmr-field">
                <label for="edit-name"><?php esc_html_e( 'Customer Name', 'glimmr-licensing' ); ?></label>
                <input type="text" id="edit-name" value="<?php echo esc_attr( $license->customer_name ); ?>" />
            </div>
            <div class="glimmr-field">
                <label for="edit-email"><?php esc_html_e( 'Customer Email', 'glimmr-licensing' ); ?></label>
                <input type="email" id="edit-email" value="<?php echo esc_attr( $license->customer_email ); ?>" />
            </div>
            <div class="glimmr-field">
                <label for="edit-plan"><?php esc_html_e( 'Plan', 'glimmr-licensing' ); ?></label>
                <select id="edit-plan">
                    <option value="plan_1" <?php selected( $license->plan, 'plan_1' ); ?>><?php esc_html_e( '1 Site', 'glimmr-licensing' ); ?></option>
                    <option value="plan_10" <?php selected( $license->plan, 'plan_10' ); ?>><?php esc_html_e( '10 Sites', 'glimmr-licensing' ); ?></option>
                    <option value="plan_100" <?php selected( $license->plan, 'plan_100' ); ?>><?php esc_html_e( '100 Sites', 'glimmr-licensing' ); ?></option>
                    <option value="plan_unlimited" <?php selected( $license->plan, 'plan_unlimited' ); ?>><?php esc_html_e( 'Unlimited Sites', 'glimmr-licensing' ); ?></option>
                </select>
            </div>
            <div class="glimmr-field">
                <label for="edit-site-limit"><?php esc_html_e( 'Site Limit Override', 'glimmr-licensing' ); ?></label>
                <input type="number" id="edit-site-limit" min="0" value="<?php echo esc_attr( $license->site_limit ); ?>" />
                <p class="description"><?php esc_html_e( '0 = unlimited. Leave as-is to use plan default.', 'glimmr-licensing' ); ?></p>
            </div>
            <div class="glimmr-field">
                <label for="edit-expiry"><?php esc_html_e( 'Expiry Date', 'glimmr-licensing' ); ?></label>
                <input type="date" id="edit-expiry" value="<?php echo $license->expiry_date ? esc_attr( gmdate( 'Y-m-d', strtotime( $license->expiry_date ) ) ) : ''; ?>" />
                <p class="description"><?php esc_html_e( 'Leave empty for lifetime (no expiry).', 'glimmr-licensing' ); ?></p>
            </div>
        </div>
        <div style="margin-top: 12px;">
            <button type="button" class="button button-primary" id="btn-save-license" data-id="<?php echo esc_attr( $license->id ); ?>">
                <?php esc_html_e( 'Save Changes', 'glimmr-licensing' ); ?>
            </button>
            <span id="edit-message" style="margin-left: 12px;"></span>
        </div>
    </div>

    <!-- Activations -->
    <div class="glimmr-card">
        <h3><?php esc_html_e( 'Active Sites', 'glimmr-licensing' ); ?></h3>

        <?php if ( empty( $activations ) ) : ?>
            <p><?php esc_html_e( 'No activations yet.', 'glimmr-licensing' ); ?></p>
        <?php else : ?>
            <table class="glimmr-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Site URL', 'glimmr-licensing' ); ?></th>
                        <th><?php esc_html_e( 'Site Name', 'glimmr-licensing' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'glimmr-licensing' ); ?></th>
                        <th><?php esc_html_e( 'Activated', 'glimmr-licensing' ); ?></th>
                        <th><?php esc_html_e( 'Last Validated', 'glimmr-licensing' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'glimmr-licensing' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $activations as $act ) : ?>
                        <tr>
                            <td><code><?php echo esc_html( $act->site_url ); ?></code></td>
                            <td><?php echo esc_html( $act->site_name ); ?></td>
                            <td><?php echo Glimmr_Licensing_Admin::status_badge( $act->status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
                            <td><?php echo esc_html( wp_date( 'M j, Y', strtotime( $act->activated_at ) ) ); ?></td>
                            <td><?php echo esc_html( wp_date( 'M j, Y g:i A', strtotime( $act->last_validated_at ) ) ); ?></td>
                            <td>
                                <?php if ( 'active' === $act->status ) : ?>
                                    <button class="button button-small btn-deactivate-site" data-id="<?php echo esc_attr( $act->id ); ?>">
                                        <?php esc_html_e( 'Deactivate', 'glimmr-licensing' ); ?>
                                    </button>
                                <?php else : ?>
                                    <em><?php esc_html_e( 'Deactivated', 'glimmr-licensing' ); ?></em>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- Activity Log -->
    <div class="glimmr-card">
        <h3><?php esc_html_e( 'Activity Log', 'glimmr-licensing' ); ?></h3>

        <?php if ( empty( $logs ) ) : ?>
            <p><?php esc_html_e( 'No activity yet.', 'glimmr-licensing' ); ?></p>
        <?php else : ?>
            <div class="glimmr-log-list">
                <?php foreach ( $logs as $log ) : ?>
                    <div class="glimmr-log-entry">
                        <span class="glimmr-log-time"><?php echo esc_html( wp_date( 'M j, Y g:i A', strtotime( $log->created_at ) ) ); ?></span>
                        <span class="glimmr-log-action"><?php echo esc_html( ucfirst( str_replace( '_', ' ', $log->action ) ) ); ?></span>
                        <span class="glimmr-log-details">
                            <?php
                            $details = json_decode( $log->details, true );
                            if ( ! empty( $details['site_url'] ) ) {
                                echo esc_html( $details['site_url'] );
                            }
                            if ( ! empty( $log->ip_address ) ) {
                                echo ' &middot; ' . esc_html( $log->ip_address );
                            }
                            ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
(function() {
    var nonce = '<?php echo esc_js( $nonce ); ?>';

    var suspendBtn = document.getElementById('btn-suspend');
    var reactivateBtn = document.getElementById('btn-reactivate');
    var deleteBtn = document.getElementById('btn-delete');

    if (suspendBtn) {
        suspendBtn.addEventListener('click', function() { updateStatus(this.dataset.id, 'suspended'); });
    }
    if (reactivateBtn) {
        reactivateBtn.addEventListener('click', function() { updateStatus(this.dataset.id, 'active'); });
    }
    if (deleteBtn) {
        deleteBtn.addEventListener('click', function() {
            if (!confirm('<?php echo esc_js( __( 'Delete this license and all its activations?', 'glimmr-licensing' ) ); ?>')) return;
            var data = new FormData();
            data.append('action', 'glimmr_licensing_delete_license');
            data.append('nonce', nonce);
            data.append('license_id', this.dataset.id);
            fetch(ajaxurl, { method: 'POST', body: data, credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(result) {
                    if (result.success) {
                        window.location.href = '<?php echo esc_js( admin_url( 'admin.php?page=glimmr-licensing-licenses' ) ); ?>';
                    } else {
                        alert(result.data && result.data.message ? result.data.message : '<?php echo esc_js( __( 'Failed to delete license.', 'glimmr-licensing' ) ); ?>');
                    }
                })
                .catch(function() { alert('<?php echo esc_js( __( 'Network error. Please try again.', 'glimmr-licensing' ) ); ?>'); });
        });
    }

    // Save license edits.
    var saveBtn = document.getElementById('btn-save-license');
    if (saveBtn) {
        saveBtn.addEventListener('click', function() {
            var btn = this;
            var msg = document.getElementById('edit-message');
            btn.disabled = true;
            msg.textContent = '';

            var data = new FormData();
            data.append('action', 'glimmr_licensing_update_license');
            data.append('nonce', nonce);
            data.append('license_id', btn.dataset.id);
            data.append('customer_name', document.getElementById('edit-name').value);
            data.append('customer_email', document.getElementById('edit-email').value);
            data.append('plan', document.getElementById('edit-plan').value);
            data.append('site_limit', document.getElementById('edit-site-limit').value);
            data.append('expiry_date', document.getElementById('edit-expiry').value);

            fetch(ajaxurl, { method: 'POST', body: data, credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(result) {
                    btn.disabled = false;
                    if (result.success) {
                        msg.style.color = '#0e6027';
                        msg.textContent = result.data.message || '<?php echo esc_js( __( 'License updated.', 'glimmr-licensing' ) ); ?>';
                        setTimeout(function() { location.reload(); }, 1500);
                    } else {
                        msg.style.color = '#8a1116';
                        msg.textContent = result.data && result.data.message ? result.data.message : '<?php echo esc_js( __( 'Failed to update license.', 'glimmr-licensing' ) ); ?>';
                    }
                })
                .catch(function() {
                    btn.disabled = false;
                    msg.style.color = '#8a1116';
                    msg.textContent = '<?php echo esc_js( __( 'Network error. Please try again.', 'glimmr-licensing' ) ); ?>';
                });
        });
    }

    document.querySelectorAll('.btn-deactivate-site').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var data = new FormData();
            data.append('action', 'glimmr_licensing_deactivate_site');
            data.append('nonce', nonce);
            data.append('activation_id', this.dataset.id);
            fetch(ajaxurl, { method: 'POST', body: data, credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(result) {
                    if (result.success) {
                        location.reload();
                    } else {
                        alert(result.data && result.data.message ? result.data.message : '<?php echo esc_js( __( 'Failed to deactivate site.', 'glimmr-licensing' ) ); ?>');
                    }
                })
                .catch(function() { alert('<?php echo esc_js( __( 'Network error. Please try again.', 'glimmr-licensing' ) ); ?>'); });
        });
    });

    function updateStatus(id, status) {
        var data = new FormData();
        data.append('action', 'glimmr_licensing_update_status');
        data.append('nonce', nonce);
        data.append('license_id', id);
        data.append('status', status);
        fetch(ajaxurl, { method: 'POST', body: data, credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(result) {
                if (result.success) {
                    location.reload();
                } else {
                    alert(result.data && result.data.message ? result.data.message : '<?php echo esc_js( __( 'Failed to update status.', 'glimmr-licensing' ) ); ?>');
                }
            })
            .catch(function() { alert('<?php echo esc_js( __( 'Network error. Please try again.', 'glimmr-licensing' ) ); ?>'); });
    }
})();
</script>
