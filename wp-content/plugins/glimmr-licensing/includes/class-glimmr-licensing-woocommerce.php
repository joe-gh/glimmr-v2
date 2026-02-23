<?php
/**
 * WooCommerce integration for Glimmr Licensing.
 *
 * @package Glimmr_Licensing
 * @since   1.0.0
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Class Glimmr_Licensing_WooCommerce
 *
 * Handles product configuration, auto-generation on purchase, subscription
 * lifecycle, email delivery, and My Account page integration.
 */
class Glimmr_Licensing_WooCommerce {

    /**
     * Register all WooCommerce hooks.
     *
     * @return void
     */
    public function register_hooks() {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return;
        }

        // Product data tab.
        add_filter( 'woocommerce_product_data_tabs', array( $this, 'add_product_data_tab' ) );
        add_action( 'woocommerce_product_data_panels', array( $this, 'render_product_data_panel' ) );
        add_action( 'woocommerce_process_product_meta', array( $this, 'save_product_meta' ) );

        // Order completion — generate license.
        add_action( 'woocommerce_order_status_completed', array( $this, 'handle_order_completed' ), 10, 1 );

        // Subscription hooks (if WooCommerce Subscriptions is active).
        add_action( 'woocommerce_subscription_status_active', array( $this, 'handle_subscription_active' ), 10, 1 );
        add_action( 'woocommerce_subscription_renewal_payment_complete', array( $this, 'handle_renewal_complete' ), 10, 1 );
        add_action( 'woocommerce_subscription_status_on-hold', array( $this, 'handle_subscription_on_hold' ), 10, 1 );
        add_action( 'woocommerce_subscription_status_cancelled', array( $this, 'handle_subscription_cancelled' ), 10, 1 );
        add_action( 'woocommerce_subscription_status_expired', array( $this, 'handle_subscription_expired' ), 10, 1 );

        // My Account tab.
        add_action( 'init', array( $this, 'register_my_account_endpoint' ) );
        add_filter( 'woocommerce_account_menu_items', array( $this, 'add_my_account_menu_item' ) );
        add_action( 'woocommerce_account_licenses_endpoint', array( $this, 'render_my_account_licenses' ) );

        // Order details — show license key.
        add_action( 'woocommerce_order_details_after_order_table', array( $this, 'display_license_on_order' ), 10, 1 );

