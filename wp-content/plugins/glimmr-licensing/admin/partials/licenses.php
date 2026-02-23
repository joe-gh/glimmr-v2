<?php
/**
 * Licenses list admin page.
 *
 * @package Glimmr_Licensing
 * @since   1.0.0
 *
 * @var array  $licenses    License rows.
 * @var int    $total       Total count.
 * @var int    $total_pages Total pages.
 * @var array  $args        Current filter args.
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

$current_page = $args['page'];
$nonce        = wp_create_nonce( 'glimmr_licensing_admin' );
?>
<div class="wrap">
    <h1 class="wp-heading-inline"><?php esc_html_e( 'Licenses', 'glimmr-licensing' ); ?></h1>
    <hr class="wp-header-end">

    <!-- Create License Form -->
    <div class="glimmr-create-form" id="glimmr-create-form">
        <h3><?php esc_html_e( 'Create License', 'glimmr-licensing' ); ?></h3>
        <div class="glimmr-form-row">
            <div class="glimmr-field">
                <label for="create-name"><?php esc_html_e( 'Customer Name', 'glimmr-licensing' ); ?></label>
                <input type="text" id="create-name" placeholder="<?php esc_attr_e( 'John Doe', 'glimmr-licensing' ); ?>" />
            </div>
            <div class="glimmr-field">
                <label for="create-email"><?php esc_html_e( 'Customer Email', 'glimmr-licensing' ); ?></label>
                <input type="email" id="create-email" placeholder="<?php esc_attr_e( 'john@example.com', 'glimmr-licensing' ); ?>" />
            </div>
            <div class="glimmr-field">
                <label for="create-plan"><?php esc_html_e( 'Plan', 'glimmr-licensing' ); ?></label>
                <select id="create-plan">
                    <option value="plan_1"><?php esc_html_e( '1 Site', 'glimmr-licensing' ); ?></option>
                    <option value="plan_10"><?php esc_html_e( '10 Sites', 'glimmr-licensing' ); ?></option>
                    <option value="plan_100"><?php esc_html_e( '100 Sites', 'glimmr-licensing' ); ?></option>
                    <option value="plan_unlimited"><?php esc_html_e( 'Unlimited Sites', 'glimmr-licensing' ); ?></option>
                </select>
            </div>
            <div class="glimmr-field">
                <label for="create-expiry"><?php esc_html_e( 'Expiry Date (optional)', 'glimmr-licensing' ); ?></label>
                <input type="date" id="create-expiry" />
            </div>
        </div>
        <button type="button" class="button button-primary" id="btn-create-license">
            <?php esc_html_e( 'Create License', 'glimmr-licensing' ); ?>
        </button>
        <span id="create-message" style="margin-left: 12px;"></span>
    </div>

    <!-- Filters -->
    <form method="get" class="glimmr-filters">
        <input type="hidden" name="page" value="glimmr-licensing-licenses" />
        <select name="status">
            <option value=""><?php esc_html_e( 'All Statuses', 'glimmr-licensing' ); ?></option>
            <option value="active" <?php selected( $args['status'], 'active' ); ?>><?php esc_html_e( 'Active', 'glimmr-licensing' ); ?></option>
            <option value="expired" <?php selected( $args['status'], 'expired' ); ?>><?php esc_html_e( 'Expired', 'glimmr-licensing' ); ?></option>
            <option value="suspended" <?php selected( $args['status'], 'suspended' ); ?>><?php esc_html_e( 'Suspended', 'glimmr-licensing' ); ?></option>
            <option value="cancelled" <?php selected( $args['status'], 'cancelled' ); ?>><?php esc_html_e( 'Cancelled', 'glimmr-licensing' ); ?></option>
        </select>
        <select name="plan">
            <option value=""><?php esc_html_e( 'All Plans', 'glimmr-licensing' ); ?></option>
            <option value="plan_1" <?php selected( $args['plan'], 'plan_1' ); ?>><?php esc_html_e( '1 Site', 'glimmr-licensing' ); ?></option>
            <option value="plan_10" <?php selected( $args['plan'], 'plan_10' ); ?>><?php esc_html_e( '10 Sites', 'glimmr-licensing' ); ?></option>
            <option value="plan_100" <?php selected( $args['plan'], 'plan_100' ); ?>><?php esc_html_e( '100 Sites', 'glimmr-licensing' ); ?></option>
            <option value="plan_unlimited" <?php selected( $args['plan'], 'plan_unlimited' ); ?>><?php esc_html_e( 'Unlimited Sites', 'glimmr-licensing' ); ?></option>
        </select>
        <input type="search" name="s" value="<?php echo esc_attr( $args['search'] ); ?>" placeholder="<?php esc_attr_e( 'Search email or key...', 'glimmr-licensing' ); ?>" />
        <button type="submit" class="button"><?php esc_html_e( 'Filter', 'glimmr-licensing' ); ?></button>
    </form>

    <!-- Licenses Table -->
    <?php if ( empty( $licenses ) ) : ?>
        <p><?php esc_html_e( 'No licenses found.', 'glimmr-licensing' ); ?></p>
    <?php else : ?>
        <!-- Bulk Actions -->
        <div class="glimmr-bulk-actions" id="bulk-actions" style="display: none; margin-bottom: 12px;">
            <span id="bulk-count"></span>
            <select id="bulk-action-select">
                <option value=""><?php esc_html_e( 'Bulk Actions', 'glimmr-licensing' ); ?></option>
                <option value="delete"><?php esc_html_e( 'Delete', 'glimmr-licensing' ); ?></option>
                <option value="suspend"><?php esc_html_e( 'Suspend', 'glimmr-licensing' ); ?></option>
                <option value="activate"><?php esc_html_e( 'Activate', 'glimmr-licensing' ); ?></option>
            </select>
            <button type="button" class="button" id="btn-bulk-apply"><?php esc_html_e( 'Apply', 'glimmr-licensing' ); ?></button>
        </div>

        <table class="glimmr-table">
            <thead>
                <tr>
                    <th style="width: 30px;"><input type="checkbox" id="cb-select-all" /></th>
                    <th><?php esc_html_e( 'Key', 'glimmr-licensing' ); ?></th>
                    <th><?php esc_html_e( 'Customer', 'glimmr-licensing' ); ?></th>
                    <th><?php esc_html_e( 'Plan', 'glimmr-licensing' ); ?></th>
                    <th><?php esc_html_e( 'Status', 'glimmr-licensing' ); ?></th>
                    <th><?php esc_html_e( 'Sites Used', 'glimmr-licensing' ); ?></th>
                    <th><?php esc_html_e( 'Expiry', 'glimmr-licensing' ); ?></th>
                    <th><?php esc_html_e( 'Actions', 'glimmr-licensing' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $licenses as $license ) : ?>
                    <tr>
                        <td><input type="checkbox" class="cb-license" value="<?php echo esc_attr( $license->id ); ?>" /></td>
                        <td>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=glimmr-licensing-licenses&license_id=' . $license->id ) ); ?>">
                                <code><?php echo esc_html( Glimmr_Licensing_Admin::mask_key( $license->license_key ) ); ?></code>
                            </a>
                        </td>
                        <td>
                            <?php echo esc_html( $license->customer_name ); ?><br>
                            <small><?php echo esc_html( $license->customer_email ); ?></small>
                        </td>
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
                        <td>
                            <?php
                            echo $license->expiry_date
                                ? esc_html( wp_date( 'M j, Y', strtotime( $license->expiry_date ) ) )
                                : '<em>' . esc_html__( 'Lifetime', 'glimmr-licensing' ) . '</em>';
                            ?>
                        </td>
                        <td class="glimmr-actions">
                            <?php if ( 'active' === $license->status ) : ?>
                                <button class="button button-small btn-suspend" data-id="<?php echo esc_attr( $license->id ); ?>">
                                    <?php esc_html_e( 'Suspend', 'glimmr-licensing' ); ?>
                                </button>
                            <?php elseif ( in_array( $license->status, array( 'suspended', 'expired', 'cancelled' ), true ) ) : ?>
                                <button class="button button-small btn-reactivate" data-id="<?php echo esc_attr( $license->id ); ?>">
                                    <?php esc_html_e( 'Activate', 'glimmr-licensing' ); ?>
                                </button>
                            <?php endif; ?>
                            <button class="button button-small btn-delete" data-id="<?php echo esc_attr( $license->id ); ?>" style="color: #b32d2e;">
                                <?php esc_html_e( 'Delete', 'glimmr-licensing' ); ?>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ( $total_pages > 1 ) : ?>
            <div class="glimmr-pagination">
                <span>
                    <?php
                    printf(
                        /* translators: 1: total items */
                        esc_html__( '%s items', 'glimmr-licensing' ),
                        number_format_i18n( $total )
                    );
                    ?>
                </span>
                <div class="page-numbers">
                    <?php
                    echo paginate_links( array( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                        'base'      => add_query_arg( 'paged', '%#%' ),
                        'format'    => '',
                        'current'   => $current_page,
                        'total'     => $total_pages,
                        'prev_text' => '&laquo;',
                        'next_text' => '&raquo;',
                    ) );
                    ?>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<script>
