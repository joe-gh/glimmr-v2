<?php
/**
 * Add to Cart Tool
 *
 * Adds products to the customer's cart with structured selection object.
 *
 * @package Glimmr_AI
 * @since 1.0.0
 * @updated 1.1.0 Added selection object, structured outcomes
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Glimmr_AI_Tool_Add_To_Cart
 *
 * Handles adding products and variations to the WooCommerce cart.
 * Supports both legacy flat parameters and new nested selection object.
 */
class Glimmr_AI_Tool_Add_To_Cart extends Glimmr_AI_Tool_Base {

    /**
     * Tool name.
     *
     * @var string
     */
    protected $name = 'add_to_cart';

    /**
     * Tool description.
     *
     * @var string
     */
    protected $description = 'Add a product to the customer\'s shopping cart. For variable products, use the selection object with variation_id or attributes.';

    /**
     * Tool parameters.
     *
     * @var array
     */
    protected $parameters = array(
        'product_id' => array(
            'type'        => 'integer',
            'description' => 'The product ID to add to cart',
            'required'    => true,
        ),
        'quantity' => array(
            'type'        => 'integer',
            'description' => 'Quantity to add (default: 1, max: 99)',
            'minimum'     => 1,
            'maximum'     => 99,
        ),
        'selection' => array(
            'type'        => 'object',
            'description' => 'Required for variable products: specify variation',
            'properties'  => array(
                'variation_id' => array(
                    'type'        => 'integer',
                    'description' => 'Specific variation ID (preferred - use resolve_variation to get this)',
                ),
                'attributes' => array(
                    'type'                 => 'object',
                    'description'          => 'Variation attributes using normalized names without pa_ prefix (e.g., {"size": "large", "color": "blue"})',
                    'additionalProperties' => array( 'type' => 'string' ),
                ),
            ),
        ),
        // Legacy parameters for backward compatibility.
        'variation_id' => array(
            'type'        => 'integer',
            'description' => '[DEPRECATED] Use selection.variation_id instead',
        ),
        'variation' => array(
            'type'                 => 'object',
            'description'          => '[DEPRECATED] Use selection.attributes instead',
            'additionalProperties' => array( 'type' => 'string' ),
        ),
    );

    /**
     * Execute the tool.
     *
     * @param array $arguments Tool arguments.
     * @return array Tool result.
     */
    public function execute( $arguments ) {
        $this->log_debug( 'add_to_cart execute() called', array(
            'arguments' => $arguments,
        ) );

        $wc_check = $this->require_wc();
        if ( $wc_check ) {
            return $wc_check;
        }

        // Log initial session/cart state.
        $this->log_session_state( 'Before processing' );

        $product_id = $this->get_int_arg( $arguments, 'product_id' );
        $quantity   = max( 1, min( 99, $this->get_int_arg( $arguments, 'quantity', 1 ) ) );

        // Extract variation info from selection object or legacy params.
        $selection = $this->extract_selection( $arguments );
        $variation_id = $selection['variation_id'];
        $variation = $selection['attributes'];

        if ( $product_id <= 0 ) {
            return $this->format_validation_error(
                'missing_required',
                'product_id',
                __( 'Required field "product_id" is missing.', 'glimmr-ai' )
            );
        }

        // Get the product.
        $product = wc_get_product( $product_id );

        if ( ! $product ) {
            return $this->format_outcome(
                'not_found',
                array( 'product_id' => $product_id ),
                sprintf( __( 'Product with ID %d not found.', 'glimmr-ai' ), $product_id )
            );
        }

        // Check if product is purchasable.
        if ( ! $product->is_purchasable() ) {
            return $this->format_outcome(
                'not_purchasable',
                array(
                    'product_id'   => $product_id,
                    'product_name' => $product->get_name(),
                ),
                sprintf( __( '"%s" cannot be purchased.', 'glimmr-ai' ), $product->get_name() )
            );
        }

        // Check if simple product incorrectly given selection.
        if ( ! $product->is_type( 'variable' ) && ( $variation_id || ! empty( $variation ) ) ) {
            return $this->format_outcome(
                'invalid_selection',
                array(
                    'product_id'   => $product_id,
                    'product_name' => $product->get_name(),
                    'product_type' => $product->get_type(),
                ),
                sprintf( __( '"%s" is not a variable product. Remove the selection parameter.', 'glimmr-ai' ), $product->get_name() )
            );
        }

        // Handle variable products.
        if ( $product->is_type( 'variable' ) ) {
            return $this->handle_variable_product( $product, $quantity, $variation_id, $variation );
        }

        // Simple product - check stock and add to cart.
        return $this->add_simple_product( $product, $quantity );
    }

