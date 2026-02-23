<?php
/**
 * View Cart Tool
 *
 * Retrieves the current shopping cart contents.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Glimmr_AI_Tool_View_Cart
 *
 * Provides access to the current cart contents and totals.
 */
class Glimmr_AI_Tool_View_Cart extends Glimmr_AI_Tool_Base {

    /**
     * Tool name.
     *
     * @var string
     */
    protected $name = 'view_cart';

    /**
     * Tool description.
     *
     * @var string
     */
    protected $description = 'View the contents of the customer\'s shopping cart including items, quantities, prices, and totals. Also shows any applied coupons.';

    /**
     * Tool parameters.
     *
     * @var array
     */
    protected $parameters = array();

    /**
     * Execute the tool.
     *
     * @param array $arguments Tool arguments.
     * @return array Tool result.
     */
    public function execute( $arguments ) {
        $this->log_debug( 'view_cart execute() called' );

        $wc_check = $this->require_wc();
        if ( $wc_check ) {
            return $wc_check;
        }

        // Log session state before loading cart.
        $this->log_session_state( 'Before ensure_cart_loaded' );

        // Ensure cart is loaded.
        $cart_loaded = $this->ensure_cart_loaded();

        // Log session state after loading cart.
        $this->log_session_state( 'After ensure_cart_loaded' );

        // Verify cart was successfully initialized.
        if ( ! $cart_loaded || is_null( WC()->cart ) ) {
            return $this->format_result(
                array(
                    'error'   => 'cart_initialization_failed',
                    'message' => __( 'Unable to load your shopping cart. Please refresh the page and try again.', 'glimmr-ai' ),
                ),
                false,
                __( 'Unable to load your cart at this time.', 'glimmr-ai' )
            );
        }

        $cart = WC()->cart;

        // Log cart state.
        $this->log_debug( 'Cart state', array(
            'is_empty'     => $cart->is_empty(),
            'item_count'   => $cart->get_cart_contents_count(),
            'session_id'   => WC()->session ? WC()->session->get_customer_id() : 'null',
            'has_session'  => WC()->session ? WC()->session->has_session() : 'null',
        ) );

        // Check if cart is empty.
        // Note: WC()->cart uses PHP session which may differ from Store API cart (Cart-Token header).
        // Items added via frontend widget use Store API, so this may report empty when Store API has items.
        // Cart modification tools (update_cart, apply_coupon, checkout_link) handle this by returning
        // cart_action intents for frontend execution via Store API.
        if ( $cart->is_empty() ) {
            $this->log_debug( 'Cart is empty - returning empty response' );
            return $this->format_result(
                array(
                    'is_empty'     => true,
                    'item_count'   => 0,
                    'cart_url'     => wc_get_cart_url(),
                    'checkout_url' => wc_get_checkout_url(),
                ),
                true,
                __( 'Your cart is empty.', 'glimmr-ai' )
            );
        }

        // Calculate totals.
        $cart->calculate_totals();

        // Get cart items.
        $items = array();
        foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
            $product = $cart_item['data'];
            if ( ! $product ) {
                continue;
            }

            $item_data = array(
                'cart_item_key' => $cart_item_key,
                'product_id'    => $cart_item['product_id'],
                'name'          => $product->get_name(),
                'quantity'      => $cart_item['quantity'],
                'price'         => $this->format_price( $product->get_price() ),
                'line_subtotal' => $this->format_price( $cart_item['line_subtotal'] ),
                'line_total'    => $this->format_price( $cart_item['line_total'] ),
                'image'         => wp_get_attachment_url( $product->get_image_id() ) ?: null,
                'url'           => $product->get_permalink(),
            );

            // Add variation info.
            if ( ! empty( $cart_item['variation_id'] ) ) {
                $item_data['variation_id'] = $cart_item['variation_id'];
                $item_data['variation'] = array();

                foreach ( $cart_item['variation'] as $attr_key => $attr_value ) {
                    $attr_name = str_replace( 'attribute_', '', $attr_key );
                    $item_data['variation'][ wc_attribute_label( $attr_name ) ] = $attr_value;
                }
            }

            // Add stock info.
            if ( ! $product->is_in_stock() ) {
                $item_data['stock_warning'] = __( 'This item is now out of stock.', 'glimmr-ai' );
            } elseif ( $product->managing_stock() ) {
                $stock_qty = $product->get_stock_quantity();
                if ( $cart_item['quantity'] > $stock_qty ) {
                    $item_data['stock_warning'] = sprintf(
                        __( 'Only %d available.', 'glimmr-ai' ),
                        $stock_qty
                    );
                }
            }

            $items[] = $item_data;
        }