(function() {
    var nonce = '<?php echo esc_js( $nonce ); ?>';

    // Create license.
    document.getElementById('btn-create-license').addEventListener('click', function() {
        var btn = this;
        var msg = document.getElementById('create-message');
        btn.disabled = true;

        var data = new FormData();
        data.append('action', 'glimmr_licensing_create_license');
        data.append('nonce', nonce);
        data.append('customer_name', document.getElementById('create-name').value);
        data.append('customer_email', document.getElementById('create-email').value);
        data.append('plan', document.getElementById('create-plan').value);
        data.append('expiry_date', document.getElementById('create-expiry').value);

        fetch(ajaxurl, { method: 'POST', body: data, credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(result) {
                btn.disabled = false;
                if (result.success) {
                    msg.style.color = '#0e6027';
                    msg.textContent = 'License created: ' + result.data.license_key;
                    setTimeout(function() { location.reload(); }, 2000);
                } else {
                    msg.style.color = '#8a1116';
                    msg.textContent = result.data.message || 'Error creating license.';
                }
            })
            .catch(function() {
                btn.disabled = false;
                msg.style.color = '#8a1116';
                msg.textContent = 'Network error.';
            });
    });

    // Bulk selection.
    var selectAll = document.getElementById('cb-select-all');
    var bulkBar = document.getElementById('bulk-actions');
    var bulkCount = document.getElementById('bulk-count');

    function getCheckedIds() {
        var ids = [];
        document.querySelectorAll('.cb-license:checked').forEach(function(cb) {
            ids.push(cb.value);
        });
        return ids;
    }

    function updateBulkBar() {
        var ids = getCheckedIds();
        if (ids.length > 0) {
            bulkBar.style.display = '';
            bulkCount.textContent = ids.length + ' <?php echo esc_js( __( 'selected', 'glimmr-licensing' ) ); ?>';
        } else {
            bulkBar.style.display = 'none';
        }
    }

    if (selectAll) {
        selectAll.addEventListener('change', function() {
            var checked = this.checked;
            document.querySelectorAll('.cb-license').forEach(function(cb) {
                cb.checked = checked;
            });
            updateBulkBar();
        });
    }

    document.querySelectorAll('.cb-license').forEach(function(cb) {
        cb.addEventListener('change', function() {
            var all = document.querySelectorAll('.cb-license');
            var allChecked = document.querySelectorAll('.cb-license:checked');
            if (selectAll) {
                selectAll.checked = all.length === allChecked.length;
            }
            updateBulkBar();
        });
    });

    // Bulk apply.
    document.getElementById('btn-bulk-apply').addEventListener('click', function() {
        var action = document.getElementById('bulk-action-select').value;
        var ids = getCheckedIds();

        if (!action || ids.length === 0) return;

        var messages = {
            'delete': '<?php echo esc_js( __( 'Delete the selected licenses and all their activations?', 'glimmr-licensing' ) ); ?>',
            'suspend': '<?php echo esc_js( __( 'Suspend the selected licenses?', 'glimmr-licensing' ) ); ?>',
            'activate': '<?php echo esc_js( __( 'Activate the selected licenses?', 'glimmr-licensing' ) ); ?>'
        };
        if (!confirm(messages[action] || '<?php echo esc_js( __( 'Are you sure?', 'glimmr-licensing' ) ); ?>')) return;

        if (action === 'delete') {
            var data = new FormData();
            data.append('action', 'glimmr_licensing_bulk_action');
            data.append('nonce', nonce);
            data.append('bulk_action', 'delete');
            ids.forEach(function(id) { data.append('license_ids[]', id); });
            fetch(ajaxurl, { method: 'POST', body: data, credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(result) {
                    if (result.success) { location.reload(); }
                    else { alert(result.data && result.data.message ? result.data.message : '<?php echo esc_js( __( 'Bulk action failed.', 'glimmr-licensing' ) ); ?>'); }
                })
                .catch(function() { alert('<?php echo esc_js( __( 'Network error. Please try again.', 'glimmr-licensing' ) ); ?>'); });
        } else {
            var status = action === 'suspend' ? 'suspended' : 'active';
            var data = new FormData();
            data.append('action', 'glimmr_licensing_bulk_action');
            data.append('nonce', nonce);
            data.append('bulk_action', 'status');
            data.append('status', status);
            ids.forEach(function(id) { data.append('license_ids[]', id); });
            fetch(ajaxurl, { method: 'POST', body: data, credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(result) {
                    if (result.success) { location.reload(); }
                    else { alert(result.data && result.data.message ? result.data.message : '<?php echo esc_js( __( 'Bulk action failed.', 'glimmr-licensing' ) ); ?>'); }
                })
                .catch(function() { alert('<?php echo esc_js( __( 'Network error. Please try again.', 'glimmr-licensing' ) ); ?>'); });
        }
    });

    // Suspend / Reactivate / Delete buttons.
    document.querySelectorAll('.btn-suspend').forEach(function(btn) {
        btn.addEventListener('click', function() { updateStatus(this.dataset.id, 'suspended'); });
    });
    document.querySelectorAll('.btn-reactivate').forEach(function(btn) {
        btn.addEventListener('click', function() { updateStatus(this.dataset.id, 'active'); });
    });
    document.querySelectorAll('.btn-delete').forEach(function(btn) {
        btn.addEventListener('click', function() {
            if (!confirm('<?php echo esc_js( __( 'Are you sure you want to delete this license?', 'glimmr-licensing' ) ); ?>')) return;
            var data = new FormData();
            data.append('action', 'glimmr_licensing_delete_license');
            data.append('nonce', nonce);
            data.append('license_id', this.dataset.id);
            fetch(ajaxurl, { method: 'POST', body: data, credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(result) {
                    if (result.success) {
                        location.reload();
                    } else {
                        alert(result.data && result.data.message ? result.data.message : '<?php echo esc_js( __( 'Failed to delete license.', 'glimmr-licensing' ) ); ?>');
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