    /**
     * Extract selection from arguments (supports new and legacy format).
     *
     * @param array $arguments Tool arguments.
     * @return array Array with 'variation_id' and 'attributes' keys.
     */
    private function extract_selection( $arguments ) {
        // Check for new selection object format.
        $selection = $this->get_nested_arg( $arguments, 'selection', array() );

        if ( ! empty( $selection ) ) {
            return array(
                'variation_id' => $this->get_nested_arg( $arguments, 'selection.variation_id', 0 ),
                'attributes'   => $this->get_nested_arg( $arguments, 'selection.attributes', array() ),
            );
        }

        // Fall back to legacy flat parameters.
        return array(
            'variation_id' => $this->get_int_arg( $arguments, 'variation_id', 0 ),
            'attributes'   => $this->get_array_arg( $arguments, 'variation', array() ),
        );
    }

    /**
     * Handle adding a variable product.
     *
     * @param WC_Product $product      The variable product.
     * @param int        $quantity     Quantity to add.
     * @param int        $variation_id Variation ID (if provided).
     * @param array      $variation    Variation attributes (if provided).
     * @return array Tool result.
     */
    private function handle_variable_product( $product, $quantity, $variation_id, $variation ) {
        // If no selection provided, return needs_variation_selection.
        if ( empty( $variation_id ) && empty( $variation ) ) {
            return $this->build_needs_selection_response( $product );
        }

        // Find variation if not provided directly.
        if ( empty( $variation_id ) ) {
            $data_store = WC_Data_Store::load( 'product' );
            $variation_id = $data_store->find_matching_product_variation( $product, $variation );

            if ( ! $variation_id ) {
                // Invalid combination - return available options.
                return $this->format_outcome(
                    'invalid_combination',
                    array(
                        'product_id'           => $product->get_id(),
                        'product_name'         => $product->get_name(),
                        'requested_attributes' => $variation,
                        'valid_combinations'   => $this->get_valid_combinations( $product, 5 ),
                    ),
                    __( 'This attribute combination is not available. Please choose from the valid combinations.', 'glimmr-ai' )
                );
            }
        }

        // Get variation product.
        $variation_product = wc_get_product( $variation_id );
        if ( ! $variation_product || ! $variation_product->is_purchasable() ) {
            return $this->format_outcome(
                'variation_not_available',
                array(
                    'product_id'   => $product->get_id(),
                    'variation_id' => $variation_id,
                ),
                __( 'This variation is not available.', 'glimmr-ai' )
            );
        }

        // Check stock.
        if ( ! $variation_product->is_in_stock() ) {
            // Find in-stock alternatives.
            $alternatives = $this->find_in_stock_alternatives( $product, $variation_product );

            return $this->format_outcome(
                'out_of_stock',
                array(
                    'product_id'   => $product->get_id(),
                    'variation_id' => $variation_id,
                    'product_name' => $product->get_name() . ' - ' . $this->format_variation_name( $variation_product ),
                    'alternatives' => $alternatives,
                ),
                sprintf(
                    __( '%s is out of stock.%s', 'glimmr-ai' ),
                    $this->format_variation_name( $variation_product ),
                    ! empty( $alternatives ) ? ' ' . __( 'See alternatives.', 'glimmr-ai' ) : ''
                )
            );
        }

        // Check stock quantity.
        if ( $variation_product->managing_stock() ) {
            $stock_qty = $variation_product->get_stock_quantity();
            if ( $stock_qty < $quantity ) {
                return $this->format_outcome(
                    'insufficient_stock',
                    array(
                        'product_id'      => $product->get_id(),
                        'variation_id'    => $variation_id,
                        'requested_qty'   => $quantity,
                        'available_qty'   => $stock_qty,
                    ),
                    sprintf(
                        __( 'Only %d units available. Would you like to add %d instead?', 'glimmr-ai' ),
                        $stock_qty,
                        $stock_qty
                    )
                );
            }
        }

        // Get variation attributes if not already set.
        if ( empty( $variation ) ) {
            $variation = $variation_product->get_variation_attributes();
        }

        // Add to cart.
        return $this->add_to_cart_and_respond( $product, $quantity, $variation_id, $variation );
    }

