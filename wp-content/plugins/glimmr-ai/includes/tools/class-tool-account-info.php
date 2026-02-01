<?php
/**
 * Account Info Tool
 *
 * Retrieves customer account information.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Glimmr_AI_Tool_Account_Info
 *
 * Provides access to customer account details for logged-in users.
 */
class Glimmr_AI_Tool_Account_Info extends Glimmr_AI_Tool_Base {

    /**
     * Tool name.
     *
     * @var string
     */
    protected $name = 'account_info';

    /**
     * Tool description.
     *
     * @var string
     */
    protected $description = 'Get information about the customer\'s account including saved addresses, payment methods, and account status. Requires the customer to be logged in.';

    /**
     * Tool parameters.
     *
     * @var array
     */
    protected $parameters = array(
        'type' => array(
            'type'        => 'string',
            'description' => 'Type of account info to retrieve: profile, addresses, payment_methods, downloads, subscriptions, all',
            'required'    => false,
            'enum'        => array( 'profile', 'addresses', 'payment_methods', 'downloads', 'subscriptions', 'all' ),
        ),
    );

    /**
     * Execute the tool.
     *
     * @param array $arguments Tool arguments.
     * @return array Tool result.
     */
    public function execute( $arguments ) {
        $wc_check = $this->require_wc();
        if ( $wc_check ) {
            return $wc_check;
        }

        $login_check = $this->require_login();
        if ( $login_check ) {
            return $login_check;
        }

        $type = $this->get_string_arg( $arguments, 'type', 'all' );

        $customer = new WC_Customer( $this->user_id );

        if ( ! $customer->get_id() ) {
            return $this->format_error(
                __( 'Could not retrieve account information.', 'glimmr-ai' ),
                'customer_not_found'
            );
        }

        $data = array();

        switch ( $type ) {
            case 'profile':
                $data = $this->get_profile_info( $customer );
                break;

            case 'addresses':
                $data = $this->get_addresses( $customer );
                break;

            case 'payment_methods':
                $data = $this->get_payment_methods();
                break;

            case 'downloads':
                $data = $this->get_downloads();
                break;

            case 'subscriptions':
                $data = $this->get_subscriptions();
                break;

            case 'all':
            default:
                $data = array(
                    'profile'         => $this->get_profile_info( $customer ),
                    'addresses'       => $this->get_addresses( $customer ),
                    'payment_methods' => $this->get_payment_methods(),
                    'quick_stats'     => $this->get_quick_stats( $customer ),
                );
                break;
        }

        // Add account links.
        $data['links'] = array(
            'account'   => wc_get_account_endpoint_url( 'dashboard' ),
            'orders'    => wc_get_account_endpoint_url( 'orders' ),
            'addresses' => wc_get_account_endpoint_url( 'edit-address' ),
            'details'   => wc_get_account_endpoint_url( 'edit-account' ),
        );

        return $this->format_result( $data, true );
    }

    /**
     * Get profile information.
     *
     * S10: PII masking - mask email and phone to prevent exposure in AI context.
     *
     * @param WC_Customer $customer Customer object.
     * @return array Profile data.
     */
    private function get_profile_info( $customer ) {
        $user = $customer->get_user();

        $member_since = null;
        if ( $user && ! empty( $user->user_registered ) ) {
            $registered_time = strtotime( $user->user_registered );
            if ( false !== $registered_time ) {
                $member_since = date_i18n( get_option( 'date_format' ), $registered_time );
            }
        }

        return array(
            'display_name' => $customer->get_display_name(),
            'first_name'   => $customer->get_first_name(),
            'last_name'    => $customer->get_last_name(),
            'email'        => $this->mask_email( $customer->get_email() ),
            'phone'        => $this->mask_phone( $customer->get_billing_phone() ),
            'member_since' => $member_since,
        );
    }

