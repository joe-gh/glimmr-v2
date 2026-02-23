<?php
/**
 * License management page template.
 *
 * Shows either the activation form (when not licensed) or the license
 * status with deactivation option (when licensed).
 *
 * @package Glimmr_AI
 * @since   1.9.0
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

$license       = Glimmr_AI_License::get_instance();
$license_key   = get_option( Glimmr_AI_License::OPT_LICENSE_KEY, '' );
$is_licensed   = ! empty( $license_key ) && $license->is_licensed();
$status_data   = $license->get_status();
$nonce_field   = wp_nonce_field( 'glimmr_ai_license_nonce', 'glimmr_license_nonce', true, false );
?>
<style>
    .glimmr-license-wrap {
        display: flex;
        justify-content: center;
        align-items: center;
        min-height: 70vh;
        padding: 40px 20px;
    }

    .glimmr-license-card {
        background: #fff;
        border: 1px solid #dcdcde;
        border-radius: 8px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        max-width: 480px;
        width: 100%;
        padding: 40px;
        text-align: center;
    }

    .glimmr-license-card .glimmr-logo {
        font-size: 28px;
        font-weight: 700;
        color: #1d2327;
        margin-bottom: 8px;
    }

    .glimmr-license-card .glimmr-logo span {
        color: #7c3aed;
    }

    .glimmr-license-card .glimmr-subtitle {
        color: #646970;
        font-size: 14px;
        margin-bottom: 32px;
    }

    .glimmr-license-card label {
        display: block;
        text-align: left;
        font-weight: 600;
        font-size: 13px;
        color: #1d2327;
        margin-bottom: 8px;
    }

    .glimmr-license-card .glimmr-license-input {
        width: 100%;
        padding: 10px 14px;
        font-size: 15px;
        font-family: 'SF Mono', 'Consolas', 'Monaco', monospace;
        letter-spacing: 1px;
        text-transform: uppercase;
        border: 1px solid #8c8f94;
        border-radius: 4px;
        box-sizing: border-box;
        transition: border-color 0.15s;
    }

    .glimmr-license-card .glimmr-license-input:focus {
        border-color: #7c3aed;
        box-shadow: 0 0 0 1px #7c3aed;
        outline: none;
    }

    .glimmr-license-card .glimmr-license-input::placeholder {
        text-transform: none;
        letter-spacing: 0;
        color: #a7aaad;
    }

    .glimmr-license-card .glimmr-activate-btn {
        display: inline-block;
        width: 100%;
        margin-top: 16px;
        padding: 12px 24px;
        font-size: 14px;
        font-weight: 600;
        color: #fff;
        background: #7c3aed;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        transition: background 0.15s;
    }

    .glimmr-license-card .glimmr-activate-btn:hover {
        background: #6d28d9;
    }

    .glimmr-license-card .glimmr-activate-btn:disabled {
        background: #a78bfa;
        cursor: not-allowed;
    }

    .glimmr-license-card .glimmr-deactivate-btn {
        display: inline-block;
        width: 100%;
        margin-top: 12px;
        padding: 10px 24px;
        font-size: 13px;
        font-weight: 600;
        color: #8a1116;
        background: #fff;
        border: 1px solid #d63638;
        border-radius: 4px;
        cursor: pointer;
        transition: background 0.15s, color 0.15s;
    }

    .glimmr-license-card .glimmr-deactivate-btn:hover {
        background: #fcf0f0;
    }

    .glimmr-license-card .glimmr-deactivate-btn:disabled {
        opacity: 0.6;
        cursor: not-allowed;
    }

    .glimmr-license-card .glimmr-license-message {
        margin-top: 16px;
        padding: 10px 14px;
        border-radius: 4px;
        font-size: 13px;
        display: none;
    }

    .glimmr-license-card .glimmr-license-message.success {
        display: block;
        background: #edfcf2;
        border: 1px solid #68de7c;
        color: #0e6027;
    }

    .glimmr-license-card .glimmr-license-message.error {
        display: block;
        background: #fcf0f0;
        border: 1px solid #d63638;
        color: #8a1116;
    }

    .glimmr-license-card .glimmr-purchase-link {
        display: block;
        margin-top: 24px;
        color: #646970;
        font-size: 13px;
    }

    .glimmr-license-card .glimmr-purchase-link a {
        color: #7c3aed;
        text-decoration: none;
    }

    .glimmr-license-card .glimmr-purchase-link a:hover {
        text-decoration: underline;
    }

    /* Status display styles */
    .glimmr-license-status {
        text-align: left;
        margin-bottom: 24px;
    }

    .glimmr-license-status .glimmr-status-badge {
        display: inline-block;
        padding: 4px 12px;
        border-radius: 12px;
        font-size: 12px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-bottom: 20px;
    }

    .glimmr-license-status .glimmr-status-badge.active {
        background: #edfcf2;
        color: #0e6027;
        border: 1px solid #68de7c;
    }

    .glimmr-license-status .glimmr-status-badge.grace {
        background: #fef8ee;
        color: #9a6700;
        border: 1px solid #dba617;
    }

    .glimmr-license-status .glimmr-status-row {
        display: flex;
        justify-content: space-between;
        padding: 10px 0;
        border-bottom: 1px solid #f0f0f1;
        font-size: 13px;
    }

    .glimmr-license-status .glimmr-status-row:last-child {
        border-bottom: none;
    }

    .glimmr-license-status .glimmr-status-label {
        color: #646970;
        font-weight: 500;
    }

    .glimmr-license-status .glimmr-status-value {
        color: #1d2327;
        font-weight: 600;
        font-family: 'SF Mono', 'Consolas', 'Monaco', monospace;
        font-size: 12px;
    }

    .glimmr-license-status .glimmr-status-value.text {
        font-family: inherit;
        font-size: 13px;
    }