    /**
     * Add a simple product to cart.
     *
     * @param WC_Product $product  The product.
     * @param int        $quantity Quantity to add.
     * @return array Tool result.
     */
    private function add_simple_product( $product, $quantity ) {
        // Check stock.
        if ( ! $product->is_in_stock() ) {
            return $this->format_outcome(
                'out_of_stock',
                array(
                    'product_id'   => $product->get_id(),
                    'product_name' => $product->get_name(),
                ),
                sprintf( __( '"%s" is currently out of stock.', 'glimmr-ai' ), $product->get_name() )
            );
        }

        // Check stock quantity.
        if ( $product->managing_stock() ) {
            $stock_qty = $product->get_stock_quantity();
            if ( $stock_qty < $quantity ) {
                return $this->format_outcome(
                    'insufficient_stock',
                    array(
                        'product_id'    => $product->get_id(),
                        'product_name'  => $product->get_name(),
                        'requested_qty' => $quantity,
                        'available_qty' => $stock_qty,
                    ),
                    sprintf(
                        __( 'Only %d units of "%s" are available.', 'glimmr-ai' ),
                        $stock_qty,
                        $product->get_name()
                    )
                );
            }
        }

        return $this->add_to_cart_and_respond( $product, $quantity, 0, array() );
    }

    /**
     * Return cart_action intent for frontend execution via Store API.
     *
     * Instead of executing cart operations directly (which causes session sync issues),
     * we return an intent that the frontend will execute via WooCommerce Store API.
     * This ensures the browser's session is used and mini cart stays in sync.
     *
     * @param WC_Product $product      The product.
     * @param int        $quantity     Quantity.
     * @param int        $variation_id Variation ID.
     * @param array      $variation    Variation attributes.
     * @return array Cart action intent for frontend execution.
     */
    private function add_to_cart_and_respond( $product, $quantity, $variation_id, $variation ) {
        $this->log_debug( 'Preparing cart_action intent for frontend execution', array(
            'product_id'   => $product->get_id(),
            'product_name' => $product->get_name(),
            'quantity'     => $quantity,
            'variation_id' => $variation_id,
            'variation'    => $variation,
        ) );

        // Get price from variation or product.
        $price = $product->get_price();
        if ( $variation_id ) {
            $var_product = wc_get_product( $variation_id );
            if ( $var_product ) {
                $price = $var_product->get_price();
            }
        }

        // Get product image URL.
        $image_url = wp_get_attachment_url( $product->get_image_id() );
        if ( ! $image_url ) {
            $image_url = wc_placeholder_img_src();
        }

        // Return cart_action intent - frontend will execute via Store API.
        return $this->format_outcome(
            'cart_action',
            array(
                'action'       => 'add',
                'product_id'   => $product->get_id(),
                'variation_id' => $variation_id ?: null,
                'quantity'     => $quantity,
                'attributes'   => $variation,
                'product_name' => $product->get_name(),
                'price'        => $this->format_price( $price ),
                'line_total'   => $this->format_price( $price * $quantity ),
                'image'        => $image_url,
                // URLs for potential redirects.
                'cart_url'     => wc_get_cart_url(),
                'checkout_url' => wc_get_checkout_url(),
            ),
            sprintf(
                __( 'Adding %d x %s to your cart...', 'glimmr-ai' ),
                $quantity,
                $product->get_name()
            )
        );
    }

    /**
     * Build needs_variation_selection response.
     *
     * @param WC_Product $product Variable product.
     * @return array Response asking for variation selection.
     */
    private function build_needs_selection_response( $product ) {
        $available_attrs = array();
        $missing_attributes = array();

        foreach ( $product->get_variation_attributes() as $attr_name => $options ) {
            $attr_label = wc_attribute_label( $attr_name, $product );
            $available_attrs[ $attr_name ] = array(
                'label'   => $attr_label,
                'options' => array_values( $options ),
            );
            $missing_attributes[] = $attr_name;
        }

        // Get some available variations with prices.
        $variations = array();
        foreach ( array_slice( $product->get_available_variations(), 0, 5 ) as $var ) {
            $var_id = $var['variation_id'] ?? 0;
            if ( ! $var_id ) {
                continue;
            }
            $variation_product = wc_get_product( $var_id );
            if ( $variation_product && $variation_product->is_in_stock() ) {
                $variations[] = array(
                    'variation_id' => $var_id,
                    'attributes'   => $var['attributes'] ?? array(),
                    'price'        => $this->format_price( $variation_product->get_price() ),
                    'in_stock'     => true,
                );
            }
        }

        return $this->format_outcome(
            'needs_variation_selection',
            array(
                'product_id'         => $product->get_id(),
                'product_name'       => $product->get_name(),
                'missing_attributes' => $missing_attributes,
                'available_options'  => $available_attrs,
                'sample_variations'  => $variations,
            ),
            $this->build_selection_message( $available_attrs )
        );
    }