        // Get applied coupons.
        $coupons = array();
        foreach ( $cart->get_applied_coupons() as $coupon_code ) {
            $coupon = new WC_Coupon( $coupon_code );
            $discount = $cart->get_coupon_discount_amount( $coupon_code );

            $coupons[] = array(
                'code'     => $coupon_code,
                'discount' => $this->format_price( $discount ),
                'type'     => $coupon->get_discount_type(),
            );
        }

        // Get shipping.
        $shipping = array();
        if ( $cart->needs_shipping() && WC()->shipping() ) {
            $packages = WC()->shipping()->get_packages();
            foreach ( $packages as $package ) {
                if ( ! empty( $package['rates'] ) ) {
                    foreach ( $package['rates'] as $rate ) {
                        $shipping[] = array(
                            'method_id' => $rate->get_method_id(),
                            'label'     => $rate->get_label(),
                            'cost'      => $this->format_price( $rate->get_cost() ),
                        );
                    }
                }
            }
        }

        // Build cart summary.
        $data = array(
            'is_empty'          => false,
            'items'             => $items,
            'item_count'        => $cart->get_cart_contents_count(),
            'unique_items'      => count( $items ),
            'subtotal'          => $this->format_price( $cart->get_subtotal() ),
            'discount_total'    => $this->format_price( $cart->get_discount_total() ),
            'shipping_total'    => $this->format_price( $cart->get_shipping_total() ),
            'tax_total'         => $this->format_price( $cart->get_total_tax() ),
            'total'             => $this->format_price( $cart->get_total( 'edit' ) ),
            'coupons'           => $coupons,
            'needs_shipping'    => $cart->needs_shipping(),
            'available_shipping' => $shipping,
            'cart_url'          => wc_get_cart_url(),
            'checkout_url'      => wc_get_checkout_url(),
        );

        // Add free shipping progress if applicable.
        $free_shipping_info = $this->get_free_shipping_progress( $cart );
        if ( $free_shipping_info ) {
            $data['free_shipping'] = $free_shipping_info;
        }