</style>

<div class="wrap glimmr-license-wrap">
    <div class="glimmr-license-card">
        <div class="glimmr-logo"><span>Glimmr</span> AI</div>
        <div class="glimmr-subtitle"><?php esc_html_e( 'AI Shopping Assistant for WooCommerce', 'glimmr-ai' ); ?></div>

        <?php if ( $is_licensed ) : ?>
            <!-- Licensed: Show status and deactivation -->
            <div class="glimmr-license-status">
                <?php if ( ! empty( $status_data['grace_period'] ) ) : ?>
                    <span class="glimmr-status-badge grace"><?php esc_html_e( 'Grace Period', 'glimmr-ai' ); ?></span>
                <?php else : ?>
                    <span class="glimmr-status-badge active"><?php esc_html_e( 'Active', 'glimmr-ai' ); ?></span>
                <?php endif; ?>

                <div class="glimmr-status-row">
                    <span class="glimmr-status-label"><?php esc_html_e( 'License Key', 'glimmr-ai' ); ?></span>
                    <span class="glimmr-status-value"><?php echo esc_html( $status_data['license_key'] ?? '' ); ?></span>
                </div>

                <div class="glimmr-status-row">
                    <span class="glimmr-status-label"><?php esc_html_e( 'Plan', 'glimmr-ai' ); ?></span>
                    <span class="glimmr-status-value text"><?php echo esc_html( $status_data['plan_label'] ?? '' ); ?></span>
                </div>

                <div class="glimmr-status-row">
                    <span class="glimmr-status-label"><?php esc_html_e( 'Sites Used', 'glimmr-ai' ); ?></span>
                    <span class="glimmr-status-value text">
                        <?php
                        $site_limit = (int) ( $status_data['site_limit'] ?? 0 );
                        $used       = (int) ( $status_data['activations_used'] ?? 0 );
                        if ( 0 === $site_limit ) {
                            echo esc_html( (string) $used ) . ' / &#8734;';
                        } else {
                            echo esc_html( (string) $used ) . ' / ' . esc_html( (string) $site_limit );
                        }
                        ?>
                    </span>
                </div>

                <div class="glimmr-status-row">
                    <span class="glimmr-status-label"><?php esc_html_e( 'Expiry', 'glimmr-ai' ); ?></span>
                    <span class="glimmr-status-value text">
                        <?php
                        $expiry = $status_data['expiry'] ?? '';
                        if ( empty( $expiry ) ) {
                            esc_html_e( 'Lifetime', 'glimmr-ai' );
                        } else {
                            $expiry_ts = strtotime( $expiry );
                            $expiry_formatted = $expiry_ts !== false ? wp_date( 'F j, Y', $expiry_ts ) : false;
                            echo esc_html( $expiry_formatted !== false ? $expiry_formatted : $expiry );
                        }
                        ?>
                    </span>
                </div>

                <?php
                $last_validated = (int) ( $status_data['last_validated'] ?? 0 );
                if ( $last_validated > 0 ) :
                ?>
                <div class="glimmr-status-row">
                    <span class="glimmr-status-label"><?php esc_html_e( 'Last Validated', 'glimmr-ai' ); ?></span>
                    <span class="glimmr-status-value text"><?php
                        $validated_formatted = wp_date( 'F j, Y g:i A', $last_validated );
                        echo esc_html( $validated_formatted !== false ? $validated_formatted : (string) $last_validated );
                    ?></span>
                </div>
                <?php endif; ?>
            </div>

            <?php echo $nonce_field; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

            <button type="button" class="glimmr-deactivate-btn" id="glimmr-deactivate-btn">
                <?php esc_html_e( 'Deactivate License', 'glimmr-ai' ); ?>
            </button>

            <div class="glimmr-license-message" id="glimmr-license-message"></div>

        <?php else : ?>
            <!-- Not licensed: Show activation form -->
            <form id="glimmr-license-form">
                <label for="glimmr-license-key"><?php esc_html_e( 'License Key', 'glimmr-ai' ); ?></label>
                <input
                    type="text"
                    id="glimmr-license-key"
                    class="glimmr-license-input"
                    placeholder="GLMR-XXXX-XXXX-XXXX-XXXX"
                    maxlength="24"
                    autocomplete="off"
                    spellcheck="false"
                />

                <button type="submit" class="glimmr-activate-btn" id="glimmr-activate-btn">
                    <?php esc_html_e( 'Activate License', 'glimmr-ai' ); ?>
                </button>

                <?php echo $nonce_field; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            </form>

            <div class="glimmr-license-message" id="glimmr-license-message"></div>

            <div class="glimmr-purchase-link">
                <?php
                printf(
                    /* translators: %s: URL to purchase page */
                    esc_html__( "Don't have a license? %s", 'glimmr-ai' ),
                    '<a href="https://glimmr.us" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Purchase one here', 'glimmr-ai' ) . '</a>'
                );
                ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
