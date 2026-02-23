<?php
/**
 * Update Cart Tool
 *
 * Modifies cart item quantities or removes items.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 * @since 1.1.0 Refactored with item object and explicit ops.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Glimmr_AI_Tool_Update_Cart
 *
 * Handles updating quantities and removing items from the cart.
 * Uses structured item object and explicit operation types.
 */
class Glimmr_AI_Tool_Update_Cart extends Glimmr_AI_Tool_Base {

    /**
     * Tool name.
     *
     * @var string
     */
    protected $name = 'update_cart';

    /**
     * Tool description.
     *
     * @var string
     */
    protected $description = 'Update quantity or remove an item from the cart. Use op="remove" to delete an item, or set_qty/increment/decrement for quantity changes.';

    /**
     * Tool parameters.
     *
     * @var array
     */
    protected $parameters = array(
        // New nested item object (preferred).
        'item' => array(
            'type'                 => 'object',
            'description'          => 'Identifies the cart item to update',
            'additionalProperties' => false,
            'properties'           => array(
                'cart_item_key' => array(
                    'type'        => 'string',
                    'description' => 'Cart item key from view_cart (preferred, unambiguous)',
                ),
                'product_id' => array(
                    'type'        => 'integer',
                    'description' => 'Product ID (fallback, may be ambiguous if multiple variations)',
                    'minimum'     => 1,
                ),
                'variation_id' => array(
                    'type'        => 'integer',
                    'description' => 'Variation ID for disambiguation when using product_id',
                    'minimum'     => 1,
                ),
            ),
        ),
        // Explicit operation type.
        'op' => array(
            'type'        => 'string',
            'enum'        => array( 'set_qty', 'increment', 'decrement', 'remove' ),
            'description' => 'Operation: set_qty (set exact quantity), increment (add to quantity), decrement (subtract from quantity), remove (delete from cart)',
            'required'    => true,
        ),
        // Quantity for non-remove operations.
        'quantity' => array(
            'type'        => 'integer',
            'minimum'     => 1,
            'maximum'     => 99,
            'description' => 'Quantity value (required for set_qty/increment/decrement)',
        ),
        // Legacy parameters for backward compatibility.
        'cart_item_key' => array(
            'type'        => 'string',
            'description' => '[DEPRECATED] Use item.cart_item_key instead',
        ),
        'product_id' => array(
            'type'        => 'integer',
            'description' => '[DEPRECATED] Use item.product_id instead',
        ),
        'action' => array(
            'type'        => 'string',
            'description' => '[DEPRECATED] Use op instead',
            'enum'        => array( 'set', 'add', 'remove' ),
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

        // Extract and normalize parameters (with backward compatibility).
        $item = $this->extract_item( $arguments );
        $op = $this->extract_op( $arguments );
        $quantity = $this->get_int_arg( $arguments, 'quantity', 0 );

        // Validate item identification.
        if ( empty( $item['cart_item_key'] ) && empty( $item['product_id'] ) ) {
            return $this->format_validation_error(
                'missing_item',
                'item',
                __( 'Please specify item.cart_item_key or item.product_id to identify the cart item.', 'glimmr-ai' )
            );
        }

        // Validate operation.
        $valid_ops = array( 'set_qty', 'increment', 'decrement', 'remove' );
        if ( empty( $op ) || ! in_array( $op, $valid_ops, true ) ) {
            return $this->format_validation_error(
                'invalid_op',
                'op',
                __( 'Please specify a valid op: set_qty, increment, decrement, or remove.', 'glimmr-ai' ),
                array( 'allowed_values' => $valid_ops )
            );
        }

        // Validate quantity for non-remove operations.
        if ( 'remove' !== $op && $quantity < 1 ) {
            return $this->format_validation_error(
                'missing_quantity',
                'quantity',
                sprintf( __( 'quantity is required for op="%s" and must be at least 1.', 'glimmr-ai' ), $op )
            );
        }

        // Ensure cart is loaded.
        $cart_loaded = $this->ensure_cart_loaded();

        // Verify cart was successfully initialized.
        if ( ! $cart_loaded || is_null( WC()->cart ) ) {
            return $this->format_outcome(
                'cart_initialization_failed',
                array(
                    'message' => __( 'Unable to load your shopping cart. Please refresh the page and try again.', 'glimmr-ai' ),
                ),
                __( 'Unable to load your cart at this time.', 'glimmr-ai' )
            );
        }

        $cart = WC()->cart;

        // Handle session sync issue: Store API maintains its own cart session that may differ from WC()->cart.
        // If cart appears empty but we have a cart_item_key, trust the frontend and return a cart_action.
        if ( $cart->is_empty() ) {
            // If cart_item_key is provided, we can still attempt the operation via Store API
            if ( ! empty( $item['cart_item_key'] ) ) {
                // For set_qty and remove, we can return cart_action directly
                // For increment/decrement, we need the current quantity which we don't have
                if ( 'set_qty' === $op ) {
                    return $this->format_outcome(
                        'cart_action',
                        array(
                            'action'        => 'update',
                            'cart_item_key' => $item['cart_item_key'],
                            'product_id'    => $item['product_id'] ?: null,
                            'variation_id'  => $item['variation_id'] ?: null,
                            'quantity'      => $quantity,
                            'product_name'  => __( 'item', 'glimmr-ai' ),
                            'op'            => $op,
                        ),
                        sprintf( __( 'Setting quantity to %d.', 'glimmr-ai' ), $quantity )
                    );
                } elseif ( 'remove' === $op ) {
                    return $this->format_outcome(
                        'cart_action',
                        array(
                            'action'        => 'remove',
                            'cart_item_key' => $item['cart_item_key'],
                            'product_id'    => $item['product_id'] ?: null,
                            'variation_id'  => $item['variation_id'] ?: null,
                            'product_name'  => __( 'item', 'glimmr-ai' ),
                        ),
                        __( 'Removing item from cart.', 'glimmr-ai' )
                    );
                }
                // For increment/decrement without current qty, fall through to cart_empty error
            }

            return $this->format_outcome(
                'cart_empty',
                array( 'cart' => $this->get_cart_summary() ),
                __( 'The cart is empty. Nothing to update.', 'glimmr-ai' )
            );
        }

        // Find the cart item.
        $find_result = $this->find_cart_item( $item );

        if ( 'not_found' === $find_result['status'] ) {
            return $this->format_outcome(
                'not_in_cart',
                array(
                    'item' => $item,
                    'cart' => $this->get_cart_summary(),
                ),
                __( 'Item not found in cart.', 'glimmr-ai' )
            );
        }

        if ( 'multiple_matches' === $find_result['status'] ) {
            return $this->format_outcome(
                'needs_disambiguation',
                array(
                    'item'    => $item,
                    'matches' => $find_result['matches'],
                ),
                __( 'Multiple items match. Please specify item.cart_item_key to identify which one.', 'glimmr-ai' )
            );
        }

        $cart_item_key = $find_result['cart_item_key'];
        $cart_item = $cart->get_cart()[ $cart_item_key ];
        $product = $cart_item['data'];
        $product_name = $product->get_name();
        $current_qty = $cart_item['quantity'];

        // Calculate new quantity based on operation.
        $new_quantity = $this->calculate_new_quantity( $op, $current_qty, $quantity );

        // Handle removal (explicit remove op or quantity reduced to 0).
        if ( 'remove' === $op || $new_quantity <= 0 ) {
            return $this->build_remove_action( $cart_item_key, $cart_item, $product_name );
        }

        // Check stock before returning action intent.
        if ( $product->managing_stock() ) {
            $stock_qty = $product->get_stock_quantity();
            if ( $new_quantity > $stock_qty ) {
                return $this->format_outcome(
                    'insufficient_stock',
                    array(
                        'product_id'         => $cart_item['product_id'],
                        'variation_id'       => $cart_item['variation_id'] ?? null,
                        'product_name'       => $product_name,
                        'requested_quantity' => $new_quantity,
                        'available_stock'    => $stock_qty,
                        'current_quantity'   => $current_qty,
                    ),
                    sprintf(
                        __( 'Only %d units of %s are available. You currently have %d in cart.', 'glimmr-ai' ),
                        $stock_qty,
                        $product_name,
                        $current_qty
                    )
                );
            }
        }

        // Return cart_action intent for frontend execution via Store API.
        return $this->format_outcome(
            'cart_action',
            array(
                'action'            => 'update',
                'cart_item_key'     => $cart_item_key,
                'product_id'        => $cart_item['product_id'],
                'variation_id'      => $cart_item['variation_id'] ?? null,
                'quantity'          => $new_quantity,
                'product_name'      => $product_name,
                'op'                => $op,
                'previous_quantity' => $current_qty,
            ),
            $this->build_update_message( $product_name, $op, $current_qty, $new_quantity )
        );
    }

    /**
     * Extract item identification from arguments (with backward compatibility).
     *
     * @param array $arguments Tool arguments.
     * @return array Item array with cart_item_key, product_id, variation_id.
     */
    private function extract_item( $arguments ) {
        // Check for new nested format.
        if ( isset( $arguments['item'] ) && is_array( $arguments['item'] ) ) {
            return array(
                'cart_item_key' => $arguments['item']['cart_item_key'] ?? '',
                'product_id'    => isset( $arguments['item']['product_id'] ) ? (int) $arguments['item']['product_id'] : 0,
                'variation_id'  => isset( $arguments['item']['variation_id'] ) ? (int) $arguments['item']['variation_id'] : 0,
            );
        }

        // Fall back to legacy flat parameters.
        return array(
            'cart_item_key' => $this->get_string_arg( $arguments, 'cart_item_key', '' ),
            'product_id'    => $this->get_int_arg( $arguments, 'product_id', 0 ),
            'variation_id'  => 0, // Legacy format didn't have variation_id.
        );
    }

    /**
     * Extract operation from arguments (with backward compatibility).
     *
     * @param array $arguments Tool arguments.
     * @return string Operation type.
     */
    private function extract_op( $arguments ) {
        // Check for new op parameter.
        if ( isset( $arguments['op'] ) ) {
            return $this->get_string_arg( $arguments, 'op', '' );
        }

        // Legacy action parameter mapping.
        $legacy_action = $this->get_string_arg( $arguments, 'action', '' );
        $quantity = $this->get_int_arg( $arguments, 'quantity', 1 );

        if ( 'add' === $legacy_action ) {
            return 'increment';
        }
        if ( 'remove' === $legacy_action ) {
            return 'decrement';
        }
        // Legacy: quantity=0 meant remove.
        if ( 0 === $quantity ) {
            return 'remove';
        }
        // Default to set_qty.
        return 'set_qty';
    }

    /**
     * Find cart item by various identifiers.
     *
     * @param array $item Item identification array.
     * @return array Result with status and cart_item_key or matches.
     */
    private function find_cart_item( $item ) {
        $cart = WC()->cart;
        $cart_contents = $cart->get_cart();

        // Direct cart_item_key lookup (preferred, unambiguous).
        if ( ! empty( $item['cart_item_key'] ) ) {
            if ( isset( $cart_contents[ $item['cart_item_key'] ] ) ) {
                return array(
                    'status'        => 'found',
                    'cart_item_key' => $item['cart_item_key'],
                );
            }
            return array( 'status' => 'not_found' );
        }

        // Find by product_id (and optionally variation_id).
        $product_id = $item['product_id'];
        $variation_id = $item['variation_id'];
        $matches = array();

        foreach ( $cart_contents as $key => $cart_item ) {
            $item_product_id = $cart_item['product_id'];
            $item_variation_id = $cart_item['variation_id'] ?? 0;

            // Match by product_id.
            if ( $item_product_id === $product_id || $item_variation_id === $product_id ) {
                // If variation_id specified, must match.
                if ( $variation_id > 0 && $item_variation_id !== $variation_id ) {
                    continue;
                }

                $product = $cart_item['data'];
                $matches[] = array(
                    'cart_item_key' => $key,
                    'product_id'    => $item_product_id,
                    'variation_id'  => $item_variation_id,
                    'product_name'  => $product->get_name(),
                    'quantity'      => $cart_item['quantity'],
                    'attributes'    => $this->get_variation_attributes( $cart_item ),
                );
            }
        }

        if ( empty( $matches ) ) {
            return array( 'status' => 'not_found' );
        }

        if ( count( $matches ) === 1 ) {
            return array(
                'status'        => 'found',
                'cart_item_key' => $matches[0]['cart_item_key'],
            );
        }

        // Multiple matches - need disambiguation.
        return array(
            'status'  => 'multiple_matches',
            'matches' => $matches,
        );
    }

    /**
     * Get variation attributes from cart item.
     *
     * @param array $cart_item Cart item data.
     * @return array Variation attributes.
     */
    private function get_variation_attributes( $cart_item ) {
        if ( empty( $cart_item['variation'] ) ) {
            return array();
        }

        $attrs = array();
        foreach ( $cart_item['variation'] as $key => $value ) {
            $clean_key = str_replace( 'attribute_', '', $key );
            $clean_key = str_replace( 'pa_', '', $clean_key );
            if ( is_array( $clean_key ) ) {
                $clean_key = implode( ', ', $clean_key );
            }
            $attrs[ ucfirst( $clean_key ) ] = $value;
        }
        return $attrs;
    }

    /**
     * Calculate new quantity based on operation.
     *
     * @param string $op       Operation type.
     * @param int    $current  Current quantity.
     * @param int    $quantity Quantity value.
     * @return int New quantity.
     */
    private function calculate_new_quantity( $op, $current, $quantity ) {
        switch ( $op ) {
            case 'increment':
                return min( $current + $quantity, 99 );
            case 'decrement':
                return max( 0, $current - $quantity );
            case 'set_qty':
            default:
                return $quantity;
        }
    }

    /**
     * Build cart_action intent for removing an item.
     *
     * Instead of executing removal directly (which causes session sync issues),
     * we return an intent that the frontend will execute via WooCommerce Store API.
     *
     * @param string $cart_item_key Cart item key.
     * @param array  $cart_item     Cart item data.
     * @param string $product_name  Product name.
     * @return array Cart action intent for frontend execution.
     */
    private function build_remove_action( $cart_item_key, $cart_item, $product_name ) {
        return $this->format_outcome(
            'cart_action',
            array(
                'action'        => 'remove',
                'cart_item_key' => $cart_item_key,
                'product_id'    => $cart_item['product_id'],
                'variation_id'  => $cart_item['variation_id'] ?? null,
                'product_name'  => $product_name,
            ),
            sprintf( __( 'Removing %s from your cart...', 'glimmr-ai' ), $product_name )
        );
    }

    /**
     * Build human-readable update message.
     *
     * @param string $product_name Product name.
     * @param string $op           Operation performed.
     * @param int    $previous     Previous quantity.
     * @param int    $new          New quantity.
     * @return string Message.
     */
    private function build_update_message( $product_name, $op, $previous, $new ) {
        if ( $new === $previous ) {
            return sprintf(
                __( '%s quantity is already %d.', 'glimmr-ai' ),
                $product_name,
                $new
            );
        }

        if ( $new > $previous ) {
            return sprintf(
                __( 'Increased %s quantity from %d to %d.', 'glimmr-ai' ),
                $product_name,
                $previous,
                $new
            );
        }

        return sprintf(
            __( 'Decreased %s quantity from %d to %d.', 'glimmr-ai' ),
            $product_name,
            $previous,
            $new
        );
    }

    /**
     * Ensure WooCommerce cart is loaded.
     *
     * @return bool True if cart was loaded successfully, false otherwise.
     */
    private function ensure_cart_loaded() {
        if ( is_null( WC()->cart ) ) {
            wc_load_cart();
        }

        if ( is_null( WC()->session ) ) {
            WC()->session = new WC_Session_Handler();
            WC()->session->init();
        }

        // Verify cart was actually loaded - wc_load_cart() may fail silently.
        if ( is_null( WC()->cart ) ) {
            if ( class_exists( 'Glimmr_AI_Logger' ) ) {
                Glimmr_AI_Logger::warning(
                    'Cart initialization failed in update_cart - WC()->cart is still null after wc_load_cart()',
                    array(
                        'session_exists'  => true,
                        'customer_exists' => ! is_null( WC()->customer ),
                    ),
                    'tools'
                );
            }
            return false;
        }

        return true;
    }

    /**
     * Get current cart summary.
     *
     * @return array Cart summary.
     */
    private function get_cart_summary() {
        $cart = WC()->cart;

        // Handle case where cart is not initialized.
        if ( is_null( $cart ) ) {
            return array(
                'item_count'     => 0,
                'subtotal'       => $this->format_price( 0 ),
                'discount_total' => $this->format_price( 0 ),
                'total'          => $this->format_price( 0 ),
                'cart_url'       => wc_get_cart_url(),
                'checkout_url'   => wc_get_checkout_url(),
                'is_empty'       => true,
            );
        }

        $data = array(
            'item_count'     => $cart->get_cart_contents_count(),
            'subtotal'       => $this->format_price( $cart->get_subtotal() ),
            'discount_total' => $this->format_price( $cart->get_discount_total() ),
            'total'          => $this->format_price( $cart->get_total( 'edit' ) ),
            'cart_url'       => wc_get_cart_url(),
            'checkout_url'   => wc_get_checkout_url(),
        );

        if ( $cart->is_empty() ) {
            $data['is_empty'] = true;
        }

        return $data;
    }
}