    /**
     * Mask an email address for privacy.
     *
     * S10: Masks email to format like "j***@example.com"
     *
     * @param string $email Email address.
     * @return string Masked email.
     */
    private function mask_email( $email ) {
        if ( empty( $email ) ) {
            return '';
        }

        $parts = explode( '@', $email );
        if ( count( $parts ) !== 2 ) {
            return '***';
        }

        $local = $parts[0];
        $domain = $parts[1];

        // Show first character, mask the rest.
        $masked_local = substr( $local, 0, 1 ) . str_repeat( '*', min( 3, strlen( $local ) - 1 ) );

        return $masked_local . '@' . $domain;
    }

    /**
     * Mask a phone number for privacy.
     *
     * S10: Masks phone to format like "***-***-1234"
     *
     * @param string $phone Phone number.
     * @return string Masked phone.
     */
    private function mask_phone( $phone ) {
        if ( empty( $phone ) ) {
            return '';
        }

        // Extract just the digits.
        $digits = preg_replace( '/[^0-9]/', '', $phone );
        // preg_replace returns null on error.
        if ( null === $digits || strlen( $digits ) < 4 ) {
            return '***';
        }

        // Show only last 4 digits.
        $last4 = substr( $digits, -4 );
        return '***-***-' . $last4;
    }

    /**
     * Get saved addresses.
     *
     * S11: Address privacy - only expose city/state/country, not full street addresses.
     * This prevents exposure of sensitive location data in AI context.
     *
     * @param WC_Customer $customer Customer object.
     * @return array Address data (masked to city/state/country only).
     */
    private function get_addresses( $customer ) {
        $addresses = array();

        // Check if billing address is set.
        $billing_city = $customer->get_billing_city();
        $billing_state = $customer->get_billing_state();
        $billing_country = $customer->get_billing_country();

        if ( ! empty( $billing_city ) || ! empty( $billing_state ) || ! empty( $billing_country ) ) {
            // S11: Only expose city, state, country - no street addresses, names, phone, or postcode.
            $addresses['billing'] = array(
                'city'    => $billing_city,
                'state'   => $billing_state,
                'country' => $billing_country,
            );
            // Build masked formatted address.
            $billing_parts = array_filter( array( $billing_city, $billing_state, $billing_country ) );
            $addresses['billing']['formatted'] = implode( ', ', $billing_parts );
            $addresses['billing']['has_address'] = true;
        }

        // Check if shipping address is set.
        $shipping_city = $customer->get_shipping_city();
        $shipping_state = $customer->get_shipping_state();
        $shipping_country = $customer->get_shipping_country();

        if ( ! empty( $shipping_city ) || ! empty( $shipping_state ) || ! empty( $shipping_country ) ) {
            // S11: Only expose city, state, country - no street addresses, names, or postcode.
            $addresses['shipping'] = array(
                'city'    => $shipping_city,
                'state'   => $shipping_state,
                'country' => $shipping_country,
            );
            // Build masked formatted address.
            $shipping_parts = array_filter( array( $shipping_city, $shipping_state, $shipping_country ) );
            $addresses['shipping']['formatted'] = implode( ', ', $shipping_parts );
            $addresses['shipping']['has_address'] = true;
        }

        if ( empty( $addresses ) ) {
            $addresses['message'] = __( 'No addresses saved yet.', 'glimmr-ai' );
        }

        // Provide link for user to manage addresses securely.
        $addresses['manage_url'] = wc_get_account_endpoint_url( 'edit-address' );

        return $addresses;
    }