        // AJAX handler for secure license key retrieval (My Account copy button).
        add_action( 'wp_ajax_glimmr_get_license_key', array( $this, 'ajax_get_license_key' ) );
    }

    /**
     * Add "Licensing" tab to product data metabox.
     *
     * @param array $tabs Existing tabs.
     * @return array Modified tabs.
     */
    public function add_product_data_tab( $tabs ) {
        $tabs['glimmr_licensing'] = array(
            'label'    => __( 'Licensing', 'glimmr-licensing' ),
            'target'   => 'glimmr_licensing_product_data',
            'class'    => array(),
            'priority' => 80,
        );
        return $tabs;
    }

    /**
     * Render the licensing product data panel.
     *
     * @return void
     */
    public function render_product_data_panel() {
        global $post;
        $product_id = $post->ID;
        ?>
        <div id="glimmr_licensing_product_data" class="panel woocommerce_options_panel">
            <?php
            woocommerce_wp_checkbox( array(
                'id'          => '_glimmr_licensing_enabled',
                'label'       => __( 'Enable Licensing', 'glimmr-licensing' ),
                'description' => __( 'Generate a license key when this product is purchased.', 'glimmr-licensing' ),
                'value'       => get_post_meta( $product_id, '_glimmr_licensing_enabled', true ),
            ) );

            woocommerce_wp_select( array(
                'id'      => '_glimmr_licensing_plan',
                'label'   => __( 'License Plan', 'glimmr-licensing' ),
                'options' => array(
                    'plan_1'         => __( 'Plan 1 — 1 Site', 'glimmr-licensing' ),
                    'plan_10'        => __( 'Plan 10 — 10 Sites', 'glimmr-licensing' ),
                    'plan_100'       => __( 'Plan 100 — 100 Sites', 'glimmr-licensing' ),
                    'plan_unlimited' => __( 'Unlimited Sites', 'glimmr-licensing' ),
                ),
                'value'   => get_post_meta( $product_id, '_glimmr_licensing_plan', true ) ?: 'plan_1',
            ) );
            ?>
        </div>
        <?php
    }

    /**
     * Save product licensing meta.
     *
     * @param int $product_id Product ID.
     * @return void
     */
    public function save_product_meta( $product_id ) {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WC handles nonce.
        $enabled = isset( $_POST['_glimmr_licensing_enabled'] ) ? 'yes' : 'no';
        update_post_meta( $product_id, '_glimmr_licensing_enabled', $enabled );

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( isset( $_POST['_glimmr_licensing_plan'] ) ) {
            $plan = sanitize_text_field( wp_unslash( $_POST['_glimmr_licensing_plan'] ) );
            $allowed = array( 'plan_1', 'plan_10', 'plan_100', 'plan_unlimited' );
            if ( in_array( $plan, $allowed, true ) ) {
                update_post_meta( $product_id, '_glimmr_licensing_plan', $plan );
            }
        }
    }

    /**
     * Handle order completed — generate license for licensing-enabled products.
     *
     * @param int $order_id Order ID.
     * @return void
     */
    public function handle_order_completed( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        // Check if already generated.
        if ( $order->get_meta( '_glimmr_license_generated' ) ) {
            return;
        }

        $license_keys = array();
        $manager      = new Glimmr_Licensing_Manager();

        foreach ( $order->get_items() as $item ) {
            $product_id = $item->get_product_id();

            if ( 'yes' !== get_post_meta( $product_id, '_glimmr_licensing_enabled', true ) ) {
                continue;
            }

            // Skip subscription products — handled by handle_subscription_active().
            if ( class_exists( 'WC_Subscriptions_Product' )
                 && WC_Subscriptions_Product::is_subscription( $product_id ) ) {
                continue;
            }

            $plan      = get_post_meta( $product_id, '_glimmr_licensing_plan', true ) ?: 'plan_1';
            $quantity  = $item->get_quantity();
            $item_keys = array();

            for ( $q = 0; $q < $quantity; $q++ ) {
                $result = $manager->create_license( array(
                    'customer_email' => $order->get_billing_email(),
                    'customer_name'  => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                    'plan'           => $plan,
                    'order_id'       => $order_id,
                ) );

                if ( ! is_wp_error( $result ) ) {
                    $item_keys[]    = $result['license_key'];
                    $license_keys[] = $result['license_key'];

                    $this->send_license_email( $order, $result['license_key'], $plan );
                }
            }

            // Store license keys on the order item.
            if ( ! empty( $item_keys ) ) {
                $item->update_meta_data( '_glimmr_license_keys', $item_keys );
                // Keep single key for backwards compat.
                $item->update_meta_data( '_glimmr_license_key', $item_keys[0] );
                $item->save();
            }
        }

        // Also store all keys on the order for quick access.
        if ( ! empty( $license_keys ) ) {
            $order->update_meta_data( '_glimmr_license_keys', $license_keys );
        }

        // Mark as generated to prevent duplicates.
        $order->update_meta_data( '_glimmr_license_generated', '1' );
        $order->save();
    }

    /**
     * Handle subscription becoming active — generate license with expiry.
     *
     * @param WC_Subscription $subscription Subscription object.
     * @return void
     */
    public function handle_subscription_active( $subscription ) {
        if ( ! is_object( $subscription ) || ! method_exists( $subscription, 'get_id' ) ) {
            return;
        }

        $subscription_id = $subscription->get_id();

        // If license was already generated, this is a reactivation (e.g. from on-hold).
        if ( $subscription->get_meta( '_glimmr_license_generated' ) ) {
            $this->reactivate_subscription_licenses( $subscription );
            return;
        }

        $parent_order = $subscription->get_parent();
        if ( ! $parent_order ) {
            return;
        }

        $manager     = new Glimmr_Licensing_Manager();
        $license_ids  = array();
        $license_keys = array();

        // Get next payment date for expiry.
        $next_payment = $subscription->get_date( 'next_payment' );
        $expiry_date  = $next_payment ?: null;

        foreach ( $subscription->get_items() as $item ) {
            $product_id = $item->get_product_id();

            if ( 'yes' !== get_post_meta( $product_id, '_glimmr_licensing_enabled', true ) ) {
                continue;
            }

            $plan     = get_post_meta( $product_id, '_glimmr_licensing_plan', true ) ?: 'plan_1';
            $quantity = $item->get_quantity();

            for ( $q = 0; $q < $quantity; $q++ ) {
                $result = $manager->create_license( array(
                    'customer_email'  => $subscription->get_billing_email(),
                    'customer_name'   => $subscription->get_billing_first_name() . ' ' . $subscription->get_billing_last_name(),
                    'plan'            => $plan,
                    'order_id'        => $parent_order->get_id(),
                    'subscription_id' => $subscription_id,
                    'expiry_date'     => $expiry_date,
                ) );

                if ( ! is_wp_error( $result ) ) {
                    $license_ids[]  = $result['id'];
                    $license_keys[] = $result['license_key'];

                    $this->send_license_email( $parent_order, $result['license_key'], $plan );
                }
            }
        }

        // Store all license IDs and keys on the subscription for lifecycle hooks.
        if ( ! empty( $license_ids ) ) {
            $subscription->update_meta_data( '_glimmr_license_ids', $license_ids );
            $subscription->update_meta_data( '_glimmr_license_keys', $license_keys );
            // Keep single-value meta for backwards compat.
            $subscription->update_meta_data( '_glimmr_license_id', $license_ids[0] );
            $subscription->update_meta_data( '_glimmr_license_key', $license_keys[0] );
        }

        $subscription->update_meta_data( '_glimmr_license_generated', '1' );
        $subscription->save();
    }

    /**
     * Handle subscription renewal — extend license expiry.
     *
     * @param WC_Subscription $subscription Subscription object.
     * @return void
     */
    public function handle_renewal_complete( $subscription ) {
        if ( ! is_object( $subscription ) || ! method_exists( $subscription, 'get_id' ) ) {
            return;
        }

        $next_payment = $subscription->get_date( 'next_payment' );
        if ( ! $next_payment ) {
            return;
        }

        $manager = new Glimmr_Licensing_Manager();

        // Extend all licenses associated with this subscription.
        $license_ids = $subscription->get_meta( '_glimmr_license_ids' );
        if ( is_array( $license_ids ) && ! empty( $license_ids ) ) {
            foreach ( $license_ids as $id ) {
                $manager->extend_expiry( (int) $id, $next_payment );
            }
            return;
        }

        // Backwards compat: single license ID.
        $license_id = $subscription->get_meta( '_glimmr_license_id' );
        if ( $license_id ) {
            $manager->extend_expiry( (int) $license_id, $next_payment );
        }
    }

    /**
     * Handle subscription cancelled.
     *
     * @param WC_Subscription $subscription Subscription object.
     * @return void
     */
    public function handle_subscription_cancelled( $subscription ) {
        $this->update_subscription_license_status( $subscription, 'cancelled' );
    }

    /**
     * Handle subscription expired.
     *
     * @param WC_Subscription $subscription Subscription object.
     * @return void
     */
    public function handle_subscription_expired( $subscription ) {
        $this->update_subscription_license_status( $subscription, 'expired' );
    }

    /**
     * Handle subscription put on hold (e.g. failed payment).
     *
     * @param WC_Subscription $subscription Subscription object.
     * @return void
     */
    public function handle_subscription_on_hold( $subscription ) {
        $this->update_subscription_license_status( $subscription, 'suspended' );
    }

    /**
     * Reactivate licenses for a subscription coming back to active status.
     *
     * Restores the license status to 'active' and updates the expiry date
     * to the subscription's next payment date.
     *
     * @param WC_Subscription $subscription Subscription object.
     * @return void
     */
    private function reactivate_subscription_licenses( $subscription ) {
        $this->update_subscription_license_status( $subscription, 'active' );

        // Update expiry date to the next payment date.
        $next_payment = $subscription->get_date( 'next_payment' );
        if ( ! $next_payment ) {
            return;
        }

        $manager = new Glimmr_Licensing_Manager();

        $license_ids = $subscription->get_meta( '_glimmr_license_ids' );
        if ( is_array( $license_ids ) && ! empty( $license_ids ) ) {
            foreach ( $license_ids as $id ) {
                $manager->extend_expiry( (int) $id, $next_payment );
            }
            return;
        }

        // Backwards compat: single license ID.
        $license_id = $subscription->get_meta( '_glimmr_license_id' );
        if ( $license_id ) {
            $manager->extend_expiry( (int) $license_id, $next_payment );
        }
    }

    /**
     * Update license status when subscription status changes.
     *
     * @param WC_Subscription $subscription Subscription object.
     * @param string          $status       New license status.
     * @return void
     */
    private function update_subscription_license_status( $subscription, $status ) {
        if ( ! is_object( $subscription ) || ! method_exists( $subscription, 'get_meta' ) ) {
            return;
        }

        $manager = new Glimmr_Licensing_Manager();

        // Update all licenses associated with this subscription.
        $license_ids = $subscription->get_meta( '_glimmr_license_ids' );
        if ( is_array( $license_ids ) && ! empty( $license_ids ) ) {
            foreach ( $license_ids as $id ) {
                $manager->update_license_status( (int) $id, $status );
            }
            return;
        }

        // Backwards compat: single license ID.
        $license_id = $subscription->get_meta( '_glimmr_license_id' );
        if ( $license_id ) {
            $manager->update_license_status( (int) $license_id, $status );
        }
    }

    /**
     * Send license key email to customer.
     *
     * @param WC_Order $order       Order object.
     * @param string   $license_key License key.
     * @param string   $plan        Plan identifier.
     * @return void
     */
    private function send_license_email( $order, $license_key, $plan ) {
        $settings = get_option( 'glimmr_licensing_settings', array() );
        if ( empty( $settings['auto_email_license'] ) ) {
            return;
        }

        $to      = $order->get_billing_email();
        $subject = sprintf(
            /* translators: %s: site name */
            __( 'Your Glimmr AI License Key — %s', 'glimmr-licensing' ),
            get_bloginfo( 'name' )
        );

        $plan_labels = array(
            'plan_1'         => __( '1 Site', 'glimmr-licensing' ),
            'plan_10'        => __( '10 Sites', 'glimmr-licensing' ),
            'plan_100'       => __( '100 Sites', 'glimmr-licensing' ),
            'plan_unlimited' => __( 'Unlimited Sites', 'glimmr-licensing' ),
        );
        $plan_label = $plan_labels[ $plan ] ?? $plan;

        $message = sprintf(
            /* translators: 1: customer name, 2: license key, 3: plan label */
            __(
                "Hi %1\$s,\n\n" .
                "Thank you for your purchase! Here is your Glimmr AI license key:\n\n" .
                "License Key: %2\$s\n" .
                "Plan: %3\$s\n\n" .
                "To activate, go to your WordPress admin → Glimmr AI and enter this license key.\n\n" .
                "If you have any questions, please contact us.\n\n" .
                "Best regards,\n" .
                "The Glimmr Team",
                'glimmr-licensing'
            ),
            $order->get_billing_first_name(),
            $license_key,
            $plan_label
        );

        $sent = wp_mail( $to, $subject, $message );
        if ( ! $sent ) {
            error_log( sprintf(
                'Glimmr Licensing: Failed to send license email to %s for order #%d.',
                $to,
                $order->get_id()
            ) );
        }
    }

    /**
     * Register the "licenses" endpoint for My Account.
     *
     * @return void
     */
    public function register_my_account_endpoint() {
        add_rewrite_endpoint( 'licenses', EP_ROOT | EP_PAGES );
    }

    /**
     * Add "Licenses" menu item to My Account.
     *
     * @param array $items Existing menu items.
     * @return array Modified items.
     */
    public function add_my_account_menu_item( $items ) {
        $new_items = array();
        foreach ( $items as $key => $label ) {
            $new_items[ $key ] = $label;
            // Insert after "Orders".
            if ( 'orders' === $key ) {
                $new_items['licenses'] = __( 'Licenses', 'glimmr-licensing' );
            }
        }
        return $new_items;
    }

    /**
     * Render the My Account licenses tab.
     *
     * @return void
     */
    public function render_my_account_licenses() {
        $user  = wp_get_current_user();
        $email = $user->user_email;

        $manager  = new Glimmr_Licensing_Manager();
        $licenses = $manager->get_licenses_by_email( $email );

        $template = GLIMMR_LICENSING_PLUGIN_DIR . 'templates/myaccount/licenses.php';
        if ( file_exists( $template ) ) {
            include $template;
        }
    }

    /**
     * Display license key on order confirmation / details page.
     *
     * @param WC_Order $order Order object.
     * @return void
     */
    public function display_license_on_order( $order ) {
        // Collect license keys from order items.
        $license_keys = array();
        foreach ( $order->get_items() as $item ) {
            // Check for multiple keys per item (quantity > 1).
            $keys = $item->get_meta( '_glimmr_license_keys' );
            if ( is_array( $keys ) && ! empty( $keys ) ) {
                $license_keys = array_merge( $license_keys, $keys );
                continue;
            }
            // Fall back to single key per item.
            $key = $item->get_meta( '_glimmr_license_key' );
            if ( ! empty( $key ) ) {
                $license_keys[] = $key;
            }
        }

        // Fall back to order-level meta (for legacy orders).
        if ( empty( $license_keys ) ) {
            $keys = $order->get_meta( '_glimmr_license_keys' );
            if ( is_array( $keys ) ) {
                $license_keys = $keys;
            }
        }

        if ( empty( $license_keys ) ) {
            return;
        }

        $heading = count( $license_keys ) > 1
            ? esc_html__( 'License Keys', 'glimmr-licensing' )
            : esc_html__( 'License Key', 'glimmr-licensing' );

        echo '<h2>' . $heading . '</h2>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

        foreach ( $license_keys as $key ) {
            echo '<p><code style="font-size: 16px; padding: 8px 12px; background: #f0f0f0; border-radius: 4px;">';
            echo esc_html( $key );
            echo '</code></p>';
        }

        echo '<p class="woocommerce-info">';
        echo esc_html__( 'Enter this key in your WordPress admin → Glimmr AI to activate the plugin.', 'glimmr-licensing' );
        echo '</p>';
    }

    /**
     * AJAX: Retrieve a license key for the clipboard copy button.
     *
     * Verifies the current user owns the license via customer_email match.
     *
     * @return void
     */
    public function ajax_get_license_key() {
        check_ajax_referer( 'glimmr_licensing_frontend', 'nonce' );

        $license_id = absint( $_POST['license_id'] ?? 0 );
        if ( 0 === $license_id ) {
            wp_send_json_error( 'Invalid request.' );
        }

        $user = wp_get_current_user();
        if ( ! $user->exists() ) {
            wp_send_json_error( 'Unauthorized.' );
        }

        $manager = new Glimmr_Licensing_Manager();
        $license = $manager->get_license( $license_id );

        if ( ! $license || $license->customer_email !== $user->user_email ) {
            wp_send_json_error( 'Unauthorized.' );
        }

        wp_send_json_success( array( 'key' => $license->license_key ) );
    }
}
