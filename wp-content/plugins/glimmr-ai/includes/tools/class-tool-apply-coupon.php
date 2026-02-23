<?php
/**
 * Apply Coupon Tool
 *
 * Applies or removes coupons from the cart.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Glimmr_AI_Tool_Apply_Coupon
 *
 * Handles applying and removing coupons from the shopping cart.
 */
class Glimmr_AI_Tool_Apply_Coupon extends Glimmr_AI_Tool_Base {

    /**
     * Tool name.
     *
     * @var string
     */
    protected $name = 'apply_coupon';

    /**
     * Tool description.
     *
     * @var string
     */
    protected $description = 'Apply a coupon code to the cart to get a discount, or remove an existing coupon. Returns the updated cart totals showing the discount.';

    /**
     * Tool parameters.
     *
     * @var array
     */
    protected $parameters = array(
        'coupon_code' => array(
            'type'        => 'string',
            'description' => 'The coupon code to apply or remove (not required for remove_all)',
            'pattern'     => '^[a-zA-Z0-9\\-_%]{1,100}$',
            'maxLength'   => 100,
        ),
        // New explicit op parameter (v2 format).
        'op' => array(
            'type'        => 'string',
            'description' => 'Operation: apply, remove, or remove_all',
            'required'    => true,
            'enum'        => array( 'apply', 'remove', 'remove_all' ),
        ),
        // Legacy action parameter (backward compatibility).
        'action' => array(
            'type'        => 'string',
            'description' => 'DEPRECATED: Use op instead. Will be removed in v2.0.',
            'enum'        => array( 'apply', 'remove' ),
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

        $coupon_code = $this->get_string_arg( $arguments, 'coupon_code' );

        // Get operation (supports both v2 'op' and legacy 'action').
        $op = $this->get_string_arg( $arguments, 'op' );
        if ( empty( $op ) ) {
            $op = $this->get_string_arg( $arguments, 'action', 'apply' );
        }

        // Ensure cart is loaded.
        $this->ensure_cart_loaded();

        $cart = WC()->cart;

        // Handle remove_all operation.
        if ( 'remove_all' === $op ) {
            return $this->remove_all_coupons( $cart );
        }

        // For apply and remove, coupon_code is required.
        if ( empty( $coupon_code ) ) {
            return $this->format_validation_error(
                'missing_required',
                'coupon_code',
                __( 'Please provide a coupon code.', 'glimmr-ai' )
            );
        }

        // Validate coupon code format (v1.7.0).
        // Only allow alphanumeric characters and common separators (-, _, %).
        // This prevents potential injection attempts through malformed codes.
        if ( ! preg_match( '/^[a-zA-Z0-9\-_%]+$/', $coupon_code ) ) {
            return $this->format_validation_error(
                'invalid_format',
                'coupon_code',
                __( 'Coupon code contains invalid characters. Please use only letters, numbers, dashes, and underscores.', 'glimmr-ai' )
            );
        }

        // Validate reasonable length (most coupon codes are < 50 chars).
        if ( strlen( $coupon_code ) > 100 ) {
            return $this->format_validation_error(
                'code_too_long',
                'coupon_code',
                __( 'Coupon code is too long. Please check and try again.', 'glimmr-ai' )
            );
        }

        // Normalize coupon code.
        $coupon_code = wc_format_coupon_code( $coupon_code );

        // Note: We skip the empty cart check here because:
        // 1. The tool returns a cart_action intent for frontend execution via Store API
        // 2. Store API maintains its own cart session that may differ from WC()->cart
        // 3. WooCommerce will return appropriate error if cart is actually empty when coupon is applied

        if ( 'remove' === $op ) {
            return $this->remove_coupon( $coupon_code, $cart );
        }

        return $this->apply_coupon( $coupon_code, $cart );
    }

    /**
     * Validate and return cart_action intent for applying a coupon.
     *
     * Instead of applying the coupon directly (which causes session sync issues),
     * we validate the coupon and return an intent that the frontend will execute
     * via WooCommerce Store API.
     *
     * @param string  $coupon_code Coupon code.
     * @param WC_Cart $cart        Cart object.
     * @return array Cart action intent or error.
     */
    private function apply_coupon( $coupon_code, $cart ) {
        // Check if already applied.
        if ( $cart->has_discount( $coupon_code ) ) {
            return $this->format_result(
                array(
                    'coupon_code' => $coupon_code,
                    'already_applied' => true,
                    'cart' => $this->get_cart_summary(),
                ),
                true,
                sprintf( __( 'Coupon "%s" is already applied to your cart.', 'glimmr-ai' ), $coupon_code )
            );
        }

        // Validate the coupon.
        $coupon = new WC_Coupon( $coupon_code );

        if ( ! $coupon->get_id() ) {
            return $this->format_error(
                sprintf( __( 'Coupon "%s" does not exist.', 'glimmr-ai' ), $coupon_code ),
                'coupon_not_found'
            );
        }

        // Check if coupon is valid.
        $discounts = new WC_Discounts( $cart );
        $valid = $discounts->is_coupon_valid( $coupon );

        if ( is_wp_error( $valid ) ) {
            return $this->format_error(
                $valid->get_error_message(),
                'coupon_invalid'
            );
        }

        // Build coupon info for display.
        $coupon_info = array(
            'code'          => $coupon_code,
            'type'          => $coupon->get_discount_type(),
            'amount'        => $coupon->get_amount(),
            'free_shipping' => $coupon->get_free_shipping(),
        );

        // Add discount description.
        switch ( $coupon->get_discount_type() ) {
            case 'percent':
                $coupon_info['description'] = $coupon->get_amount() . '% off';
                break;
            case 'fixed_cart':
                $coupon_info['description'] = $this->format_price( $coupon->get_amount() ) . ' off your order';
                break;
            case 'fixed_product':
                $coupon_info['description'] = $this->format_price( $coupon->get_amount() ) . ' off per item';
                break;
        }

        // Return cart_action intent - frontend will execute via Store API.
        return $this->format_outcome(
            'cart_action',
            array(
                'action'      => 'apply_coupon',
                'coupon_code' => $coupon_code,
                'coupon'      => $coupon_info,
            ),
            sprintf( __( 'Applying coupon "%s"...', 'glimmr-ai' ), $coupon_code )
        );
    }

    /**
     * Validate and return cart_action intent for removing a coupon.
     *
     * Instead of removing the coupon directly (which causes session sync issues),
     * we validate and return an intent that the frontend will execute
     * via WooCommerce Store API.
     *
     * @param string  $coupon_code Coupon code.
     * @param WC_Cart $cart        Cart object.
     * @return array Cart action intent or error.
     */
    private function remove_coupon( $coupon_code, $cart ) {
        // Check if coupon is applied.
        if ( ! $cart->has_discount( $coupon_code ) ) {
            return $this->format_outcome(
                'coupon_not_applied',
                array(
                    'coupon_code'     => $coupon_code,
                    'applied_coupons' => $cart->get_applied_coupons(),
                ),
                sprintf( __( 'Coupon "%s" is not applied to your cart.', 'glimmr-ai' ), $coupon_code )
            );
        }

        // Return cart_action intent - frontend will execute via Store API.
        return $this->format_outcome(
            'cart_action',
            array(
                'action'      => 'remove_coupon',
                'coupon_code' => $coupon_code,
            ),
            sprintf( __( 'Removing coupon "%s"...', 'glimmr-ai' ), $coupon_code )
        );
    }

    /**
     * Remove all coupons from the cart.
     *
     * @param WC_Cart $cart Cart object.
     * @return array Result.
     */
    private function remove_all_coupons( $cart ) {
        $applied_coupons = $cart->get_applied_coupons();

        if ( empty( $applied_coupons ) ) {
            return $this->format_outcome(
                'no_coupons',
                array( 'cart' => $this->get_cart_summary() ),
                __( 'No coupons are applied to your cart.', 'glimmr-ai' )
            );
        }

        // Calculate total discount before removing.
        $total_discount = $cart->get_discount_total();
        $coupon_count   = count( $applied_coupons );
        $removed_codes  = $applied_coupons;

        // Remove all coupons.
        foreach ( $applied_coupons as $coupon_code ) {
            $cart->remove_coupon( $coupon_code );
        }

        wc_clear_notices();
        $cart->calculate_totals();

        return $this->format_outcome(
            'all_removed',
            array(
                'coupons_removed'   => $removed_codes,
                'coupon_count'      => $coupon_count,
                'discount_removed'  => $this->format_price( $total_discount ),
                'cart'              => $this->get_cart_summary(),
            ),
            sprintf(
                _n(
                    'Removed %d coupon (saved %s).',
                    'Removed %d coupons (saved %s).',
                    $coupon_count,
                    'glimmr-ai'
                ),
                $coupon_count,
                $this->format_price( $total_discount )
            )
        );
    }

    /**
     * Ensure WooCommerce cart is loaded.
     */
    private function ensure_cart_loaded() {
        if ( is_null( WC()->cart ) ) {
            wc_load_cart();
        }

        if ( is_null( WC()->session ) ) {
            WC()->session = new WC_Session_Handler();
            WC()->session->init();
        }
    }

    /**
     * Get current cart summary.
     *
     * @return array Cart summary.
     */
    private function get_cart_summary() {
        $cart = WC()->cart;

        $coupons = array();
        foreach ( $cart->get_applied_coupons() as $code ) {
            $coupons[] = array(
                'code'     => $code,
                'discount' => $this->format_price( $cart->get_coupon_discount_amount( $code ) ),
            );
        }

        return array(
            'item_count'     => $cart->get_cart_contents_count(),
            'subtotal'       => $this->format_price( $cart->get_subtotal() ),
            'discount_total' => $this->format_price( $cart->get_discount_total() ),
            'total'          => $this->format_price( $cart->get_total( 'edit' ) ),
            'applied_coupons' => $coupons,
            'cart_url'       => wc_get_cart_url(),
            'checkout_url'   => wc_get_checkout_url(),
        );
    }
}