    /**
     * Get saved payment methods.
     *
     * S7: Do not expose sensitive card data (last4, card_type, expiry) to AI context.
     * Only provide count and generic payment method info.
     *
     * @return array Payment method data.
     */
    private function get_payment_methods() {
        $tokens = WC_Payment_Tokens::get_customer_tokens( $this->user_id );

        if ( empty( $tokens ) ) {
            return array(
                'has_saved_methods' => false,
                'message'           => __( 'No saved payment methods.', 'glimmr-ai' ),
            );
        }

        // S7: Only expose safe, non-sensitive payment info to AI.
        // Do NOT include: card_type, last4, expiry, full card details.
        $method_count    = count( $tokens );
        $has_default     = false;
        $payment_types   = array();

        foreach ( $tokens as $token ) {
            // Track if there's a default payment method.
            if ( $token->is_default() ) {
                $has_default = true;
            }

            // Only track general type (e.g., "credit card", "bank account").
            $type = $token->get_type();
            if ( 'CC' === $type ) {
                $payment_types['credit_card'] = true;
            } elseif ( 'eCheck' === $type ) {
                $payment_types['bank_account'] = true;
            } else {
                $payment_types['other'] = true;
            }
        }

        return array(
            'has_saved_methods'  => true,
            'saved_method_count' => $method_count,
            'has_default_method' => $has_default,
            'payment_types'      => array_keys( $payment_types ),
            'message'            => sprintf(
                /* translators: %d: number of saved payment methods */
                _n(
                    'You have %d saved payment method.',
                    'You have %d saved payment methods.',
                    $method_count,
                    'glimmr-ai'
                ),
                $method_count
            ),
            // Provide link for user to manage payment methods securely.
            'manage_url'         => wc_get_account_endpoint_url( 'payment-methods' ),
        );
    }

    /**
     * Get downloadable products.
     *
     * @return array Downloads data.
     */
    private function get_downloads() {
        $downloads = wc_get_customer_available_downloads( $this->user_id );

        if ( empty( $downloads ) ) {
            return array(
                'has_downloads' => false,
                'message'       => __( 'No downloadable products.', 'glimmr-ai' ),
            );
        }

        $formatted = array();
        foreach ( $downloads as $download ) {
            $formatted[] = array(
                'product_name'     => $download['product_name'],
                'download_name'    => $download['download_name'],
                'download_url'     => $download['download_url'],
                'downloads_remaining' => $download['downloads_remaining'],
                'access_expires'   => $download['access_expires'],
            );
        }

        return array(
            'has_downloads' => true,
            'downloads'     => $formatted,
        );
    }

    /**
     * Get subscriptions if WooCommerce Subscriptions is active.
     *
     * @return array Subscriptions data.
     */
    private function get_subscriptions() {
        // Check if WooCommerce Subscriptions is active.
        if ( ! class_exists( 'WC_Subscriptions' ) ) {
            return array(
                'available' => false,
                'message'   => __( 'Subscriptions are not available.', 'glimmr-ai' ),
            );
        }

        $subscriptions = wcs_get_users_subscriptions( $this->user_id );

        if ( empty( $subscriptions ) ) {
            return array(
                'available'         => true,
                'has_subscriptions' => false,
                'message'           => __( 'No active subscriptions.', 'glimmr-ai' ),
            );
        }

        $formatted = array();
        foreach ( $subscriptions as $subscription ) {
            $sub_data = array(
                'id'           => $subscription->get_id(),
                'status'       => $subscription->get_status(),
                'status_label' => wcs_get_subscription_status_name( $subscription->get_status() ),
                'next_payment' => $subscription->get_date( 'next_payment' ) ? date_i18n( get_option( 'date_format' ), $subscription->get_time( 'next_payment' ) ) : null,
                'total'        => $this->format_price( $subscription->get_total() ),
                'items'        => array(),
            );

            // Add subscription items directly to $sub_data.
            foreach ( $subscription->get_items() as $item ) {
                $sub_data['items'][] = array(
                    'name'     => $item->get_name(),
                    'quantity' => $item->get_quantity(),
                );
            }

            $formatted[] = $sub_data;
        }

        return array(
            'available'         => true,
            'has_subscriptions' => true,
            'subscriptions'     => $formatted,
        );
    }

    /**
     * Get quick account stats.
     *
     * @param WC_Customer $customer Customer object.
     * @return array Stats data.
     */
    private function get_quick_stats( $customer ) {
        // Count orders.
        $order_count = wc_get_customer_order_count( $this->user_id );
        $total_spent = wc_get_customer_total_spent( $this->user_id );

        // Count active orders.
        $active_orders = wc_get_orders( array(
            'customer_id' => $this->user_id,
            'status'      => array( 'pending', 'processing', 'on-hold' ),
            'return'      => 'ids',
        ) );

        return array(
            'total_orders'  => $order_count,
            'total_spent'   => $this->format_price( $total_spent ),
            'active_orders' => count( $active_orders ),
        );
    }
}