(function() {
    var msg = document.getElementById('glimmr-license-message');

    function showMessage(type, text) {
        msg.className = 'glimmr-license-message ' + type;
        msg.textContent = text;
        msg.style.display = 'block';
    }

    <?php if ( $is_licensed ) : ?>
    // Deactivation handler.
    var deactivateBtn = document.getElementById('glimmr-deactivate-btn');
    deactivateBtn.addEventListener('click', function() {
        if (!confirm('<?php echo esc_js( __( 'Are you sure you want to deactivate your license on this site? You can reactivate later.', 'glimmr-ai' ) ); ?>')) {
            return;
        }

        deactivateBtn.disabled = true;
        deactivateBtn.textContent = '<?php echo esc_js( __( 'Deactivating...', 'glimmr-ai' ) ); ?>';
        msg.style.display = 'none';

        var data = new FormData();
        data.append('action', 'glimmr_ai_deactivate_license');
        data.append('_wpnonce', document.getElementById('glimmr_license_nonce').value);

        fetch(ajaxurl, {
            method: 'POST',
            body: data,
            credentials: 'same-origin'
        })
        .then(function(response) { return response.json(); })
        .then(function(result) {
            if (result.success) {
                showMessage('success', result.data.message || '<?php echo esc_js( __( 'License deactivated. Reloading...', 'glimmr-ai' ) ); ?>');
                setTimeout(function() { window.location.reload(); }, 1500);
            } else {
                showMessage('error', result.data.message || '<?php echo esc_js( __( 'Deactivation failed.', 'glimmr-ai' ) ); ?>');
                deactivateBtn.disabled = false;
                deactivateBtn.textContent = '<?php echo esc_js( __( 'Deactivate License', 'glimmr-ai' ) ); ?>';
            }
        })
        .catch(function() {
            showMessage('error', '<?php echo esc_js( __( 'Network error. Please try again.', 'glimmr-ai' ) ); ?>');
            deactivateBtn.disabled = false;
            deactivateBtn.textContent = '<?php echo esc_js( __( 'Deactivate License', 'glimmr-ai' ) ); ?>';
        });
    });

    <?php else : ?>
    // Activation handler.
    var form = document.getElementById('glimmr-license-form');
    var input = document.getElementById('glimmr-license-key');
    var btn = document.getElementById('glimmr-activate-btn');

    form.addEventListener('submit', function(e) {
        e.preventDefault();

        var key = input.value.trim();
        if (!key) {
            showMessage('error', '<?php echo esc_js( __( 'Please enter a license key.', 'glimmr-ai' ) ); ?>');
            return;
        }

        btn.disabled = true;
        btn.textContent = '<?php echo esc_js( __( 'Activating...', 'glimmr-ai' ) ); ?>';
        msg.style.display = 'none';

        var data = new FormData();
        data.append('action', 'glimmr_ai_activate_license');
        data.append('license_key', key);
        data.append('_wpnonce', document.getElementById('glimmr_license_nonce').value);

        fetch(ajaxurl, {
            method: 'POST',
            body: data,
            credentials: 'same-origin'
        })
        .then(function(response) { return response.json(); })
        .then(function(result) {
            if (result.success) {
                showMessage('success', result.data.message || '<?php echo esc_js( __( 'License activated! Reloading...', 'glimmr-ai' ) ); ?>');
                setTimeout(function() { window.location.reload(); }, 1500);
            } else {
                showMessage('error', result.data.message || '<?php echo esc_js( __( 'Activation failed.', 'glimmr-ai' ) ); ?>');
                btn.disabled = false;
                btn.textContent = '<?php echo esc_js( __( 'Activate License', 'glimmr-ai' ) ); ?>';
            }
        })
        .catch(function() {
            showMessage('error', '<?php echo esc_js( __( 'Network error. Please try again.', 'glimmr-ai' ) ); ?>');
            btn.disabled = false;
            btn.textContent = '<?php echo esc_js( __( 'Activate License', 'glimmr-ai' ) ); ?>';
        });
    });
    <?php endif; ?>
})();
</script>