    /**
     * Build selection message from available attributes.
     *
     * @param array $available_attrs Available attributes.
     * @return string Selection message.
     */
    private function build_selection_message( $available_attrs ) {
        $parts = array();
        foreach ( $available_attrs as $attr_name => $attr_data ) {
            $options_str = implode( ', ', array_slice( $attr_data['options'], 0, 5 ) );
            if ( count( $attr_data['options'] ) > 5 ) {
                $options_str .= '...';
            }
            $parts[] = sprintf( '%s (%s)', $attr_data['label'], $options_str );
        }

        return sprintf( __( 'Please select: %s', 'glimmr-ai' ), implode( '; ', $parts ) );
    }

    /**
     * Get valid variation combinations.
     *
     * @param WC_Product $product Variable product.
     * @param int        $limit   Max combinations.
     * @return array Valid combinations.
     */
    private function get_valid_combinations( $product, $limit ) {
        $combinations = array();
        $variations = $product->get_available_variations();

        foreach ( array_slice( $variations, 0, $limit ) as $variation ) {
            $var_id = $variation['variation_id'] ?? 0;
            if ( ! $var_id ) {
                continue;
            }
            $var_product = wc_get_product( $var_id );
            if ( $var_product && $var_product->is_in_stock() ) {
                $combinations[] = array(
                    'variation_id' => $var_id,
                    'attributes'   => $variation['attributes'] ?? array(),
                    'price'        => $this->format_price( $var_product->get_price() ),
                );
            }
        }

        return $combinations;
    }

    /**
     * Find in-stock alternatives for an out-of-stock variation.
     *
     * @param WC_Product           $product           Parent product.
     * @param WC_Product_Variation $current_variation Current variation.
     * @return array Alternative variations.
     */
    private function find_in_stock_alternatives( $product, $current_variation ) {
        $alternatives = array();
        $current_attrs = $current_variation->get_attributes();

        foreach ( $product->get_available_variations() as $variation ) {
            $var_id = $variation['variation_id'] ?? 0;
            if ( ! $var_id || $var_id === $current_variation->get_id() ) {
                continue;
            }

            $var_product = wc_get_product( $var_id );
            if ( ! $var_product || ! $var_product->is_in_stock() ) {
                continue;
            }

            // Check similarity - at least one matching attribute.
            $matches = 0;
            $var_attributes = $variation['attributes'] ?? array();
            foreach ( $current_attrs as $key => $value ) {
                $var_key = 'attribute_' . $key;
                if ( isset( $var_attributes[ $var_key ] ) &&
                    strtolower( $var_attributes[ $var_key ] ) === strtolower( $value ) ) {
                    $matches++;
                }
            }

            if ( $matches > 0 ) {
                $alternatives[] = array(
                    'variation_id' => $var_id,
                    'attributes'   => $this->format_variation_name( $var_product ),
                    'price'        => $this->format_price( $var_product->get_price() ),
                    'in_stock'     => true,
                );

                if ( count( $alternatives ) >= 3 ) {
                    break;
                }
            }
        }

        return $alternatives;
    }

    /**
     * Format variation name from attributes.
     *
     * @param WC_Product_Variation $variation Variation product.
     * @return string Formatted name.
     */
    private function format_variation_name( $variation ) {
        $attrs = $variation->get_attributes();
        $parts = array();

        foreach ( $attrs as $key => $value ) {
            if ( ! empty( $value ) ) {
                $parts[] = ucfirst( $value );
            }
        }

        return implode( ' / ', $parts );
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
                '[add_to_cart] ' . $message,
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
            'is_ajax'             => wp_doing_ajax(),
            'user_logged_in'      => is_user_logged_in(),
            'user_id'             => get_current_user_id(),
        ) );
    }
}
