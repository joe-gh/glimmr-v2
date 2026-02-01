<?php
/**
 * Checkout Link Tool
 *
 * Generates links to checkout or cart pages.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Glimmr_AI_Tool_Checkout_Link
 *
 * Generates checkout and cart links with optional pre-filled data.
 * Supports adding items to cart before generating checkout link.
 */
class Glimmr_AI_Tool_Checkout_Link extends Glimmr_AI_Tool_Base {

    /**
     * Tool name.
     *
     * @var string
     */
    protected $name = 'checkout_link';

    /**
     * Tool description.
     *
     * @var string
     */
    protected $description = 'Generate a link to the checkout page or cart page. Can optionally add items to cart first. Returns URLs that the customer can use to complete their purchase.';

    /**
     * Tool parameters.
     *
     * @var array
     */
    protected $parameters = array(
        'type' => array(
            'type'        => 'string',
            'description' => 'Type of link to generate: checkout, cart, or add_to_cart (direct add link)',
            'required'    => true,
            'enum'        => array( 'checkout', 'cart', 'add_to_cart' ),
        ),
        'auto_navigate' => array(
            'type'        => 'boolean',
            'description' => 'If true, automatically navigate the user to checkout/cart instead of returning a URL. Use when user expresses clear intent like "let\'s checkout" or "take me to cart".',
        ),
        // New nested add_to_cart object (v2 format).
        'add_to_cart' => array(
            'type'        => 'object',
            'description' => 'Optionally add item(s) to cart before generating checkout link',
            'additionalProperties' => false,
            'properties'  => array(
                'product_id' => array(
                    'type'        => 'integer',
                    'description' => 'Product ID to add',
                    'minimum'     => 1,
                ),
                'quantity' => array(
                    'type'        => 'integer',
                    'description' => 'Quantity to add (default: 1)',
                    'minimum'     => 1,
                    'maximum'     => 99,
                ),
                'variation_id' => array(
                    'type'        => 'integer',
                    'description' => 'Variation ID for variable products',
                    'minimum'     => 1,
                ),
                'attributes' => array(
                    'type'                 => 'object',
                    'description'          => 'Variation attributes if variation_id not provided',
                    'additionalProperties' => array( 'type' => 'string' ),
                ),
            ),
        ),
        // Legacy flat parameters (backward compatibility).
        'product_id' => array(
            'type'        => 'integer',
            'description' => 'DEPRECATED: Use add_to_cart.product_id instead. Will be removed in v2.0.',
            'minimum'     => 1,
        ),
        'quantity' => array(
            'type'        => 'integer',
            'description' => 'DEPRECATED: Use add_to_cart.quantity instead. Will be removed in v2.0.',
            'minimum'     => 1,
            'maximum'     => 99,
        ),
        'variation_id' => array(
            'type'        => 'integer',
            'description' => 'DEPRECATED: Use add_to_cart.variation_id instead. Will be removed in v2.0.',
            'minimum'     => 1,
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

        $type = $this->get_string_arg( $arguments, 'type', 'checkout' );

        // Extract add_to_cart data from new or legacy format.
        $add_to_cart_data = $this->extract_add_to_cart_data( $arguments );

        // If type is add_to_cart, generate direct add link.
        if ( 'add_to_cart' === $type ) {
            if ( empty( $add_to_cart_data['product_id'] ) ) {
                return $this->format_validation_error(
                    'missing_required',
                    'add_to_cart.product_id',
                    __( 'Product ID is required for add_to_cart link type.', 'glimmr-ai' )
                );
            }
            return $this->generate_add_to_cart_link( $add_to_cart_data );
        }

        // Ensure cart is loaded for cart/checkout links.
        $this->ensure_cart_loaded();

        // If add_to_cart data provided with checkout/cart type, return cart_action intent.
        // Frontend will execute via Store API then redirect.
        if ( ! empty( $add_to_cart_data['product_id'] ) && 'add_to_cart' !== $type ) {
            // First validate the product can be added.
            $validation = $this->validate_add_to_cart( $add_to_cart_data );
            if ( ! $validation['valid'] ) {
                return $validation['result'];
            }

            // Return cart_action intent for frontend execution.
            return $this->format_outcome(
                'cart_action',
                array(
                    'action'       => 'add_then_redirect',
                    'product_id'   => $add_to_cart_data['product_id'],
                    'variation_id' => $add_to_cart_data['variation_id'] ?: null,
                    'quantity'     => $add_to_cart_data['quantity'],
                    'attributes'   => $add_to_cart_data['attributes'] ?? array(),
                    'redirect_to'  => 'checkout' === $type ? 'checkout' : 'cart',
                    'checkout_url' => wc_get_checkout_url(),
                    'cart_url'     => wc_get_cart_url(),
                    'product_name' => $validation['product_name'],
                    'price'        => $validation['price'],
                ),
                sprintf(
                    __( 'Adding %d x %s to cart and preparing %s...', 'glimmr-ai' ),
                    $add_to_cart_data['quantity'],
                    $validation['product_name'],
                    'checkout' === $type ? 'checkout' : 'cart'
                )
            );
        }

        switch ( $type ) {
            case 'cart':
                return $this->generate_cart_link( $arguments );

            case 'checkout':
            default:
                return $this->generate_checkout_link( $arguments );
        }
    }

    /**
     * Extract add_to_cart data from arguments (supports both v1 and v2 format).
     *
     * @param array $arguments Tool arguments.
     * @return array Normalized add_to_cart data.
     */
    private function extract_add_to_cart_data( $arguments ) {
        // New v2 format: nested add_to_cart object.
        if ( isset( $arguments['add_to_cart'] ) && is_array( $arguments['add_to_cart'] ) ) {
            return array(
                'product_id'   => isset( $arguments['add_to_cart']['product_id'] ) ? (int) $arguments['add_to_cart']['product_id'] : 0,
                'quantity'     => isset( $arguments['add_to_cart']['quantity'] ) ? max( 1, (int) $arguments['add_to_cart']['quantity'] ) : 1,
                'variation_id' => isset( $arguments['add_to_cart']['variation_id'] ) ? (int) $arguments['add_to_cart']['variation_id'] : 0,
                'attributes'   => isset( $arguments['add_to_cart']['attributes'] ) ? $arguments['add_to_cart']['attributes'] : array(),
            );
        }

        // Legacy v1 format: flat parameters.
        return array(
            'product_id'   => $this->get_int_arg( $arguments, 'product_id', 0 ),
            'quantity'     => max( 1, $this->get_int_arg( $arguments, 'quantity', 1 ) ),
            'variation_id' => $this->get_int_arg( $arguments, 'variation_id', 0 ),
            'attributes'   => array(),
        );
    }

    /**
     * Validate add to cart data without executing.
     *
     * Used to validate before returning cart_action intent.
     *
     * @param array $data Add to cart data.
     * @return array Array with 'valid' boolean and either 'result' (error) or product info.
     */
    private function validate_add_to_cart( $data ) {
        $product_id   = $data['product_id'];
        $quantity     = $data['quantity'];
        $variation_id = $data['variation_id'];
        $attributes   = $data['attributes'] ?? array();

        $product = wc_get_product( $product_id );

        if ( ! $product ) {
            return array(
                'valid'  => false,
                'result' => $this->format_outcome(
                    'product_not_found',
                    array( 'product_id' => $product_id ),
                    __( 'Product not found.', 'glimmr-ai' )
                ),
            );
        }

        if ( ! $product->is_purchasable() ) {
            return array(
                'valid'  => false,
                'result' => $this->format_outcome(
                    'not_purchasable',
                    array( 'product_id' => $product_id, 'product_name' => $product->get_name() ),
                    __( 'This product cannot be purchased.', 'glimmr-ai' )
                ),
            );
        }

        // Handle variable products.
        if ( $product->is_type( 'variable' ) ) {
            // Try to resolve variation if not provided.
            if ( empty( $variation_id ) && ! empty( $attributes ) ) {
                $data_store   = WC_Data_Store::load( 'product' );
                $variation_id = $data_store->find_matching_product_variation( $product, $attributes );
            }

            if ( empty( $variation_id ) ) {
                $available_variations = $product->get_available_variations();
                $variation_attributes = $product->get_variation_attributes();

                return array(
                    'valid'  => false,
                    'result' => $this->format_outcome(
                        'needs_variation_selection',
                        array(
                            'product_id'           => $product_id,
                            'product_name'         => $product->get_name(),
                            'variation_attributes' => $variation_attributes,
                            'available_variations' => array_slice( $available_variations, 0, 10 ),
                        ),
                        sprintf( __( '%s requires variation selection.', 'glimmr-ai' ), $product->get_name() )
                    ),
                );
            }

            // Validate variation.
            $variation = wc_get_product( $variation_id );
            if ( ! $variation || ! $variation->is_type( 'variation' ) || $variation->get_parent_id() !== $product_id ) {
                return array(
                    'valid'  => false,
                    'result' => $this->format_outcome(
                        'invalid_variation',
                        array(
                            'product_id'   => $product_id,
                            'variation_id' => $variation_id,
                        ),
                        __( 'Invalid variation for this product.', 'glimmr-ai' )
                    ),
                );
            }
        }

        // Check stock.
        $check_product = $variation_id ? wc_get_product( $variation_id ) : $product;
        if ( ! $check_product ) {
            return array(
                'valid'  => false,
                'result' => $this->format_outcome(
                    'product_not_found',
                    array(
                        'product_id'   => $product_id,
                        'variation_id' => $variation_id,
                    ),
                    __( 'Could not load product for stock check.', 'glimmr-ai' )
                ),
            );
        }
        if ( ! $check_product->is_in_stock() ) {
            return array(
                'valid'  => false,
                'result' => $this->format_outcome(
                    'out_of_stock',
                    array(
                        'product_id'   => $product_id,
                        'variation_id' => $variation_id,
                        'product_name' => $check_product->get_name(),
                    ),
                    sprintf( __( '%s is out of stock.', 'glimmr-ai' ), $check_product->get_name() )
                ),
            );
        }

        // Validation passed - use already loaded check_product instead of re-fetching.
        $price = $check_product->get_price();

        return array(
            'valid'        => true,
            'product_name' => $check_product->get_name(),
            'price'        => $this->format_price( $price ),
            'variation_id' => $variation_id,
        );
    }

    /**
     * Add item to cart before generating link.
     *
     * @deprecated 1.1.0 Use cart_action pattern instead for session sync.
     * @param array $data Add to cart data.
     * @return array Result.
     */
    private function add_item_to_cart( $data ) {
        $product_id   = $data['product_id'];
        $quantity     = $data['quantity'];
        $variation_id = $data['variation_id'];
        $attributes   = $data['attributes'];

        $product = wc_get_product( $product_id );

        if ( ! $product ) {
            return $this->format_outcome(
                'product_not_found',
                array( 'product_id' => $product_id ),
                __( 'Product not found.', 'glimmr-ai' )
            );
        }

        if ( ! $product->is_purchasable() ) {
            return $this->format_outcome(
                'not_purchasable',
                array( 'product_id' => $product_id, 'product_name' => $product->get_name() ),
                __( 'This product cannot be purchased.', 'glimmr-ai' )
            );
        }

        // Handle variable products.
        if ( $product->is_type( 'variable' ) ) {
            // Try to resolve variation if not provided.
            if ( empty( $variation_id ) && ! empty( $attributes ) ) {
                $data_store    = WC_Data_Store::load( 'product' );
                $variation_id  = $data_store->find_matching_product_variation( $product, $attributes );
            }

            if ( empty( $variation_id ) ) {
                // Get available variations for user selection.
                $available_variations = $product->get_available_variations();
                $variation_attributes = $product->get_variation_attributes();

                return $this->format_outcome(
                    'needs_variation_selection',
                    array(
                        'product_id'             => $product_id,
                        'product_name'           => $product->get_name(),
                        'variation_attributes'   => $variation_attributes,
                        'available_variations'   => array_slice( $available_variations, 0, 10 ),
                    ),
                    sprintf( __( '%s requires variation selection.', 'glimmr-ai' ), $product->get_name() )
                );
            }

            // Validate variation.
            $variation = wc_get_product( $variation_id );
            if ( ! $variation || ! $variation->is_type( 'variation' ) || $variation->get_parent_id() !== $product_id ) {
                return $this->format_outcome(
                    'invalid_variation',
                    array(
                        'product_id'   => $product_id,
                        'variation_id' => $variation_id,
                    ),
                    __( 'Invalid variation for this product.', 'glimmr-ai' )
                );
            }

            // Get variation attributes for cart.
            $attributes = $variation->get_variation_attributes();
        }

        // Check stock.
        $check_product = $variation_id ? wc_get_product( $variation_id ) : $product;
        if ( ! $check_product ) {
            return $this->format_outcome(
                'product_not_found',
                array(
                    'product_id'   => $product_id,
                    'variation_id' => $variation_id,
                ),
                __( 'Could not load product for stock check.', 'glimmr-ai' )
            );
        }
        if ( ! $check_product->is_in_stock() ) {
            return $this->format_outcome(
                'out_of_stock',
                array(
                    'product_id'   => $product_id,
                    'variation_id' => $variation_id,
                    'product_name' => $check_product->get_name(),
                ),
                sprintf( __( '%s is out of stock.', 'glimmr-ai' ), $check_product->get_name() )
            );
        }

        // Add to cart.
        $cart = WC()->cart;
        $cart_item_key = $cart->add_to_cart( $product_id, $quantity, $variation_id, $attributes );

        if ( ! $cart_item_key ) {
            $notices = wc_get_notices( 'error' );
            wc_clear_notices();
            $error_message = ! empty( $notices ) ? wp_strip_all_tags( $notices[0]['notice'] ?? $notices[0] ) : __( 'Could not add to cart.', 'glimmr-ai' );
            return $this->format_outcome(
                'add_failed',
                array(
                    'product_id'   => $product_id,
                    'variation_id' => $variation_id,
                ),
                $error_message
            );
        }

        wc_clear_notices();

        return array(
            'success'        => true,
            'cart_item_key'  => $cart_item_key,
            'product_id'     => $product_id,
            'variation_id'   => $variation_id,
            'quantity'       => $quantity,
        );
    }

    /**
     * Generate checkout link.
     *
     * @param array $arguments Tool arguments (optional).
     * @return array Result.
     */
    private function generate_checkout_link( $arguments = array() ) {
        $cart = WC()->cart;

        if ( $cart->is_empty() ) {
            return $this->format_outcome(
                'cart_empty',
                array(
                    'checkout_url' => wc_get_checkout_url(),
                    'cart_url'     => wc_get_cart_url(),
                ),
                __( 'Your cart is empty. Add items before checking out.', 'glimmr-ai' )
            );
        }

        // Calculate totals.
        $cart->calculate_totals();

        // Check if auto_navigate is requested - triggers frontend redirect.
        if ( ! empty( $arguments['auto_navigate'] ) ) {
            return $this->format_outcome(
                'cart_action',
                array(
                    'action'       => 'navigate',
                    'redirect_to'  => 'checkout',
                    'checkout_url' => wc_get_checkout_url(),
                    'cart_summary' => array(
                        'item_count' => $cart->get_cart_contents_count(),
                        'total'      => $this->format_price( $cart->get_total( 'edit' ) ),
                    ),
                ),
                sprintf(
                    /* translators: 1: cart total, 2: item count */
                    __( 'Taking you to checkout (%1$s, %2$d items)...', 'glimmr-ai' ),
                    $this->format_price( $cart->get_total( 'edit' ) ),
                    $cart->get_cart_contents_count()
                )
            );
        }

        return $this->format_outcome(
            'ready',
            array(
                'checkout_url'   => wc_get_checkout_url(),
                'cart_url'       => wc_get_cart_url(),
                'item_count'     => $cart->get_cart_contents_count(),
                'subtotal'       => $this->format_price( $cart->get_subtotal() ),
                'discount_total' => $this->format_price( $cart->get_discount_total() ),
                'total'          => $this->format_price( $cart->get_total( 'edit' ) ),
                'needs_shipping' => $cart->needs_shipping(),
                'needs_payment'  => $cart->needs_payment(),
                'applied_coupons'=> $cart->get_applied_coupons(),
            ),
            sprintf(
                __( 'Ready to checkout! Your cart total is %s.', 'glimmr-ai' ),
                $this->format_price( $cart->get_total( 'edit' ) )
            )
        );
    }

    /**
     * Generate cart link.
     *
     * @param array $arguments Tool arguments (optional).
     * @return array Result.
     */
    private function generate_cart_link( $arguments = array() ) {
        $cart = WC()->cart;

        if ( $cart->is_empty() ) {
            return $this->format_outcome(
                'cart_empty',
                array(
                    'cart_url' => wc_get_cart_url(),
                ),
                __( 'Your cart is empty.', 'glimmr-ai' )
            );
        }

        $cart->calculate_totals();

        // Check if auto_navigate is requested - triggers frontend redirect.
        if ( ! empty( $arguments['auto_navigate'] ) ) {
            return $this->format_outcome(
                'cart_action',
                array(
                    'action'       => 'navigate',
                    'redirect_to'  => 'cart',
                    'cart_url'     => wc_get_cart_url(),
                    'cart_summary' => array(
                        'item_count' => $cart->get_cart_contents_count(),
                        'total'      => $this->format_price( $cart->get_total( 'edit' ) ),
                    ),
                ),
                sprintf(
                    /* translators: %d: item count */
                    _n(
                        'Taking you to your cart (%d item)...',
                        'Taking you to your cart (%d items)...',
                        $cart->get_cart_contents_count(),
                        'glimmr-ai'
                    ),
                    $cart->get_cart_contents_count()
                )
            );
        }

        return $this->format_outcome(
            'ready',
            array(
                'cart_url'       => wc_get_cart_url(),
                'checkout_url'   => wc_get_checkout_url(),
                'item_count'     => $cart->get_cart_contents_count(),
                'subtotal'       => $this->format_price( $cart->get_subtotal() ),
                'discount_total' => $this->format_price( $cart->get_discount_total() ),
                'total'          => $this->format_price( $cart->get_total( 'edit' ) ),
            ),
            sprintf(
                _n( 'View your cart with %d item.', 'View your cart with %d items.', $cart->get_cart_contents_count(), 'glimmr-ai' ),
                $cart->get_cart_contents_count()
            )
        );
    }

    /**
     * Generate add-to-cart link.
     *
     * @param array $data Add to cart data with product_id, quantity, variation_id, attributes.
     * @return array Result.
     */
    private function generate_add_to_cart_link( $data ) {
        $product_id   = $data['product_id'];
        $quantity     = $data['quantity'];
        $variation_id = $data['variation_id'];

        $product = wc_get_product( $product_id );

        if ( ! $product ) {
            return $this->format_outcome(
                'product_not_found',
                array( 'product_id' => $product_id ),
                __( 'Product not found.', 'glimmr-ai' )
            );
        }

        if ( ! $product->is_purchasable() ) {
            return $this->format_outcome(
                'not_purchasable',
                array( 'product_id' => $product_id, 'product_name' => $product->get_name() ),
                __( 'This product cannot be purchased.', 'glimmr-ai' )
            );
        }

        // Build add to cart URL.
        $url_args = array(
            'add-to-cart' => $product_id,
            'quantity'    => $quantity,
        );

        if ( $variation_id > 0 ) {
            $url_args['variation_id'] = $variation_id;

            // Get variation attributes.
            $variation = wc_get_product( $variation_id );
            if ( $variation && $variation->is_type( 'variation' ) ) {
                foreach ( $variation->get_variation_attributes() as $attr_key => $attr_value ) {
                    $url_args[ $attr_key ] = $attr_value;
                }
            }
        }

        // Check if product is variable and needs variation selection.
        if ( $product->is_type( 'variable' ) && empty( $variation_id ) ) {
            // Get available variations for user selection.
            $available_variations = $product->get_available_variations();
            $variation_attributes = $product->get_variation_attributes();

            return $this->format_outcome(
                'needs_variation_selection',
                array(
                    'product_url'            => $product->get_permalink(),
                    'product_id'             => $product_id,
                    'product_name'           => $product->get_name(),
                    'is_variable'            => true,
                    'variation_attributes'   => $variation_attributes,
                    'available_variations'   => array_slice( $available_variations, 0, 10 ),
                ),
                sprintf( __( '%s requires variation selection. Please specify size, color, or other options.', 'glimmr-ai' ), $product->get_name() )
            );
        }

        // Check stock.
        $check_product = $variation_id ? wc_get_product( $variation_id ) : $product;
        if ( ! $check_product ) {
            return $this->format_outcome(
                'product_not_found',
                array(
                    'product_id'   => $product_id,
                    'variation_id' => $variation_id,
                ),
                __( 'Could not load product for stock check.', 'glimmr-ai' )
            );
        }
        if ( ! $check_product->is_in_stock() ) {
            return $this->format_outcome(
                'out_of_stock',
                array(
                    'product_id'   => $product_id,
                    'variation_id' => $variation_id,
                    'product_name' => $check_product->get_name(),
                ),
                sprintf( __( '%s is out of stock.', 'glimmr-ai' ), $check_product->get_name() )
            );
        }

        $add_to_cart_url = add_query_arg( $url_args, wc_get_cart_url() );
        // Use already loaded check_product instead of re-fetching.
        $price           = $check_product->get_price();

        return $this->format_outcome(
            'link_generated',
            array(
                'add_to_cart_url' => $add_to_cart_url,
                'product_id'      => $product_id,
                'variation_id'    => $variation_id ?: null,
                'product_name'    => $check_product->get_name(),
                'quantity'        => $quantity,
                'price'           => $this->format_price( $price ),
                'line_total'      => $this->format_price( $price * $quantity ),
            ),
            sprintf(
                __( 'Click to add %d x %s to your cart.', 'glimmr-ai' ),
                $quantity,
                $check_product->get_name()
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
}