        return $this->format_result(
            $data,
            true,
            sprintf(
                _n( 'Your cart has %d item.', 'Your cart has %d items.', $data['item_count'], 'glimmr-ai' ),
                $data['item_count']
            )
        );
    }

    /**
     * Ensure WooCommerce cart is loaded with the browser's existing session.
     *
     * @return bool True if cart was loaded successfully, false otherwise.
     */
    private function ensure_cart_loaded() {
        $this->log_debug( 'ensure_cart_loaded() called', array(
            'cart_null'     => is_null( WC()->cart ),
            'session_null'  => is_null( WC()->session ),
            'customer_null' => is_null( WC()->customer ),
        ) );

        // Find the WooCommerce session cookie from the request.
        $wc_session_cookie_name = 'wp_woocommerce_session_' . COOKIEHASH;
        $session_cookie_value = isset( $_COOKIE[ $wc_session_cookie_name ] )
            ? sanitize_text_field( wp_unslash( $_COOKIE[ $wc_session_cookie_name ] ) )
            : '';

        $this->log_debug( 'Session cookie check', array(
            'cookie_name'   => $wc_session_cookie_name,
            'cookie_exists' => ! empty( $session_cookie_value ),
            'cookie_length' => strlen( $session_cookie_value ),
        ) );

        // Initialize session if needed.
        if ( is_null( WC()->session ) ) {
            $this->log_debug( 'Creating new WC_Session_Handler' );
            WC()->session = new WC_Session_Handler();
            WC()->session->init();
        }

        // Log session details after init.
        $session_customer_id = WC()->session->get_customer_id();
        $this->log_debug( 'Session after init', array(
            'session_class'        => get_class( WC()->session ),
            'customer_id'          => $session_customer_id,
            'has_session'          => WC()->session->has_session(),
            'is_user_logged_in'    => is_user_logged_in(),
            'wp_user_id'           => get_current_user_id(),
        ) );

        // For guests, check if session matches cookie.
        if ( ! is_user_logged_in() && ! empty( $session_cookie_value ) ) {
            $cookie_parts = explode( '||', $session_cookie_value );
            if ( count( $cookie_parts ) >= 3 ) {
                $cookie_customer_id = $cookie_parts[0];
                $this->log_debug( 'Parsed session cookie', array(
                    'cookie_customer_id'  => $cookie_customer_id,
                    'session_customer_id' => $session_customer_id,
                    'ids_match'           => $cookie_customer_id === $session_customer_id,
                ) );
            }
        }

        // Initialize customer if needed.
        if ( is_null( WC()->customer ) ) {
            WC()->customer = new WC_Customer( get_current_user_id(), true );
        }

        // Load cart if needed.
        if ( is_null( WC()->cart ) ) {
            $this->log_debug( 'Loading cart via wc_load_cart()' );
            wc_load_cart();
        }

        // Verify cart was actually loaded - wc_load_cart() may fail silently.
        if ( is_null( WC()->cart ) ) {
            $this->log_debug( 'Cart initialization failed - WC()->cart is still null after wc_load_cart()', array(
                'session_exists'  => ! is_null( WC()->session ),
                'customer_exists' => ! is_null( WC()->customer ),
            ) );
            return false;
        }

        // Log cart state after loading.
        $this->log_debug( 'Cart after loading', array(
            'cart_contents_count' => WC()->cart->get_cart_contents_count(),
            'is_empty'            => WC()->cart->is_empty(),
            'session_customer_id' => WC()->session ? WC()->session->get_customer_id() : 'null',
        ) );

        return true;
    }

    /**
     * Get free shipping progress information.
     *
     * @param WC_Cart $cart Cart object.
     * @return array|null Free shipping info or null.
     */
    private function get_free_shipping_progress( $cart ) {
        // Check for free shipping methods.
        $zones = WC_Shipping_Zones::get_zones();

        foreach ( $zones as $zone ) {
            foreach ( $zone['shipping_methods'] as $method ) {
                if ( 'free_shipping' === $method->id && $method->is_enabled() ) {
                    $min_amount = $method->get_option( 'min_amount' );
                    if ( $min_amount > 0 ) {
                        $cart_total = $cart->get_subtotal();
                        $remaining  = $min_amount - $cart_total;

                        if ( $remaining > 0 ) {
                            return array(
                                'threshold'  => $this->format_price( $min_amount ),
                                'remaining'  => $this->format_price( $remaining ),
                                'progress'   => round( ( $cart_total / $min_amount ) * 100 ),
                                'message'    => sprintf(
                                    __( 'Add %s more for free shipping!', 'glimmr-ai' ),
                                    $this->format_price( $remaining )
                                ),
                            );
                        } else {
                            return array(
                                'qualified' => true,
                                'message'   => __( 'You qualify for free shipping!', 'glimmr-ai' ),
                            );
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * Log debug message for cart operations.
     *
     * @param string $message Log message.
     * @param array  $context Additional context data.
     */
    private function log_debug( $message, $context = array() ) {
        if ( class_exists( 'Glimmr_AI_Logger' ) ) {
            Glimmr_AI_Logger::debug(
                '[view_cart] ' . $message,
                $context,
                'tools'
            );
        }
    }

    /**
     * Log current session state for debugging.
     *
     * @param string $label Label for the log entry.
     */
    private function log_session_state( $label ) {
        $this->log_debug( 'Session state: ' . $label, array(
            'wc_loaded'           => class_exists( 'WooCommerce' ),
            'wc_instance'         => function_exists( 'WC' ) && WC() ? 'yes' : 'no',
            'session_exists'      => function_exists( 'WC' ) && WC() && WC()->session ? 'yes' : 'no',
            'cart_exists'         => function_exists( 'WC' ) && WC() && WC()->cart ? 'yes' : 'no',
            'customer_exists'     => function_exists( 'WC' ) && WC() && WC()->customer ? 'yes' : 'no',
            'is_rest_request'     => defined( 'REST_REQUEST' ) && REST_REQUEST,
            'user_logged_in'      => is_user_logged_in(),
            'user_id'             => get_current_user_id(),
        ) );
    }
}
